<?php
// Ficheiro: calculos.php (v5.2 - Custo de energia dividido e projeção)

function obterDadosPeriodo(mysqli $mysqli, string $data_inicio_str, string $data_fim_str, array $tarifas): array
{
    $sql = "
        SELECT
            data_hora,
            consumo_vazio,
            consumo_cheia,
            consumo_ponta
        FROM leituras_energia le
        WHERE DATE(le.data_hora) BETWEEN ? AND ?
        ORDER BY data_hora ASC";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ss", $data_inicio_str, $data_fim_str);
    $stmt->execute();
    $result = $stmt->get_result();

    $precos_dinamicos = [];
    $tem_tarifa_dinamica = !empty(array_filter($tarifas, fn($t) => $t['modalidade'] === 'dinamico'));

    if ($tem_tarifa_dinamica) {
        $sql_precos = "SELECT * FROM precos_dinamicos WHERE DATE(data_hora) BETWEEN ? AND ?";
        $stmt_precos = $mysqli->prepare($sql_precos);
        if ($stmt_precos) {
            $stmt_precos->bind_param("ss", $data_inicio_str, $data_fim_str);
            $stmt_precos->execute();
            $result_precos = $stmt_precos->get_result();
            while ($row = $result_precos->fetch_assoc()) { $precos_dinamicos[$row['data_hora']] = $row; }
            $stmt_precos->close();
        }
    }

    $dados_por_dia = [];
    $tarifas_ids = array_keys($tarifas);
    $totais = [
        'consumo_vazio' => 0, 'consumo_cheia' => 0, 'consumo_ponta' => 0, 'total_kwh' => 0,
        'custos_energia' => array_fill_keys($tarifas_ids, 0),
        'custos_vazio' => array_fill_keys($tarifas_ids, 0),
        'custos_fora_vazio' => array_fill_keys($tarifas_ids, 0),
        'iva_energia_reduzido' => array_fill_keys($tarifas_ids, 0),
        'iva_energia_normal' => array_fill_keys($tarifas_ids, 0),
        'base_iva_energia_reduzido' => array_fill_keys($tarifas_ids, 0),
        'base_iva_energia_normal' => array_fill_keys($tarifas_ids, 0)
    ];

    while ($row = $result->fetch_assoc()) {
        $data_leitura = new DateTime($row['data_hora'], new DateTimeZone('UTC'));
        $dia = $data_leitura->format('Y-m-d');
        $consumo_total_leitura = $row['consumo_vazio'] + $row['consumo_cheia'] + $row['consumo_ponta'];

        if (!isset($dados_por_dia[$dia])) {
            $dados_por_dia[$dia] = [
                'consumo_vazio' => 0, 'consumo_cheia' => 0, 'consumo_ponta' => 0,
                'custos_energia' => [], 'custos_vazio' => [], 'custos_fora_vazio' => []
            ];
        }

        $dados_por_dia[$dia]['consumo_vazio'] += $row['consumo_vazio'];
        $dados_por_dia[$dia]['consumo_cheia'] += $row['consumo_cheia'];
        $dados_por_dia[$dia]['consumo_ponta'] += $row['consumo_ponta'];
        $totais['consumo_vazio'] += $row['consumo_vazio'];
        $totais['consumo_cheia'] += $row['consumo_cheia'];
        $totais['consumo_ponta'] += $row['consumo_ponta'];
        $totais['total_kwh'] += $consumo_total_leitura;

        foreach ($tarifas as $id => $tarifa) {
            $kwh_antes_leitura = $totais['total_kwh'] - $consumo_total_leitura;
            
            $custo_vazio_leitura = 0;
            $custo_fora_vazio_leitura = 0;

            if ($tarifa['modalidade'] === 'simples') {
                $custo_fora_vazio_leitura = $consumo_total_leitura * $tarifa['preco_simples'];
            } elseif ($tarifa['modalidade'] === 'bi-horario') {
                $custo_vazio_leitura = $row['consumo_vazio'] * $tarifa['preco_vazio'];
                $custo_fora_vazio_leitura = ($row['consumo_cheia'] * $tarifa['preco_cheia']) + ($row['consumo_ponta'] * $tarifa['preco_ponta']);
            } elseif ($tarifa['modalidade'] === 'dinamico') {
                $data_hora_leitura = $data_leitura->format('Y-m-d H:00:00');
                if (isset($precos_dinamicos[$data_hora_leitura])) {
                    $preco_kwh = $precos_dinamicos[$data_hora_leitura]['preco_kwh'];
                    $periodo_tarifario = obterPeriodoTarifario($data_leitura);
                    if ($periodo_tarifario === 'Vazio') {
                        $custo_vazio_leitura = $consumo_total_leitura * $preco_kwh;
                    } else {
                        $custo_fora_vazio_leitura = $consumo_total_leitura * $preco_kwh;
                    }
                }
            }
            
            $custo_leitura = $custo_vazio_leitura + $custo_fora_vazio_leitura;

            if ($kwh_antes_leitura < LIMITE_KWH_IVA_REDUZIDO) {
                $kwh_para_iva_reduzido = min($consumo_total_leitura, LIMITE_KWH_IVA_REDUZIDO - $kwh_antes_leitura);
                $kwh_para_iva_normal = $consumo_total_leitura - $kwh_para_iva_reduzido;

                if ($consumo_total_leitura > 0) {
                    $fracao_reduzida = $kwh_para_iva_reduzido / $consumo_total_leitura;
                    $fracao_normal = $kwh_para_iva_normal / $consumo_total_leitura;
                    $custo_fracao_reduzida = $custo_leitura * $fracao_reduzida;
                    $custo_fracao_normal = $custo_leitura * $fracao_normal;
                    $totais['base_iva_energia_reduzido'][$id] += $custo_fracao_reduzida;
                    $totais['base_iva_energia_normal'][$id] += $custo_fracao_normal;
                    $totais['iva_energia_reduzido'][$id] += $custo_fracao_reduzida * IVA_REDUZIDO;
                    $totais['iva_energia_normal'][$id] += $custo_fracao_normal * IVA_NORMAL;
                }
            } else {
                $totais['base_iva_energia_normal'][$id] += $custo_leitura;
                $totais['iva_energia_normal'][$id] += $custo_leitura * IVA_NORMAL;
            }

            $dados_por_dia[$dia]['custos_vazio'][$id] = ($dados_por_dia[$dia]['custos_vazio'][$id] ?? 0) + $custo_vazio_leitura;
            $dados_por_dia[$dia]['custos_fora_vazio'][$id] = ($dados_por_dia[$dia]['custos_fora_vazio'][$id] ?? 0) + $custo_fora_vazio_leitura;
            $dados_por_dia[$dia]['custos_energia'][$id] = ($dados_por_dia[$dia]['custos_energia'][$id] ?? 0) + $custo_leitura;
            
            $totais['custos_vazio'][$id] = ($totais['custos_vazio'][$id] ?? 0) + $custo_vazio_leitura;
            $totais['custos_fora_vazio'][$id] = ($totais['custos_fora_vazio'][$id] ?? 0) + $custo_fora_vazio_leitura;
            $totais['custos_energia'][$id] += $custo_leitura;
        }
    }
    $stmt->close();

    return [
        'dados_por_dia' => $dados_por_dia,
        'totais' => $totais,
        'dias_registados' => count($dados_por_dia)
    ];
}

