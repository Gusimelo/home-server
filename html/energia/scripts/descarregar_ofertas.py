import requests
import os
import json
import zipfile
import io
import pandas as pd
import warnings
import glob
import argparse
import sys

# --- Configuração Automática ---
URL_SETTINGS_JSON = "https://simuladorprecos.erse.pt/config/Settings.json"
NOME_FICHIRO_METADATA = "metadata_eletricidade.json"

# Nomes exatos dos ficheiros (com a barra invertida)
NOME_FICHEIRO_PRECOS = 'csv\Precos_ELEGN.csv'
NOME_FICHEIRO_CONDICOES = 'csv\CondComerciais.csv'

# --- Parte 1: Encontrar, Descarregar e Extrair os Ficheiros ---

def encontrar_url_zip_via_api(verbose=True):
    """Encontra o URL do .zip mais recente lendo o ficheiro de configuração JSON."""
    if verbose: print(f"1. A obter o link do ficheiro a partir de: {URL_SETTINGS_JSON}")
    try:
        resposta = requests.get(URL_SETTINGS_JSON, timeout=15)
        resposta.raise_for_status()
        dados_json = resposta.json()
        url_completo = dados_json.get("csvPath")
        if url_completo:
            if verbose: print(f"   ✓ Link encontrado: {url_completo}")
            return url_completo
        else:
            if verbose: print("   ERRO: A chave 'csvPath' não foi encontrada no ficheiro Settings.json.")
            return None
    except Exception as e:
        if verbose: print(f"   ERRO ao obter o link do ficheiro: {e}")
        return None

def descarregar_e_extrair_se_necessario(verbose=True):
    """Verifica se existe uma nova versão do ficheiro, descarrega e extrai o conteúdo."""
    url_zip_atual = encontrar_url_zip_via_api(verbose)
    if not url_zip_atual: return False
    try:
        metadados_locais = {}
        if os.path.exists(NOME_FICHIRO_METADATA):
            try:
                with open(NOME_FICHIRO_METADATA, 'r') as f: metadados_locais = json.load(f)
            except json.JSONDecodeError: pass
        url_local = metadados_locais.get('url')
        
        ficheiro_local_existe = os.path.exists(NOME_FICHEIRO_PRECOS)
        
        if url_local != url_zip_atual or not ficheiro_local_existe:
            if verbose: print(f"2. Link novo encontrado (ou ficheiros locais inexistentes). A descarregar de {url_zip_atual}...")
            
            for f in glob.glob("csv*"):
                try:
                    os.remove(f)
                    if verbose: print(f"   ✓ Ficheiro antigo '{f}' removido.")
                except OSError as e:
                    if verbose: print(f"   Aviso: Não foi possível remover o ficheiro antigo {f}: {e}")

            resposta_get = requests.get(url_zip_atual, timeout=30)
            resposta_get.raise_for_status()
            with zipfile.ZipFile(io.BytesIO(resposta_get.content)) as zf:
                zf.extractall()
                if verbose: print(f"   ✓ Ficheiros extraídos com sucesso: {', '.join(zf.namelist())}")
                
            metadata_novo = {'url': url_zip_atual}
            with open(NOME_FICHIRO_METADATA, 'w') as f: json.dump(metadata_novo, f)
            if verbose: print("   ✓ Metadados da nova versão guardados.")
        else:
            if verbose: print("2. Os ficheiros locais já estão atualizados.")
        return True
    except Exception as e:
        if verbose: print(f"   ERRO ao tentar descarregar/extrair: {e}")
        return False

# --- Parte 2: Carregar e Analisar os Dados ---

def carregar_e_preparar_dados(verbose=True):
    """Carrega e prepara TODOS os dados, sem aplicar filtros."""
    if verbose: print("3. A carregar e preparar os dados dos ficheiros CSV...")
    try:
        warnings.filterwarnings('ignore', category=UserWarning, module='pandas')
        
        precos_df = pd.read_csv(NOME_FICHEIRO_PRECOS, sep=';', decimal=',', dtype={'Contagem': str}, on_bad_lines='skip', encoding='utf-8')
        condicoes_df = pd.read_csv(NOME_FICHEIRO_CONDICOES, sep=';', decimal=',', on_bad_lines='skip', encoding='utf-8')
        
        precos_df.rename(columns={'Pot_Cont': 'PotenciaContratada', 'COD_Proposta': 'CodProposta', 'TF': 'TermoFixoDiario', 'TV|TVFV|TVP': 'PrecoForaVazio', 'TVV|TVC': 'PrecoVazio'}, inplace=True)
        numeric_cols = ['PotenciaContratada', 'TermoFixoDiario', 'PrecoForaVazio', 'PrecoVazio']
        for col in numeric_cols:
            precos_df[col] = pd.to_numeric(precos_df[col], errors='coerce')

        condicoes_df.rename(columns={'COM': 'Comercializador', 'COD_Proposta': 'CodProposta', 'NomeProposta': 'NomeProposta'}, inplace=True)
        
        df_merged = pd.merge(precos_df, condicoes_df, on='CodProposta')
        
        if verbose: print("   ✓ Dados carregados e combinados com sucesso.")
        return df_merged
    except FileNotFoundError as e:
        if verbose: print(f"   ERRO CRÍTICO: O ficheiro '{e.filename}' não foi encontrado.")
        return None

