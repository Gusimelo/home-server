# Manual da Aplicação de Gestão de Energia

**Versão:** 1.0
**Data:** 2024-10-26

---

## 1. Introdução

A aplicação "Energia" é um sistema de *Business Intelligence* pessoal, concebido para monitorizar, analisar e otimizar os custos com eletricidade em Portugal. A sua principal função é permitir que um utilizador, com base nos seus dados de consumo horário, possa:

1.  **Calcular o custo exato** da sua fatura de eletricidade com a tarifa atualmente contratada.
2.  **Simular e comparar** esse custo com outras tarifas fixas e, mais importante, com uma **tarifa dinâmica** indexada ao mercado ibérico (OMIE).
3.  **Projetar os custos** para o resto do ciclo de faturação, fornecendo uma estimativa do valor final da fatura.

Para tal, a aplicação integra dados de múltiplas fontes: o consumo do utilizador, os preços do mercado grossista (OMIE), os custos regulados (ERSE) e as ofertas comerciais dos fornecedores.

---

## 2. Arquitetura e Componentes

A aplicação é composta por um conjunto de scripts PHP e Python que trabalham em conjunto com uma base de dados MySQL.

*   **Base de Dados (MySQL):** O núcleo do sistema, onde são armazenados:
    *   `leituras_energia`: Consumos horários do utilizador.
    *   `tarifas`: Detalhes das tarifas a serem analisadas (a contratada e as de comparação).
    *   `precos_dinamicos`: Preços horários finais, já calculados, para a tarifa dinâmica.
    *   `perdas_erse_*`: Tabelas anuais com os fatores de perda da rede elétrica.
    *   `tarifas_acesso_redes`: Custos regulados das Tarifas de Acesso às Redes.

*   **Scripts de Coleta de Dados (Python):** Responsáveis por obter dados externos essenciais.
    *   `scripts/omie.py`: Descarrega os preços horários do mercado diário do OMIE.
    *   `scripts/descarregar_ofertas.py`: Descarrega e analisa as ofertas comerciais publicadas pela ERSE.

*   **Scripts de Processamento (PHP):** Contêm a lógica de negócio da aplicação.
    *   `coletor_precos_dinamicos.php`: Orquestra a recolha e o cálculo do preço final da tarifa dinâmica. **É o script mais crítico para o funcionamento da tarifa indexada.**
    *   `calculos.php`: Uma biblioteca de funções que realiza todos os cálculos de custos, projeções e lógica de faturação.
    *   `config.php`: Ficheiro de configuração central com credenciais da base de dados, constantes de faturação (IVA, taxas) e outros parâmetros.

*   **Interface (Não incluída nos ficheiros, mas inferida):** Uma ou mais páginas web (ex: `index.php`, `dashboard.php`) que utilizam os scripts PHP para apresentar os dados e os resultados dos cálculos ao utilizador.

---

## 3. Fluxo de Funcionamento e Lógica

O funcionamento da aplicação pode ser dividido em três fases principais:

### Fase 1: Coleta de Dados (Automatizada)

1.  **Consumo do Utilizador:** Um sistema externo (provavelmente o Home Assistant, como sugerido em `config.php`) recolhe os dados de consumo horário do contador inteligente e insere-os na tabela `leituras_energia`.

2.  **Preços de Mercado (OMIE):** Diariamente, por volta das 13:00, um `cron job` executa o script `coletor_precos_dinamicos.php`. A primeira ação deste script é chamar o `scripts/omie.py` para descarregar os preços de mercado para o dia seguinte.

### Fase 2: Processamento e Cálculo do Preço Dinâmico (Automatizada)

Esta é a fase mais complexa, executada pelo `coletor_precos_dinamicos.php` logo após a coleta dos preços OMIE.

1.  O script lê o ficheiro CSV com os 24 preços horários do OMIE (em ?/MWh).
2.  Para cada hora, consulta a base de dados para obter os **custos regulados** correspondentes:
    *   **Fator de Perda (FP):** Obtido da tabela `perdas_erse_*`. Este fator representa a energia que se perde na rede de transporte e distribuição.
    *   **Tarifa de Acesso às Redes (TAR):** Obtida da tabela `tarifas_acesso_redes`. Este valor (em ?/kWh) depende do período horário (Ponta, Cheia, Vazio).
3.  Aplica a fórmula de cálculo do preço final para o consumidor.
4.  O resultado são 24 preços finais em ?/kWh, que são guardados na tabela `precos_dinamicos` para serem usados nos cálculos da fatura.

