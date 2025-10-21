# Manual da Aplica��o de Gest�o de Energia

**Vers�o:** 1.0
**Data:** 2024-10-26

---

## 1. Introdu��o

A aplica��o "Energia" � um sistema de *Business Intelligence* pessoal, concebido para monitorizar, analisar e otimizar os custos com eletricidade em Portugal. A sua principal fun��o � permitir que um utilizador, com base nos seus dados de consumo hor�rio, possa:

1.  **Calcular o custo exato** da sua fatura de eletricidade com a tarifa atualmente contratada.
2.  **Simular e comparar** esse custo com outras tarifas fixas e, mais importante, com uma **tarifa din�mica** indexada ao mercado ib�rico (OMIE).
3.  **Projetar os custos** para o resto do ciclo de fatura��o, fornecendo uma estimativa do valor final da fatura.

Para tal, a aplica��o integra dados de m�ltiplas fontes: o consumo do utilizador, os pre�os do mercado grossista (OMIE), os custos regulados (ERSE) e as ofertas comerciais dos fornecedores.

---

## 2. Arquitetura e Componentes

A aplica��o � composta por um conjunto de scripts PHP e Python que trabalham em conjunto com uma base de dados MySQL.

*   **Base de Dados (MySQL):** O n�cleo do sistema, onde s�o armazenados:
    *   `leituras_energia`: Consumos hor�rios do utilizador.
    *   `tarifas`: Detalhes das tarifas a serem analisadas (a contratada e as de compara��o).
    *   `precos_dinamicos`: Pre�os hor�rios finais, j� calculados, para a tarifa din�mica.
    *   `perdas_erse_*`: Tabelas anuais com os fatores de perda da rede el�trica.
    *   `tarifas_acesso_redes`: Custos regulados das Tarifas de Acesso �s Redes.

*   **Scripts de Coleta de Dados (Python):** Respons�veis por obter dados externos essenciais.
    *   `scripts/omie.py`: Descarrega os pre�os hor�rios do mercado di�rio do OMIE.
    *   `scripts/descarregar_ofertas.py`: Descarrega e analisa as ofertas comerciais publicadas pela ERSE.

*   **Scripts de Processamento (PHP):** Cont�m a l�gica de neg�cio da aplica��o.
    *   `coletor_precos_dinamicos.php`: Orquestra a recolha e o c�lculo do pre�o final da tarifa din�mica. **� o script mais cr�tico para o funcionamento da tarifa indexada.**
    *   `calculos.php`: Uma biblioteca de fun��es que realiza todos os c�lculos de custos, proje��es e l�gica de fatura��o.
    *   `config.php`: Ficheiro de configura��o central com credenciais da base de dados, constantes de fatura��o (IVA, taxas) e outros par�metros.

*   **Interface (N�o inclu�da nos ficheiros, mas inferida):** Uma ou mais p�ginas web (ex: `index.php`, `dashboard.php`) que utilizam os scripts PHP para apresentar os dados e os resultados dos c�lculos ao utilizador.

---

## 3. Fluxo de Funcionamento e L�gica

O funcionamento da aplica��o pode ser dividido em tr�s fases principais:

### Fase 1: Coleta de Dados (Automatizada)

1.  **Consumo do Utilizador:** Um sistema externo (provavelmente o Home Assistant, como sugerido em `config.php`) recolhe os dados de consumo hor�rio do contador inteligente e insere-os na tabela `leituras_energia`.

2.  **Pre�os de Mercado (OMIE):** Diariamente, por volta das 13:00, um `cron job` executa o script `coletor_precos_dinamicos.php`. A primeira a��o deste script � chamar o `scripts/omie.py` para descarregar os pre�os de mercado para o dia seguinte.

### Fase 2: Processamento e C�lculo do Pre�o Din�mico (Automatizada)

Esta � a fase mais complexa, executada pelo `coletor_precos_dinamicos.php` logo ap�s a coleta dos pre�os OMIE.

1.  O script l� o ficheiro CSV com os 24 pre�os hor�rios do OMIE (em ?/MWh).
2.  Para cada hora, consulta a base de dados para obter os **custos regulados** correspondentes:
    *   **Fator de Perda (FP):** Obtido da tabela `perdas_erse_*`. Este fator representa a energia que se perde na rede de transporte e distribui��o.
    *   **Tarifa de Acesso �s Redes (TAR):** Obtida da tabela `tarifas_acesso_redes`. Este valor (em ?/kWh) depende do per�odo hor�rio (Ponta, Cheia, Vazio).
3.  Aplica a f�rmula de c�lculo do pre�o final para o consumidor.
4.  O resultado s�o 24 pre�os finais em ?/kWh, que s�o guardados na tabela `precos_dinamicos` para serem usados nos c�lculos da fatura.