function calcularFaturaDetalhada(array $dados_custos_totais, int $dias_periodo, float $custo_potencia_diario, bool $is_projection = false): array
{
    $resultado = [];
    $resultado['energia_custo_base'] = $dados_custos_totais['custo_energia'] ?? 0;
    $total_kwh = $dados_custos_totais['total_kwh'] ?? 0;

    // Custos fixos e taxas variáveis são sempre calculados com base nos dias do período e no consumo total (seja ele atual ou projetado).
    $resultado['potencia_custo_base'] = $dias_periodo * $custo_potencia_diario;
    $resultado['ts_custo_base'] = $total_kwh * TAXA_SOCIAL_VALOR_KWH;
    $resultado['iece_custo_base'] = $total_kwh * IECE_VALOR_KWH;
    $resultado['cav_custo_base'] = $dias_periodo * CAV_VALOR_DIARIO;

    // IVA sobre taxas e potência
    $resultado['potencia_iva'] = $resultado['potencia_custo_base'] * IVA_NORMAL;
    $resultado['ts_iva'] = $resultado['ts_custo_base'] * IVA_NORMAL;
    $resultado['iece_iva'] = $resultado['iece_custo_base'] * IVA_NORMAL;
    $resultado['cav_iva'] = $resultado['cav_custo_base'] * IVA_REDUZIDO;

    // --- Cálculo do IVA sobre a Energia ---
    $base_iva_energia_reduzido = 0;
    $base_iva_energia_normal = 0;

    if ($is_projection) {
        $custo_energia_total = $resultado['energia_custo_base'];
        $custo_energia_atual = $dados_custos_totais['custo_energia_atual'] ?? 0;
        $custo_energia_futuro = $custo_energia_total - $custo_energia_atual;

        $total_kwh_projetado = $dados_custos_totais['total_kwh'] ?? 0;
        $total_kwh_atual = $dados_custos_totais['total_kwh_atual'] ?? 0;
        $kwh_futuro = $total_kwh_projetado - $total_kwh_atual;

        $base_iva_energia_reduzido = $dados_custos_totais['base_iva_energia_reduzido_atual'] ?? 0;
        $base_iva_energia_normal = $dados_custos_totais['base_iva_energia_normal_atual'] ?? 0;

        if ($kwh_futuro > 0) {
            $preco_medio_kwh_futuro = $custo_energia_futuro / $kwh_futuro;
            
            $kwh_restantes_iva_reduzido = max(0, LIMITE_KWH_IVA_REDUZIDO - $total_kwh_atual);
            $kwh_futuro_com_iva_reduzido = min($kwh_futuro, $kwh_restantes_iva_reduzido);
            $kwh_futuro_com_iva_normal = $kwh_futuro - $kwh_futuro_com_iva_reduzido;

            $base_iva_energia_reduzido += $kwh_futuro_com_iva_reduzido * $preco_medio_kwh_futuro;
            $base_iva_energia_normal += $kwh_futuro_com_iva_normal * $preco_medio_kwh_futuro;
        }

        $iva_energia_reduzido = $base_iva_energia_reduzido * IVA_REDUZIDO;
        $iva_energia_normal = $base_iva_energia_normal * IVA_NORMAL;
    } else {
        // Para valores atuais, os valores de IVA e base de incidência já vêm calculados de forma incremental
        $iva_energia_reduzido = $dados_custos_totais['iva_energia_reduzido'] ?? 0;
        $iva_energia_normal = $dados_custos_totais['iva_energia_normal'] ?? 0;
        $base_iva_energia_reduzido = $dados_custos_totais['base_iva_energia_reduzido'] ?? 0;
        $base_iva_energia_normal = $dados_custos_totais['base_iva_energia_normal'] ?? 0;
    }

    // Agrega as bases de IVA e os totais de IVA
    $resultado['base_iva_reduzido'] = $resultado['cav_custo_base'] + $base_iva_energia_reduzido;
    $resultado['base_iva_normal'] = $resultado['potencia_custo_base'] + $resultado['ts_custo_base'] + $resultado['iece_custo_base'] + $base_iva_energia_normal;
    
    $resultado['total_iva_reduzido'] = $resultado['cav_iva'] + $iva_energia_reduzido;
    $resultado['total_iva_normal'] = $resultado['potencia_iva'] + $resultado['ts_iva'] + $resultado['iece_iva'] + $iva_energia_normal;

    // Calcula o total final da fatura
    $subtotal_base = $resultado['energia_custo_base'] + $resultado['potencia_custo_base'] + $resultado['ts_custo_base'] + $resultado['iece_custo_base'] + $resultado['cav_custo_base'];
    $total_iva = $resultado['total_iva_reduzido'] + $resultado['total_iva_normal'];
    $resultado['total_fatura'] = $subtotal_base + $total_iva;

    return $resultado;
}

