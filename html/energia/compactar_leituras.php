<?php
// Ficheiro: compactar_leituras.php
// ATENÇÃO: Este é um script de execução única para compactar os dados existentes de 5 minutos para 1 hora.
// FAÇA UM BACKUP COMPLETO DA SUA BASE DE DADOS ANTES DE EXECUTAR ESTE SCRIPT.

require_once 'config.php';

echo "--- Início do Script de Compactação de Leituras ---\n\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// Aumenta o tempo limite para operações longas na base de dados
set_time_limit(600); // 10 minutos

try {
    echo "Passo 1: A criar tabela temporária 'leituras_energia_horaria'...\n";
    // Cria uma tabela temporária com a estrutura correta
    $sql_create = "
        CREATE TABLE leituras_energia_horaria (
          `id` bigint(20) NOT NULL AUTO_INCREMENT,
          `data_hora` datetime NOT NULL DEFAULT current_timestamp(),
          `consumo_vazio` decimal(10,5) NOT NULL,
          `consumo_cheia` decimal(10,5) NOT NULL,
          `consumo_ponta` decimal(10,5) NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `data_hora_unique` (`data_hora`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    if (!$mysqli->query($sql_create)) {
        throw new Exception("Falha ao criar a tabela temporária: " . $mysqli->error);
    }
    echo "   -> Tabela temporária criada com sucesso.\n\n";

    echo "Passo 2: A agregar dados de 5 minutos para 1 hora...\n";
    $sql_aggregate = "
        INSERT INTO leituras_energia_horaria (data_hora, consumo_vazio, consumo_cheia, consumo_ponta)
        SELECT
            STR_TO_DATE(DATE_FORMAT(data_hora, '%Y-%m-%d %H:00:00'), '%Y-%m-%d %H:%i:%s') as hora_agregada,
            SUM(consumo_vazio) as total_consumo_vazio,
            SUM(consumo_cheia) as total_consumo_cheia,
            SUM(consumo_ponta) as total_consumo_ponta
        FROM
            leituras_energia
        GROUP BY
            hora_agregada
        ORDER BY
            hora_agregada;
    ";
    if (!$mysqli->query($sql_aggregate)) {
        throw new Exception("Falha ao agregar e inserir os dados: " . $mysqli->error);
    }
    $registos_agregados = $mysqli->affected_rows;
    echo "   -> $registos_agregados registos horários criados com sucesso.\n\n";

    echo "Passo 3: A substituir a tabela antiga pela nova...\n";
    if (!$mysqli->query("RENAME TABLE leituras_energia TO leituras_energia_old_5min;")) {
        throw new Exception("Falha ao renomear a tabela original: " . $mysqli->error);
    }
    echo "   -> Tabela original renomeada para 'leituras_energia_old_5min' (backup).\n";

    if (!$mysqli->query("RENAME TABLE leituras_energia_horaria TO leituras_energia;")) {
        throw new Exception("Falha ao renomear a nova tabela: " . $mysqli->error);
    }
    echo "   -> Nova tabela compactada agora é a 'leituras_energia' principal.\n\n";

    echo "SUCESSO! A compactação foi concluída.\n";
    echo "A tabela original foi guardada como 'leituras_energia_old_5min'.\n";
    echo "Pode apagá-la manualmente quando confirmar que tudo está correto: 'DROP TABLE leituras_energia_old_5min;'\n";
    echo "\nIMPORTANTE: Não se esqueça de alterar o seu cron job para executar o coletor apenas uma vez por hora (ex: '0 * * * *').\n";

} catch (Exception $e) {
    echo "\nERRO CRÍTICO: A operação foi interrompida. Detalhe: " . $e->getMessage() . "\n";
    echo "A tentar reverter alterações...\n";
    $mysqli->query("DROP TABLE IF EXISTS leituras_energia_horaria;"); // Limpa a tabela temporária se existir
    echo "Nenhuma alteração permanente foi feita na sua tabela principal 'leituras_energia'.\n";
}

$mysqli->close();
echo "\n--- Fim do Script de Compactação ---\n";
?>