### Fase 3: An�lise e Apresenta��o (Interativa)

Quando o utilizador acede � interface da aplica��o:

1.  O sistema (usando as fun��es de `calculos.php`) l� o ciclo de fatura��o atual (ex: dia 16 a 15 do m�s seguinte).
2.  Busca todos os consumos hor�rios (`leituras_energia`) e os pre�os aplic�veis (fixos da tabela `tarifas` ou din�micos da tabela `precos_dinamicos`) para o per�odo.
3.  **C�lculo do Custo Atual:** Para cada tarifa em an�lise, o sistema itera hora a hora, calculando:
    *   `Custo da Energia = ? (Consumo da Hora * Pre�o da Hora)`
    *   Adiciona os custos fixos (pot�ncia contratada) e as taxas (IECE, CESE, etc.).
    *   Calcula o IVA de forma incremental, aplicando a taxa reduzida (6%) aos primeiros 100 kWh de consumo no ciclo e a taxa normal (23%) ao restante.
4.  **Proje��o de Custos:** Se o ciclo de fatura��o ainda n�o terminou, o sistema:
    *   Calcula o consumo m�dio di�rio, separado por dias �teis, s�bados e domingos.
    *   Usa estas m�dias para estimar o consumo e o custo para os dias restantes do ciclo.
    *   Soma os custos atuais com os custos projetados para obter uma estimativa do valor final da fatura.
5.  **Apresenta��o:** Os resultados (custo atual e projetado para cada tarifa) s�o apresentados ao utilizador, permitindo uma compara��o direta e informada.

---

## 4. Algoritmos Detalhados

### 4.1. `omie.py` - Coletor de Pre�os de Mercado

*   **Objetivo:** Descarregar os pre�os hor�rios do mercado di�rio (spot) do site do OMIE.
*   **Algoritmo:**
    1.  Recebe uma data ou um intervalo de datas como argumento de linha de comando (`-d dd/mm/yyyy`).
    2.  Itera por cada dia no intervalo.
    3.  Constr�i a URL de download espec�fica para cada dia: `https://www.omie.es/es/file-download?parents%5B0%5D=marginalpdbc&filename=marginalpdbc_YYYYMMDD.1`.
    4.  Faz um pedido HTTP GET para a URL.
    5.  Se o download for bem-sucedido, l� o conte�do bin�rio do ficheiro.
    6.  **Limpeza:** Remove as linhas de cabe�alho (`MARGINALPDBC;\r\n`) e rodap� (`*\r\n`) do ficheiro para garantir que a concatena��o de v�rios dias resulta num CSV v�lido.
    7.  Concatena o conte�do limpo de todos os dias num �nico buffer.
    8.  Guarda o buffer num �nico ficheiro CSV, com o nome `marginalpdbc_DDMMYYYY_DDMMYYYY.csv`.

### 4.2. `coletor_precos_dinamicos.php` - Calculadora de Tarifa Din�mica

*   **Objetivo:** Calcular o pre�o final hor�rio (?/kWh) que o consumidor de uma tarifa din�mica (indexada) ir� pagar.
*   **Algoritmo:**
    1.  **Obter Pre�o Base:** Executa o `omie.py` para o dia seguinte.
    2.  **Ler Pre�os OMIE:** Abre o CSV gerado e carrega os 24 pre�os hor�rios, convertendo-os de ?/MWh para ?/kWh (dividindo por 1000).
    3.  **Obter Fatores de Perda (FP):** Executa uma query SQL na tabela `perdas_erse_{ANO}` para obter o fator de perda (`BT`) para cada hora do dia seguinte.
    4.  **Obter Tarifas de Acesso (TAR):** Executa uma query SQL na tabela `tarifas_acesso_redes` para obter os pre�os de acesso em Ponta, Cheia e Vazio para o per�odo atual.
    5.  **Iterar e Calcular:** Para cada uma das 24 horas do dia:
        a.  Determina o per�odo tarif�rio (Ponta, Cheia, Vazio) da hora atual usando a fun��o `obterPeriodoTarifario` (de `calculos.php`).
        b.  Seleciona a TAR correta com base no per�odo.
        c.  Aplica a f�rmula principal para o c�lculo do pre�o final:
            ```
            Pre�o_Base = Pre�o_OMIE_kWh + Margem_Comercializador + Custo_Garantias_Origem
            Pre�o_com_Perdas = Pre�o_Base * (1 + Fator_Perda_Hora)
            Pre�o_Final_kWh = Pre�o_com_Perdas + TAR_kWh_Periodo
            ```
            *   `Margem_Comercializador` e `Custo_Garantias_Origem` s�o constantes definidas no pr�prio script (ex: 0.009 e 0.001).
    6.  **Armazenar:** Insere os 24 valores de `Pre�o_Final_kWh` na tabela `precos_dinamicos` com a data e hora correspondentes. Usa `ON DUPLICATE KEY UPDATE` para garantir que os dados podem ser re-executados sem erro.