function projetarConsumoDetalhado(array $dados_atuais_por_dia, DateTime $data_fim_periodo): array
{
    // Para a projeção, não consideramos o dia de hoje, pois está incompleto.
    $dados_para_projecao = $dados_atuais_por_dia;
    $hoje_str = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
    if (isset($dados_para_projecao[$hoje_str])) {
        unset($dados_para_projecao[$hoje_str]);
    }

    $consumos_agregados = ['uteis' => [], 'sabado' => [], 'domingo' => []];
    $dias_contados = ['uteis' => 0, 'sabado' => 0, 'domingo' => 0];

    $metricas_consumo = ['consumo_vazio', 'consumo_cheia', 'consumo_ponta', 'total_kwh'];
    $todas_tarifas_ids = [];

    foreach ($dados_para_projecao as $dia => $dados) {
        $data = new DateTime($dia);
        $dia_semana = (int)$data->format('N');
        $tipo_dia = 'uteis';
        if ($dia_semana === 6) $tipo_dia = 'sabado';
        if ($dia_semana === 7) $tipo_dia = 'domingo';

        // Adiciona o consumo total do dia para a projeção
        $dados['total_kwh'] = ($dados['consumo_vazio'] ?? 0) + ($dados['consumo_cheia'] ?? 0) + ($dados['consumo_ponta'] ?? 0);

        foreach ($metricas_consumo as $metrica) {
            if (!isset($consumos_agregados[$tipo_dia][$metrica])) $consumos_agregados[$tipo_dia][$metrica] = 0;
            $consumos_agregados[$tipo_dia][$metrica] += $dados[$metrica] ?? 0;
        }
        if (isset($dados['custos_energia'])) {
            foreach ($dados['custos_energia'] as $tarifa_id => $custo) {
                $metrica_custo = 'custo_tarifa_' . $tarifa_id;
                if (!isset($consumos_agregados[$tipo_dia][$metrica_custo])) $consumos_agregados[$tipo_dia][$metrica_custo] = 0;
                $consumos_agregados[$tipo_dia][$metrica_custo] += $custo;
                if (!in_array($tarifa_id, $todas_tarifas_ids)) $todas_tarifas_ids[] = $tarifa_id;
            }
        }
        $dias_contados[$tipo_dia]++;
    }

    $metricas_custo = array_map(fn($id) => 'custo_tarifa_' . $id, $todas_tarifas_ids);
    $metricas_projecao = array_merge($metricas_consumo, $metricas_custo);

    $medias_diarias = [];
    foreach ($dias_contados as $tipo => $contagem) {
        if ($contagem > 0) {
            foreach ($consumos_agregados[$tipo] as $metrica => $valor_total) {
                $medias_diarias[$tipo][$metrica] = $valor_total / $contagem;
            }
        }
    }

    $medias_globais = [];
    $total_dias_contados = array_sum($dias_contados);
    if ($total_dias_contados > 0) {
        foreach ($metricas_projecao as $metrica) {
            $valor_total_global = array_sum(array_column($consumos_agregados, $metrica));
            $medias_globais[$metrica] = $valor_total_global / $total_dias_contados;
        }
    } else {
        // Se não há dias completos para a média, a projeção é zero.
        return array_fill_keys($metricas_projecao, 0);
    }

    $medias_diarias['uteis'] = $medias_diarias['uteis'] ?? $medias_globais;
    $medias_diarias['sabado'] = $medias_diarias['sabado'] ?? $medias_globais;
    $medias_diarias['domingo'] = $medias_diarias['domingo'] ?? $medias_globais;

    $dias_futuros = ['uteis' => 0, 'sabado' => 0, 'domingo' => 0];
    $hoje = new DateTime('now', new DateTimeZone('UTC'));
    $amanha = (clone $hoje)->modify('+1 day')->setTime(0, 0, 0);
    if ($amanha > $data_fim_periodo) return array_fill_keys($metricas_projecao, 0);

    $periodo_futuro = new DatePeriod($amanha, new DateInterval('P1D'), (clone $data_fim_periodo)->modify('+1 day'));
    foreach ($periodo_futuro as $data) {
        $dia_semana = (int)$data->format('N');
        if ($dia_semana >= 1 && $dia_semana <= 5) $dias_futuros['uteis']++;
        if ($dia_semana === 6) $dias_futuros['sabado']++;
        if ($dia_semana === 7) $dias_futuros['domingo']++;
    }

    $consumo_futuro_projetado = array_fill_keys($metricas_projecao, 0);
    foreach (['uteis', 'sabado', 'domingo'] as $tipo) {
        foreach ($consumo_futuro_projetado as $metrica => $valor) {
            $consumo_futuro_projetado[$metrica] += $dias_futuros[$tipo] * ($medias_diarias[$tipo][$metrica] ?? 0);
        }
    }

    return $consumo_futuro_projetado;
}