### Fase 3: Análise e Apresentação (Interativa)

Quando o utilizador acede à interface da aplicação:

1.  O sistema (usando as funções de `calculos.php`) lê o ciclo de faturação atual (ex: dia 16 a 15 do mês seguinte).
2.  Busca todos os consumos horários (`leituras_energia`) e os preços aplicáveis (fixos da tabela `tarifas` ou dinâmicos da tabela `precos_dinamicos`) para o período.
3.  **Cálculo do Custo Atual:** Para cada tarifa em análise, o sistema itera hora a hora, calculando:
    *   `Custo da Energia = ? (Consumo da Hora * Preço da Hora)`
    *   Adiciona os custos fixos (potência contratada) e as taxas (IECE, CESE, etc.).
    *   Calcula o IVA de forma incremental, aplicando a taxa reduzida (6%) aos primeiros 100 kWh de consumo no ciclo e a taxa normal (23%) ao restante.
4.  **Projeção de Custos:** Se o ciclo de faturação ainda não terminou, o sistema:
    *   Calcula o consumo médio diário, separado por dias úteis, sábados e domingos.
    *   Usa estas médias para estimar o consumo e o custo para os dias restantes do ciclo.
    *   Soma os custos atuais com os custos projetados para obter uma estimativa do valor final da fatura.
5.  **Apresentação:** Os resultados (custo atual e projetado para cada tarifa) são apresentados ao utilizador, permitindo uma comparação direta e informada.

---

## 4. Algoritmos Detalhados

### 4.1. `omie.py` - Coletor de Preços de Mercado

*   **Objetivo:** Descarregar os preços horários do mercado diário (spot) do site do OMIE.
*   **Algoritmo:**
    1.  Recebe uma data ou um intervalo de datas como argumento de linha de comando (`-d dd/mm/yyyy`).
    2.  Itera por cada dia no intervalo.
    3.  Constrói a URL de download específica para cada dia: `https://www.omie.es/es/file-download?parents%5B0%5D=marginalpdbc&filename=marginalpdbc_YYYYMMDD.1`.
    4.  Faz um pedido HTTP GET para a URL.
    5.  Se o download for bem-sucedido, lê o conteúdo binário do ficheiro.
    6.  **Limpeza:** Remove as linhas de cabeçalho (`MARGINALPDBC;\r\n`) e rodapé (`*\r\n`) do ficheiro para garantir que a concatenação de vários dias resulta num CSV válido.
    7.  Concatena o conteúdo limpo de todos os dias num único buffer.
    8.  Guarda o buffer num único ficheiro CSV, com o nome `marginalpdbc_DDMMYYYY_DDMMYYYY.csv`.

### 4.2. `coletor_precos_dinamicos.php` - Calculadora de Tarifa Dinâmica

*   **Objetivo:** Calcular o preço final horário (?/kWh) que o consumidor de uma tarifa dinâmica (indexada) irá pagar.
*   **Algoritmo:**
    1.  **Obter Preço Base:** Executa o `omie.py` para o dia seguinte.
    2.  **Ler Preços OMIE:** Abre o CSV gerado e carrega os 24 preços horários, convertendo-os de ?/MWh para ?/kWh (dividindo por 1000).
    3.  **Obter Fatores de Perda (FP):** Executa uma query SQL na tabela `perdas_erse_{ANO}` para obter o fator de perda (`BT`) para cada hora do dia seguinte.
    4.  **Obter Tarifas de Acesso (TAR):** Executa uma query SQL na tabela `tarifas_acesso_redes` para obter os preços de acesso em Ponta, Cheia e Vazio para o período atual.
    5.  **Iterar e Calcular:** Para cada uma das 24 horas do dia:
        a.  Determina o período tarifário (Ponta, Cheia, Vazio) da hora atual usando a função `obterPeriodoTarifario` (de `calculos.php`).
        b.  Seleciona a TAR correta com base no período.
        c.  Aplica a fórmula principal para o cálculo do preço final:
            ```
            Preço_Base = Preço_OMIE_kWh + Margem_Comercializador + Custo_Garantias_Origem
            Preço_com_Perdas = Preço_Base * (1 + Fator_Perda_Hora)
            Preço_Final_kWh = Preço_com_Perdas + TAR_kWh_Periodo
            ```
            *   `Margem_Comercializador` e `Custo_Garantias_Origem` são constantes definidas no próprio script (ex: 0.009 e 0.001).
    6.  **Armazenar:** Insere os 24 valores de `Preço_Final_kWh` na tabela `precos_dinamicos` com a data e hora correspondentes. Usa `ON DUPLICATE KEY UPDATE` para garantir que os dados podem ser re-executados sem erro.