def calcular_melhores_ofertas(df_dados, consumo_fv_kwh, consumo_v_kwh=0, potencia=5.75, segmentos=None, fornecimento='ELE'):
    """Calcula e ordena as melhores ofertas com filtros variáveis."""
    if df_dados is None: return pd.DataFrame() 
    
    ofertas_filtradas = df_dados.copy()
    
    if segmentos:
        ofertas_filtradas = ofertas_filtradas[ofertas_filtradas['Segmento'].isin(segmentos)]
    if fornecimento:
        ofertas_filtradas = ofertas_filtradas[ofertas_filtradas['Fornecimento'] == fornecimento]
    if potencia:
        ofertas_filtradas = ofertas_filtradas[ofertas_filtradas['PotenciaContratada'] == potencia]

    if ofertas_filtradas.empty: return pd.DataFrame() 
    
    is_bi_horario = consumo_v_kwh > 0
    if is_bi_horario:
        ofertas_tarifas = ofertas_filtradas[ofertas_filtradas['Contagem'] == '2'].copy()
        custo_consumo = (ofertas_tarifas['PrecoForaVazio'] * consumo_fv_kwh) + (ofertas_tarifas['PrecoVazio'] * consumo_v_kwh)
        ofertas_tarifas['TipoTarifa'] = 'Bi-Horario'
    else:
        ofertas_tarifas = ofertas_filtradas[ofertas_filtradas['Contagem'] == '1'].copy()
        custo_consumo = ofertas_tarifas['PrecoForaVazio'] * consumo_fv_kwh
        ofertas_tarifas['TipoTarifa'] = 'Simples'
        
    custo_termo_fixo = ofertas_tarifas['TermoFixoDiario'] * 365
    ofertas_tarifas['CustoAnualEstimado'] = custo_termo_fixo + custo_consumo

    # Identificar tarifas indexadas
    ofertas_tarifas['TipoPreco'] = 'Fixo'
    ofertas_tarifas.loc[ofertas_tarifas['FiltroPrecosIndex'] == 'S', 'TipoPreco'] = 'Indexado'

    # Condição especial para tarifas Goldenergy ACP
    custo_mensal_acp = 4.65
    condicao_acp = (ofertas_tarifas['Comercializador'].str.contains('gold', case=False, na=False)) & \
                   (ofertas_tarifas['NomeProposta'].str.contains('acp', case=False, na=False))
    ofertas_tarifas.loc[condicao_acp, 'CustoAnualEstimado'] += custo_mensal_acp * 12
    
    ofertas_finais = ofertas_tarifas.dropna(subset=['CustoAnualEstimado'])
    ofertas_finais = ofertas_finais[ofertas_finais['CustoAnualEstimado'] > 0].sort_values('CustoAnualEstimado')

    # Assegurar que as colunas de link existem, mesmo que vazias
    if 'LinkOfertaCom' not in ofertas_finais.columns:
        ofertas_finais['LinkOfertaCom'] = ''
    if 'LinkFichaPadrao' not in ofertas_finais.columns:
        ofertas_finais['LinkFichaPadrao'] = ''

    return ofertas_finais[['Comercializador', 'NomeProposta', 'CustoAnualEstimado', 'TermoFixoDiario', 'PrecoForaVazio', 'PrecoVazio', 'TipoTarifa', 'TipoPreco', 'LinkOfertaCom', 'LinkFichaPadrao']]

# --- Ponto de Entrada Principal ---
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Calculadora de custos de eletricidade baseada nos dados da ERSE.')
    parser.add_argument('--potencia', type=float, required=True, help='Potência contratada em kVA (ex: 5.75)')
    parser.add_argument('--consumo-fora-vazio', type=float, required=True, help='Consumo mensal em kWh fora de vazio.')
    parser.add_argument('--consumo-vazio', type=float, default=0, help='Consumo mensal em kWh em vazio (para bi-horário).')
    parser.add_argument('--segmentos', nargs='+', help='Segmento de mercado (ex: Dom, Neg, Tod)')
    parser.add_argument('--quiet', action='store_true', help='Suprime os logs de progresso, mostrando apenas o JSON final.')

    args = parser.parse_args()
    
    verbose = not args.quiet

    if descarregar_e_extrair_se_necessario(verbose=verbose):
        dados_ofertas = carregar_e_preparar_dados(verbose=verbose)
        if dados_ofertas is not None:
            # Converter consumo mensal para anual
            consumo_anual_fv = args.consumo_fora_vazio * 12
            consumo_anual_v = args.consumo_vazio * 12

            resultado = calcular_melhores_ofertas(
                df_dados=dados_ofertas,
                potencia=args.potencia,
                consumo_fv_kwh=consumo_anual_fv,
                consumo_v_kwh=consumo_anual_v,
                segmentos=args.segmentos
            )
            
            # Output do resultado em JSON
            json_output = resultado.to_json(orient='records', indent=4)
            sys.stdout.write(json_output)
            if verbose:
                print("\n\n--- TOP OFERTAS (JSON) ---", file=sys.stderr)
                print("O resultado em JSON foi enviado para o standard output.", file=sys.stderr)

        else:
            if verbose: print("\nERRO: Não foi possível carregar os dados das ofertas.", file=sys.stderr)
            sys.exit(1)
    else:
        if verbose: print("\nERRO: Não foi possível descarregar ou extrair os dados da ERSE.", file=sys.stderr)
        sys.exit(1)