function obterPeriodoTarifario(DateTime $data): string
{
    // Clonar a data para não modificar o objeto original
    $data_local = clone $data;
    $data_local->setTimezone(new DateTimeZone('Europe/Lisbon'));

    $ano = (int)$data_local->format('Y');
    $start_summer = new DateTime("last sunday of March $ano", new DateTimeZone('Europe/Lisbon'));
    $end_summer = new DateTime("last sunday of October $ano", new DateTimeZone('Europe/Lisbon'));
    $is_summer = ($data >= $start_summer && $data < $end_summer);

    $dia_semana = (int)$data->format('N'); // 1-7, 1=Segunda
    $hora = (int)$data->format('G'); // 0-23
    $minuto = (int)$data->format('i');

    if ($dia_semana === 7) return 'Vazio'; // Domingo é sempre Vazio

    // A lógica para Ponta e Cheia é mais complexa e depende do ciclo diário/semanal
    // Esta é uma simplificação. A E-REDES tem lógicas diferentes para ciclo diário e semanal.
    // Assumindo ciclo semanal para este exemplo.
    if ($is_summer) { // Horário de Verão
        if ($dia_semana <= 5) { // Dias úteis
            if (($hora >= 9 && $hora < 10) || ($hora >= 13 && $hora < 14) || ($hora >= 19 && $hora < 20)) return 'Ponta';
            if ($hora >= 7 && $hora < 24) return 'Cheia';
        }
        if ($dia_semana === 6) { // Sábado
            if ($hora >= 9 && $hora < 14) return 'Cheia';
            if ($hora >= 20 && $hora < 22) return 'Cheia';
        }
    } else { // Horário de Inverno
        if ($dia_semana <= 5) { // Dias úteis
            if (($hora >= 9 && $hora < 11) || ($hora >= 18 && $hora < 21)) return 'Ponta';
            if ($hora >= 7 && $hora < 24) return 'Cheia';
        }
        if ($dia_semana === 6) { // Sábado
            $hm = $hora * 100 + $minuto;
            if (($hm >= 930 && $hm < 1300) || ($hm >= 1830 && $hm < 2200)) return 'Cheia';
        }
    }

    return 'Vazio'; // Restantes períodos são Vazio
}

function obterDadosDashboard(mysqli $mysqli, array $get_params): array
{
    $dia_inicio_ciclo = 16;
    if (isset($get_params['ano']) && is_numeric($get_params['ano']) && isset($get_params['mes']) && is_numeric($get_params['mes'])) {
        $ano_selecionado = (int)$get_params['ano'];
        $mes_selecionado = (int)$get_params['mes'];
        $data_fim_periodo = new DateTime("$ano_selecionado-$mes_selecionado-" . ($dia_inicio_ciclo - 1));
        $data_inicio_periodo = (new DateTime("$ano_selecionado-$mes_selecionado-" . $dia_inicio_ciclo))->modify('-1 month');
        $titulo_pagina = "Período de " . $data_inicio_periodo->format('d/m/Y') . " a " . $data_fim_periodo->format('d/m/Y');
        $is_current_period = false;
    } else {
        $dia_hoje = (int)date('j');
        if ($dia_hoje >= $dia_inicio_ciclo) {
            $data_inicio_periodo = new DateTime(date('Y-m-') . $dia_inicio_ciclo);
            $data_fim_periodo = (new DateTime(date('Y-m-') . ($dia_inicio_ciclo - 1)))->modify('+1 month');
        } else {
            $data_inicio_periodo = (new DateTime(date('Y-m-') . $dia_inicio_ciclo))->modify('-1 month');
            $data_fim_periodo = new DateTime(date('Y-m-') . ($dia_inicio_ciclo - 1));
        }
        $titulo_pagina = "Período Atual";
        $is_current_period = true;
    }

    $data_inicio_str = $data_inicio_periodo->format('Y-m-d');
    $data_fim_str = $data_fim_periodo->format('Y-m-d');
    $data_fim_query = $is_current_period ? date('Y-m-d') : $data_fim_str;
    $total_dias_periodo = $data_inicio_periodo->diff($data_fim_periodo)->days + 1;

    // Busca as tarifas selecionadas para o dashboard
    $tarifa_sql = "
        SELECT * FROM tarifas 
        WHERE 
            (is_contratada = TRUE OR is_comparacao = TRUE OR modalidade = 'dinamico')
            AND data_inicio <= ? 
            AND (data_fim IS NULL OR data_fim >= ?)
        ORDER BY is_contratada DESC, is_comparacao DESC, nome_tarifa ASC
    ";
    $stmt_tarifa = $mysqli->prepare($tarifa_sql);
    $stmt_tarifa->bind_param("ss", $data_fim_str, $data_inicio_str);
    $stmt_tarifa->execute();
    $tarifas_result = $stmt_tarifa->get_result();
    $tarifas = [];
    // Usamos um array associativo para garantir que não há tarifas duplicadas
    // (ex: se a dinâmica for também de comparação)
    while ($row = $tarifas_result->fetch_assoc()) { $tarifas[$row['id']] = $row; }
    $stmt_tarifa->close();

    $dados_atuais = obterDadosPeriodo($mysqli, $data_inicio_str, $data_fim_query, $tarifas);
    $dados_consumo_atual = $dados_atuais['totais'];
    $dias_registados_atual = $dados_atuais['dias_registados'];

    $faturas_atuais = [];
    $faturas_projetadas = [];
    $consumo_projetado = [];

    foreach ($tarifas as $id => $tarifa) {
        $custo_potencia = $tarifa['custo_potencia_diario'];
        $dados_fatura_atual = [
            'custo_energia' => $dados_consumo_atual['custos_energia'][$id] ?? 0,
            'total_kwh' => $dados_consumo_atual['total_kwh'],
            'iva_energia_reduzido' => $dados_consumo_atual['iva_energia_reduzido'][$id] ?? 0,
            'iva_energia_normal' => $dados_consumo_atual['iva_energia_normal'][$id] ?? 0,
            'base_iva_energia_reduzido' => $dados_consumo_atual['base_iva_energia_reduzido'][$id] ?? 0,
            'base_iva_energia_normal' => $dados_consumo_atual['base_iva_energia_normal'][$id] ?? 0,
        ];
        $faturas_atuais[$id] = calcularFaturaDetalhada($dados_fatura_atual, $total_dias_periodo, $custo_potencia, false);
    }

    if ($is_current_period && $dias_registados_atual > 0) {
        $consumo_futuro = projetarConsumoDetalhado($dados_atuais['dados_por_dia'], $data_fim_periodo);
        $consumo_projetado = $consumo_futuro; // Agora $consumo_projetado contém apenas o consumo futuro

        foreach ($tarifas as $id => $tarifa) {
            $custo_potencia = $tarifa['custo_potencia_diario'];
            
            // A projeção do IVA é feita de forma simplificada (média) pois não temos dados futuros
            $custo_energia_total_projetado = ($dados_consumo_atual['custos_energia'][$id] ?? 0) + ($consumo_futuro['custo_tarifa_' . $id] ?? 0);
            $consumo_total_kwh_projetado = ($dados_consumo_atual['total_kwh'] ?? 0) + ($consumo_futuro['total_kwh'] ?? 0);
            $dados_fatura_projetada = [
                'custo_energia' => $custo_energia_total_projetado,
                'total_kwh' => $consumo_total_kwh_projetado,
                'custo_energia_atual' => $dados_consumo_atual['custos_energia'][$id] ?? 0,
                'total_kwh_atual' => $dados_consumo_atual['total_kwh'] ?? 0,
                'base_iva_energia_reduzido_atual' => $dados_consumo_atual['base_iva_energia_reduzido'][$id] ?? 0,
                'base_iva_energia_normal_atual' => $dados_consumo_atual['base_iva_energia_normal'][$id] ?? 0,
            ];
            $faturas_projetadas[$id] = calcularFaturaDetalhada($dados_fatura_projetada, $total_dias_periodo, $custo_potencia, true);
        }
    }

    $ultimos_registos_sql = "
        SELECT le.data_hora, le.consumo_vazio, le.consumo_cheia, le.consumo_ponta
        FROM leituras_energia le
        WHERE DATE(le.data_hora) BETWEEN ? AND ?
        ORDER BY le.data_hora DESC";
    $stmt_registos = $mysqli->prepare($ultimos_registos_sql);
    $stmt_registos->bind_param("ss", $data_inicio_str, $data_fim_str);
    $stmt_registos->execute();
    $ultimos_registos_result = $stmt_registos->get_result();

    $periodos_historicos_sql = "SELECT DISTINCT YEAR(data_hora) as ano, MONTH(data_hora) as mes FROM leituras_energia ORDER BY ano DESC, mes DESC";
    $periodos_historicos_result = $mysqli->query($periodos_historicos_sql);

    return [
        'titulo_pagina' => $titulo_pagina,
        'is_current_period' => $is_current_period,
        'ano_selecionado' => $ano_selecionado ?? null,
        'mes_selecionado' => $mes_selecionado ?? null,
        'total_dias_periodo' => $total_dias_periodo,
        'tarifas' => $tarifas,
        'faturas_atuais' => $faturas_atuais,
        'faturas_projetadas' => $faturas_projetadas,
        'dados_consumo_atual' => $dados_consumo_atual,
        'dias_registados_atual' => $dias_registados_atual,
        'consumo_projetado' => $consumo_projetado,
        'ultimos_registos_result' => $ultimos_registos_result,
        'periodos_historicos_result' => $periodos_historicos_result
    ];
}