### 4.3. `calculos.php` - Lógica de Faturação e Projeção

Este ficheiro é uma biblioteca de funções. As mais importantes são:

*   **`obterDadosPeriodo()`:**
    *   **Objetivo:** Recolher todos os dados de consumo e preços para um ciclo de faturação.
    *   **Algoritmo:**
        1.  Recebe um período (data de início e fim) e a lista de tarifas a analisar.
        2.  Faz uma query à tabela `leituras_energia` para obter o consumo horário (Vazio, Cheia, Ponta) no período.
        3.  Se houver uma tarifa dinâmica, faz uma query à tabela `precos_dinamicos` para carregar os preços horários.
        4.  Itera por cada leitura horária, calculando o custo da energia para essa hora em cada uma das tarifas (seja com preço fixo ou dinâmico).
        5.  **Lógica de IVA Incremental:** Mantém um contador do consumo total acumulado (`$kwh_antes_leitura`). Para cada hora, verifica se o consumo acumulado já ultrapassou o `LIMITE_KWH_IVA_REDUZIDO` (100 kWh).
            *   Se não, parte o custo da hora em duas porções: uma para a base de incidência de IVA reduzido e outra para a base de IVA normal.
            *   Se sim, todo o custo da hora é adicionado à base de incidência de IVA normal.
        6.  Retorna uma estrutura de dados com os totais de consumo, custos e bases de IVA para cada tarifa.

*   **`calcularFaturaDetalhada()`:**
    *   **Objetivo:** Calcular o valor final de uma fatura a partir de dados de custo agregados.
    *   **Algoritmo:**
        1.  Recebe os totais de custo de energia, consumo total (kWh) e dias do período.
        2.  Calcula os custos fixos e taxas variáveis:
            *   `Custo Potência = dias_periodo * custo_potencia_diario`
            *   `Custo Taxas (IECE, etc.) = total_kwh * valor_taxa_kwh`
        3.  Calcula o IVA para cada uma destas parcelas.
        4.  Soma todas as componentes (energia, potência, taxas e respetivos IVAs) para obter o `total_fatura`.

*   **`projetarConsumoDetalhado()`:**
    *   **Objetivo:** Estimar o consumo e os custos para os dias restantes do ciclo de faturação.
    *   **Algoritmo:**
        1.  Recebe os dados de consumo diário já registados no ciclo.
        2.  Agrupa os dados e calcula o consumo médio para: dias úteis, sábados e domingos.
        3.  Se faltarem dados para um tipo de dia (ex: ainda não houve um sábado no ciclo), usa a média global como fallback.
        4.  Conta quantos dias úteis, sábados e domingos faltam até ao final do ciclo.
        5.  Multiplica o número de dias futuros de cada tipo pela respetiva média de consumo para obter o consumo total projetado.
        6.  O mesmo processo é aplicado aos custos de energia, usando o custo médio por tipo de dia.
        7.  Retorna uma estrutura com os totais de consumo e custos projetados.

### 4.4. `descarregar_ofertas.py` - Comparador de Tarifas ERSE

*   **Objetivo:** Encontrar as tarifas de eletricidade mais baratas do mercado para um dado perfil de consumo.
*   **Algoritmo:**
    1.  Acede ao `Settings.json` da ERSE para encontrar a URL do ficheiro ZIP mais recente com todas as ofertas.
    2.  Verifica localmente (via `metadata_eletricidade.json`) se já tem a versão mais recente. Se não, descarrega e extrai os ficheiros CSV.
    3.  Carrega `Precos_ELEGN.csv` (preços) e `CondComerciais.csv` (condições) usando a biblioteca Pandas.
    4.  Junta (merge) os dois ficheiros usando o `CodProposta` como chave.
    5.  Filtra as ofertas com base nos parâmetros fornecidos: potência contratada, tipo de fornecimento (eletricidade) e segmento (doméstico).
    6.  Calcula o custo anual para cada oferta:
        *   `Custo Anual = (TermoFixoDiario * 365) + (ConsumoAnualForaVazio * PrecoForaVazio) + (ConsumoAnualVazio * PrecoVazio)`
    7.  Aplica ajustes especiais (ex: adiciona o custo da subscrição ACP para as tarifas Goldenergy ACP).
    8.  Ordena as ofertas pelo `CustoAnualEstimado` ascendente.
    9.  Exporta o resultado final como um ficheiro JSON para ser consumido por outra parte do sistema.