### 4.3. `calculos.php` - L�gica de Fatura��o e Proje��o

Este ficheiro � uma biblioteca de fun��es. As mais importantes s�o:

*   **`obterDadosPeriodo()`:**
    *   **Objetivo:** Recolher todos os dados de consumo e pre�os para um ciclo de fatura��o.
    *   **Algoritmo:**
        1.  Recebe um per�odo (data de in�cio e fim) e a lista de tarifas a analisar.
        2.  Faz uma query � tabela `leituras_energia` para obter o consumo hor�rio (Vazio, Cheia, Ponta) no per�odo.
        3.  Se houver uma tarifa din�mica, faz uma query � tabela `precos_dinamicos` para carregar os pre�os hor�rios.
        4.  Itera por cada leitura hor�ria, calculando o custo da energia para essa hora em cada uma das tarifas (seja com pre�o fixo ou din�mico).
        5.  **L�gica de IVA Incremental:** Mant�m um contador do consumo total acumulado (`$kwh_antes_leitura`). Para cada hora, verifica se o consumo acumulado j� ultrapassou o `LIMITE_KWH_IVA_REDUZIDO` (100 kWh).
            *   Se n�o, parte o custo da hora em duas por��es: uma para a base de incid�ncia de IVA reduzido e outra para a base de IVA normal.
            *   Se sim, todo o custo da hora � adicionado � base de incid�ncia de IVA normal.
        6.  Retorna uma estrutura de dados com os totais de consumo, custos e bases de IVA para cada tarifa.

*   **`calcularFaturaDetalhada()`:**
    *   **Objetivo:** Calcular o valor final de uma fatura a partir de dados de custo agregados.
    *   **Algoritmo:**
        1.  Recebe os totais de custo de energia, consumo total (kWh) e dias do per�odo.
        2.  Calcula os custos fixos e taxas vari�veis:
            *   `Custo Pot�ncia = dias_periodo * custo_potencia_diario`
            *   `Custo Taxas (IECE, etc.) = total_kwh * valor_taxa_kwh`
        3.  Calcula o IVA para cada uma destas parcelas.
        4.  Soma todas as componentes (energia, pot�ncia, taxas e respetivos IVAs) para obter o `total_fatura`.

*   **`projetarConsumoDetalhado()`:**
    *   **Objetivo:** Estimar o consumo e os custos para os dias restantes do ciclo de fatura��o.
    *   **Algoritmo:**
        1.  Recebe os dados de consumo di�rio j� registados no ciclo.
        2.  Agrupa os dados e calcula o consumo m�dio para: dias �teis, s�bados e domingos.
        3.  Se faltarem dados para um tipo de dia (ex: ainda n�o houve um s�bado no ciclo), usa a m�dia global como fallback.
        4.  Conta quantos dias �teis, s�bados e domingos faltam at� ao final do ciclo.
        5.  Multiplica o n�mero de dias futuros de cada tipo pela respetiva m�dia de consumo para obter o consumo total projetado.
        6.  O mesmo processo � aplicado aos custos de energia, usando o custo m�dio por tipo de dia.
        7.  Retorna uma estrutura com os totais de consumo e custos projetados.

### 4.4. `descarregar_ofertas.py` - Comparador de Tarifas ERSE

*   **Objetivo:** Encontrar as tarifas de eletricidade mais baratas do mercado para um dado perfil de consumo.
*   **Algoritmo:**
    1.  Acede ao `Settings.json` da ERSE para encontrar a URL do ficheiro ZIP mais recente com todas as ofertas.
    2.  Verifica localmente (via `metadata_eletricidade.json`) se j� tem a vers�o mais recente. Se n�o, descarrega e extrai os ficheiros CSV.
    3.  Carrega `Precos_ELEGN.csv` (pre�os) e `CondComerciais.csv` (condi��es) usando a biblioteca Pandas.
    4.  Junta (merge) os dois ficheiros usando o `CodProposta` como chave.
    5.  Filtra as ofertas com base nos par�metros fornecidos: pot�ncia contratada, tipo de fornecimento (eletricidade) e segmento (dom�stico).
    6.  Calcula o custo anual para cada oferta:
        *   `Custo Anual = (TermoFixoDiario * 365) + (ConsumoAnualForaVazio * PrecoForaVazio) + (ConsumoAnualVazio * PrecoVazio)`
    7.  Aplica ajustes especiais (ex: adiciona o custo da subscri��o ACP para as tarifas Goldenergy ACP).
    8.  Ordena as ofertas pelo `CustoAnualEstimado` ascendente.
    9.  Exporta o resultado final como um ficheiro JSON para ser consumido por outra parte do sistema.