function obterDadosFaturaDetalhada(mysqli $mysqli, array $get_params): array
{
    // 1. Obter período e tarifário contratado
    $dia_inicio_ciclo = 16;
    if (isset($get_params['ano']) && is_numeric($get_params['ano']) && isset($get_params['mes']) && is_numeric($get_params['mes'])) {
        $ano_selecionado = (int)$get_params['ano'];
        $mes_selecionado = (int)$get_params['mes'];
        $data_fim_periodo = new DateTime("$ano_selecionado-$mes_selecionado-" . ($dia_inicio_ciclo - 1));
        $data_inicio_periodo = (new DateTime("$ano_selecionado-$mes_selecionado-" . $dia_inicio_ciclo))->modify('-1 month');
        $titulo_pagina = "Período de " . $data_inicio_periodo->format('d/m/Y') . " a " . $data_fim_periodo->format('d/m/Y');
        $is_current_period = false;
    } else {
        $dia_hoje = (int)date('j');
        if ($dia_hoje >= $dia_inicio_ciclo) {
            $data_inicio_periodo = new DateTime(date('Y-m-') . $dia_inicio_ciclo);
            $data_fim_periodo = (new DateTime(date('Y-m-') . ($dia_inicio_ciclo - 1)))->modify('+1 month');
        } else {
            $data_inicio_periodo = (new DateTime(date('Y-m-') . $dia_inicio_ciclo))->modify('-1 month');
            $data_fim_periodo = new DateTime(date('Y-m-') . ($dia_inicio_ciclo - 1));
        }
        $titulo_pagina = "Período Atual";
        $is_current_period = true;
    }

    $data_inicio_str = $data_inicio_periodo->format('Y-m-d');
    $data_fim_str = $data_fim_periodo->format('Y-m-d');
    $data_fim_query = $is_current_period ? date('Y-m-d') : $data_fim_str;

    $tarifa_sql = "SELECT * FROM tarifas WHERE is_contratada = TRUE AND data_inicio <= ? AND (data_fim IS NULL OR data_fim >= ?) ORDER BY data_inicio DESC LIMIT 1";
    $stmt_tarifa = $mysqli->prepare($tarifa_sql);
    $stmt_tarifa->bind_param("ss", $data_fim_str, $data_inicio_str);
    $stmt_tarifa->execute();
    $tarifa_contratada = $stmt_tarifa->get_result()->fetch_assoc();
    $stmt_tarifa->close();

    if (!$tarifa_contratada) {
        return [ 'nome_tarifa_contratada' => 'Nenhuma', 'titulo_pagina' => $titulo_pagina, 'is_current_period' => $is_current_period, 'dados_reais_dia_a_dia' => [], 'dados_projetados_dia_a_dia' => [], 'totais_reais' => [], 'totais_projetados' => [] ];
    }

    // 2. Obter dados de consumo
    $dados_periodo = obterDadosPeriodo($mysqli, $data_inicio_str, $data_fim_query, [$tarifa_contratada['id'] => $tarifa_contratada]);
    $dados_por_dia = $dados_periodo['dados_por_dia'];
    ksort($dados_por_dia);

    // 3. Processar dados reais dia a dia
    $dados_reais_dia_a_dia = [];
    $consumo_acumulado = 0;
    $consumo_vazio_acumulado = 0;
    $consumo_fora_vazio_acumulado = 0;
    $total_acumulado = 0;
    $totais_reais = array_fill_keys(['energia_custo_base', 'potencia_custo_base', 'taxas_custo_base', 'cav_custo_base', 'base_iva_normal', 'base_iva_reduzido', 'total_iva_normal', 'total_iva_reduzido', 'total_fatura'], 0);

    // Calcula os limiares de IVA com base na proporção do consumo total do período
    $totais_consumo = $dados_periodo['totais'];
    $consumo_total_periodo = $totais_consumo['total_kwh'] ?? 0;
    $consumo_vazio_periodo = $totais_consumo['consumo_vazio'] ?? 0;
    $consumo_fora_vazio_periodo = ($totais_consumo['consumo_cheia'] ?? 0) + ($totais_consumo['consumo_ponta'] ?? 0);

    $proporcao_vazio = ($consumo_total_periodo > 0) ? $consumo_vazio_periodo / $consumo_total_periodo : 0;
    $proporcao_fora_vazio = ($consumo_total_periodo > 0) ? $consumo_fora_vazio_periodo / $consumo_total_periodo : 0;

    $limiar_iva_reduzido_vazio = LIMITE_KWH_IVA_REDUZIDO * $proporcao_vazio;
    $limiar_iva_reduzido_fora_vazio = LIMITE_KWH_IVA_REDUZIDO * $proporcao_fora_vazio;



    foreach ($dados_por_dia as $dia => $dados_dia) {
        $consumo_vazio_dia = $dados_dia['consumo_vazio'] ?? 0;
        $consumo_fora_vazio_dia = ($dados_dia['consumo_cheia'] ?? 0) + ($dados_dia['consumo_ponta'] ?? 0);
        $consumo_dia = $consumo_vazio_dia + $consumo_fora_vazio_dia;
        
        $custo_vazio_dia = $dados_dia['custos_vazio'][$tarifa_contratada['id']] ?? 0;
        $custo_fora_vazio_dia = $dados_dia['custos_fora_vazio'][$tarifa_contratada['id']] ?? 0;
        $custo_energia_dia = $custo_vazio_dia + $custo_fora_vazio_dia;

        $kwh_vazio_antes_dia = $consumo_vazio_acumulado;
        $kwh_fora_vazio_antes_dia = $consumo_fora_vazio_acumulado;
        
        $consumo_acumulado += $consumo_dia;
        $consumo_vazio_acumulado += $consumo_vazio_dia;
        $consumo_fora_vazio_acumulado += $consumo_fora_vazio_dia;

        $base_iva_energia_reduzido_dia = 0;
        $base_iva_energia_normal_dia = 0;

        if ($tarifa_contratada['modalidade'] === 'simples') {
            $kwh_antes_dia = $kwh_vazio_antes_dia + $kwh_fora_vazio_antes_dia;
            if ($kwh_antes_dia < LIMITE_KWH_IVA_REDUZIDO) {
                $kwh_para_iva_reduzido = min($consumo_dia, LIMITE_KWH_IVA_REDUZIDO - $kwh_antes_dia);
                $kwh_para_iva_normal = $consumo_dia - $kwh_para_iva_reduzido;
                if ($consumo_dia > 0) {
                    $base_iva_energia_reduzido_dia = ($kwh_para_iva_reduzido / $consumo_dia) * $custo_energia_dia;
                    $base_iva_energia_normal_dia = ($kwh_para_iva_normal / $consumo_dia) * $custo_energia_dia;
                }
            } else {
                $base_iva_energia_normal_dia = $custo_energia_dia;
            }
        } else {
            // IVA para consumo em Vazio
            if ($consumo_vazio_dia > 0) {
                $preco_medio_vazio = $custo_vazio_dia / $consumo_vazio_dia;
                $kwh_restante_reduzido_vazio = max(0, $limiar_iva_reduzido_vazio - $kwh_vazio_antes_dia);
                $kwh_aplicar_reduzido_vazio = min($consumo_vazio_dia, $kwh_restante_reduzido_vazio);
                $kwh_aplicar_normal_vazio = $consumo_vazio_dia - $kwh_aplicar_reduzido_vazio;
                
                $base_iva_energia_reduzido_dia += $kwh_aplicar_reduzido_vazio * $preco_medio_vazio;
                $base_iva_energia_normal_dia += $kwh_aplicar_normal_vazio * $preco_medio_vazio;
            }

            // IVA para consumo Fora de Vazio
            if ($consumo_fora_vazio_dia > 0) {
                $preco_medio_fora_vazio = $custo_fora_vazio_dia / $consumo_fora_vazio_dia;
                $kwh_restante_reduzido_fora_vazio = max(0, $limiar_iva_reduzido_fora_vazio - $kwh_fora_vazio_antes_dia);
                $kwh_aplicar_reduzido_fora_vazio = min($consumo_fora_vazio_dia, $kwh_restante_reduzido_fora_vazio);
                $kwh_aplicar_normal_fora_vazio = $consumo_fora_vazio_dia - $kwh_aplicar_reduzido_fora_vazio;

                $base_iva_energia_reduzido_dia += $kwh_aplicar_reduzido_fora_vazio * $preco_medio_fora_vazio;
                $base_iva_energia_normal_dia += $kwh_aplicar_normal_fora_vazio * $preco_medio_fora_vazio;
            }
        }

        $custo_potencia_dia = $tarifa_contratada['custo_potencia_diario'];
        $custo_cav_dia = CAV_VALOR_DIARIO;
        $custo_ts_dia = $consumo_dia * TAXA_SOCIAL_VALOR_KWH;
        $custo_iece_dia = $consumo_dia * IECE_VALOR_KWH;

        $base_iva_reduzido_dia = $custo_cav_dia + $base_iva_energia_reduzido_dia;
        $base_iva_normal_dia = $custo_potencia_dia + $custo_ts_dia + $custo_iece_dia + $base_iva_energia_normal_dia;

        $iva_reduzido_dia = $base_iva_reduzido_dia * IVA_REDUZIDO;
        $iva_normal_dia = $base_iva_normal_dia * IVA_NORMAL;

        $total_dia = $base_iva_reduzido_dia + $base_iva_normal_dia + $iva_reduzido_dia + $iva_normal_dia;
        $total_acumulado += $total_dia;

        $dados_reais_dia_a_dia[] = [
            'dia' => (new DateTime($dia))->format('d/m'),
            'consumo_vazio_dia' => $consumo_vazio_dia,
            'consumo_fora_vazio_dia' => $consumo_fora_vazio_dia,
            'consumo_dia' => $consumo_dia,
            'consumo_acumulado' => $consumo_acumulado,
            'consumo_vazio_acumulado' => $consumo_vazio_acumulado,
            'consumo_fora_vazio_acumulado' => $consumo_fora_vazio_acumulado,
            'custo_vazio_dia' => $custo_vazio_dia,
            'custo_fora_vazio_dia' => $custo_fora_vazio_dia,
            'base_iva_reduzido_dia' => $base_iva_reduzido_dia,
            'base_iva_normal_dia' => $base_iva_normal_dia,
            'total_dia' => $total_dia,
            'total_acumulado' => $total_acumulado
        ];

        $totais_reais['energia_custo_base'] += $custo_energia_dia;
        $totais_reais['potencia_custo_base'] += $custo_potencia_dia;
        $totais_reais['taxas_custo_base'] += $custo_ts_dia + $custo_iece_dia;
        $totais_reais['cav_custo_base'] += $custo_cav_dia;
        $totais_reais['base_iva_reduzido'] += $base_iva_reduzido_dia;
        $totais_reais['base_iva_normal'] += $base_iva_normal_dia;
        $totais_reais['total_iva_reduzido'] += $iva_reduzido_dia;
        $totais_reais['total_iva_normal'] += $iva_normal_dia;
        $totais_reais['total_fatura'] += $total_dia;
    }

    // 4. Lógica de projeção
    $dados_projetados_dia_a_dia = [];
    $totais_projetados = $totais_reais;

    if ($is_current_period && !empty($dados_por_dia)) {

        // Para a projeção, não consideramos o dia de hoje, pois está incompleto.
        $dados_para_projecao = $dados_por_dia;
        $hoje_str = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
        if (isset($dados_para_projecao[$hoje_str])) {
            unset($dados_para_projecao[$hoje_str]);
        }

        // Se, após remover o dia de hoje, não houver dados para a projeção, usa os dados de hoje como base.
        if (empty($dados_para_projecao) && isset($dados_por_dia[$hoje_str])) {
            $dados_para_projecao = [$hoje_str => $dados_por_dia[$hoje_str]];
        }
        
        $consumos_agregados = ['uteis' => [], 'sabado' => [], 'domingo' => []];
        $dias_contados = ['uteis' => 0, 'sabado' => 0, 'domingo' => 0];
        $metricas = ['consumo_vazio', 'consumo_cheia', 'consumo_ponta', 'custo_vazio', 'custo_fora_vazio'];
        $id = $tarifa_contratada['id'];

        // Usamos os dados filtrados (sem hoje) para calcular as médias
        foreach ($dados_para_projecao as $dia => $dados) {
            $data = new DateTime($dia);
            $dia_semana = (int)$data->format('N');
            $tipo_dia = ($dia_semana === 6) ? 'sabado' : (($dia_semana === 7) ? 'domingo' : 'uteis');
            $dias_contados[$tipo_dia]++;
            foreach($metricas as $metrica) {
                if (!isset($consumos_agregados[$tipo_dia][$metrica])) $consumos_agregados[$tipo_dia][$metrica] = 0;
                if ($metrica === 'custo_vazio') {
                    $consumos_agregados[$tipo_dia][$metrica] += $dados['custos_vazio'][$id] ?? 0;
                } elseif ($metrica === 'custo_fora_vazio') {
                    $consumos_agregados[$tipo_dia][$metrica] += $dados['custos_fora_vazio'][$id] ?? 0;
                } else {
                    $consumos_agregados[$tipo_dia][$metrica] += $dados[$metrica] ?? 0;
                }
            }
        }

        $medias_diarias = [];
        foreach ($dias_contados as $tipo => $contagem) {
            if ($contagem > 0) {
                foreach ($consumos_agregados[$tipo] as $metrica => $valor_total) {
                    $medias_diarias[$tipo][$metrica] = $valor_total / $contagem;
                }
            }
        }

        // Fallback com média global
        $medias_globais = [];
        $total_dias_contados = array_sum($dias_contados);
        if ($total_dias_contados > 0) {
            foreach ($metricas as $metrica) {
                $valor_total_global = 0;
                foreach (['uteis', 'sabado', 'domingo'] as $tipo) {
                    $valor_total_global += $consumos_agregados[$tipo][$metrica] ?? 0;
                }
                $medias_globais[$metrica] = $valor_total_global / $total_dias_contados;
            }
        } elseif (isset($dados_por_dia[$hoje_str])) { // Se não há dias passados, usa o dia de hoje
             $dados_hoje = $dados_por_dia[$hoje_str];
             foreach($metricas as $metrica) {
                if ($metrica === 'custo_vazio') {
                    $medias_globais[$metrica] = $dados_hoje['custos_vazio'][$id] ?? 0;
                } elseif ($metrica === 'custo_fora_vazio') {
                    $medias_globais[$metrica] = $dados_hoje['custos_fora_vazio'][$id] ?? 0;
                }
                else {
                    $medias_globais[$metrica] = $dados_hoje[$metrica] ?? 0;
                }
            }
        }

        $medias_diarias['uteis'] = $medias_diarias['uteis'] ?? $medias_globais;
        $medias_diarias['sabado'] = $medias_diarias['sabado'] ?? $medias_globais;
        $medias_diarias['domingo'] = $medias_diarias['domingo'] ?? $medias_globais;

        $hoje = new DateTime('now', new DateTimeZone('UTC'));
        $amanha = (clone $hoje)->modify('+1 day')->setTime(0, 0, 0);
        if ($amanha <= $data_fim_periodo) {
            $periodo_futuro = new DatePeriod($amanha, new DateInterval('P1D'), (clone $data_fim_periodo)->modify('+1 day'));
            foreach ($periodo_futuro as $data) {
                $dia_semana = (int)$data->format('N');
                $tipo_dia = ($dia_semana === 6) ? 'sabado' : (($dia_semana === 7) ? 'domingo' : 'uteis');
                $media_dia = $medias_diarias[$tipo_dia];

                $consumo_vazio_dia = $media_dia['consumo_vazio'] ?? 0;
                $consumo_fora_vazio_dia = ($media_dia['consumo_cheia'] ?? 0) + ($media_dia['consumo_ponta'] ?? 0);
                $consumo_dia = $consumo_vazio_dia + $consumo_fora_vazio_dia;
                $custo_vazio_dia = $media_dia['custo_vazio'] ?? 0;
                $custo_fora_vazio_dia = $media_dia['custo_fora_vazio'] ?? 0;
                $custo_energia_dia = $custo_vazio_dia + $custo_fora_vazio_dia;

                $kwh_antes_dia = $consumo_acumulado;
                $consumo_acumulado += $consumo_dia;
                $consumo_vazio_acumulado += $consumo_vazio_dia;
                $consumo_fora_vazio_acumulado += $consumo_fora_vazio_dia;

                $base_iva_energia_reduzido_dia = 0;
                $base_iva_energia_normal_dia = 0;
                if ($kwh_antes_dia < LIMITE_KWH_IVA_REDUZIDO) {
                    $kwh_para_iva_reduzido = min($consumo_dia, LIMITE_KWH_IVA_REDUZIDO - $kwh_antes_dia);
                    $kwh_para_iva_normal = $consumo_dia - $kwh_para_iva_reduzido;
                    if ($consumo_dia > 0) {
                        $base_iva_energia_reduzido_dia = ($kwh_para_iva_reduzido / $consumo_dia) * $custo_energia_dia;
                        $base_iva_energia_normal_dia = ($kwh_para_iva_normal / $consumo_dia) * $custo_energia_dia;
                    }
                } else {
                    $base_iva_energia_normal_dia = $custo_energia_dia;
                }

                $custo_potencia_dia = $tarifa_contratada['custo_potencia_diario'];
                $custo_cav_dia = CAV_VALOR_DIARIO;
                $custo_ts_dia = $consumo_dia * TAXA_SOCIAL_VALOR_KWH;
                $custo_iece_dia = $consumo_dia * IECE_VALOR_KWH;

                $base_iva_reduzido_dia = $custo_cav_dia + $base_iva_energia_reduzido_dia;
                $base_iva_normal_dia = $custo_potencia_dia + $custo_ts_dia + $custo_iece_dia + $base_iva_energia_normal_dia;

                $iva_reduzido_dia = $base_iva_reduzido_dia * IVA_REDUZIDO;
                $iva_normal_dia = $base_iva_normal_dia * IVA_NORMAL;

                $total_dia = $base_iva_reduzido_dia + $base_iva_normal_dia + $iva_reduzido_dia + $iva_normal_dia;
                $total_acumulado += $total_dia;

                $dados_projetados_dia_a_dia[] = [
                    'dia' => $data->format('d/m'),
                    'consumo_vazio_dia' => $consumo_vazio_dia,
                    'consumo_fora_vazio_dia' => $consumo_fora_vazio_dia,
                    'consumo_dia' => $consumo_dia,
                    'consumo_acumulado' => $consumo_acumulado,
                    'consumo_vazio_acumulado' => $consumo_vazio_acumulado,
                    'consumo_fora_vazio_acumulado' => $consumo_fora_vazio_acumulado,
                    'custo_vazio_dia' => $custo_vazio_dia,
                    'custo_fora_vazio_dia' => $custo_fora_vazio_dia,
                    'base_iva_reduzido_dia' => $base_iva_reduzido_dia,
                    'base_iva_normal_dia' => $base_iva_normal_dia,
                    'total_dia' => $total_dia,
                    'total_acumulado' => $total_acumulado
                ];

                $totais_projetados['energia_custo_base'] += $custo_energia_dia;
                $totais_projetados['potencia_custo_base'] += $custo_potencia_dia;
                $totais_projetados['taxas_custo_base'] += $custo_ts_dia + $custo_iece_dia;
                $totais_projetados['cav_custo_base'] += $custo_cav_dia;
                $totais_projetados['base_iva_reduzido'] += $base_iva_reduzido_dia;
                $totais_projetados['base_iva_normal'] += $base_iva_normal_dia;
                $totais_projetados['total_iva_reduzido'] += $iva_reduzido_dia;
                $totais_projetados['total_iva_normal'] += $iva_normal_dia;
                $totais_projetados['total_fatura'] += $total_dia;
            }
        }
    }

    return [
        'nome_tarifa_contratada' => $tarifa_contratada['nome_tarifa'],
        'titulo_pagina' => $titulo_pagina,
        'is_current_period' => $is_current_period,
        'dados_reais_dia_a_dia' => $dados_reais_dia_a_dia,
        'dados_projetados_dia_a_dia' => $dados_projetados_dia_a_dia,
        'totais_reais' => $totais_reais,
        'totais_projetados' => $totais_projetados,
    ];
}