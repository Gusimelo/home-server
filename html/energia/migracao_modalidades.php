<?php
// Ficheiro: migracao_modalidades.php
// ATENÇÃO: Este é um script de execução única para separar tarifas híbridas em modalidades distintas.

require_once 'config.php';

echo "--- Início do Script de Migração de Modalidades ---\n";

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("ERRO: Falha na ligação à base de dados: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// Encontrar tarifas antigas que ainda não foram migradas (sem modalidade definida)
$sql_find_old = "SELECT * FROM tarifas WHERE modalidade IS NULL OR modalidade = ''";
$result_old = $mysqli->query($sql_find_old);

if ($result_old->num_rows === 0) {
    die("Nenhuma tarifa antiga para migrar. O script já foi executado ou não é necessário.\n");
}

$mysqli->begin_transaction();

try {
    $stmt_insert = $mysqli->prepare(
        "INSERT INTO tarifas (nome_tarifa, modalidade, preco_vazio, preco_cheia, preco_ponta, preco_simples, custo_potencia_diario, data_inicio, data_fim) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    while ($tarifa_antiga = $result_old->fetch_assoc()) {
        $id_antigo = $tarifa_antiga['id'];
        $nome_base = $tarifa_antiga['nome_tarifa'];
        echo "A migrar tarifa antiga #$id_antigo: '$nome_base'\n";

        // 1. Criar a nova tarifa Bi-Horária
        $nome_bh = "$nome_base (Bi-Horário)";
        $modalidade_bh = 'bi-horario';
        $stmt_insert->bind_param(
            "ssddddsss",
            $nome_bh,
            $modalidade_bh,
            $tarifa_antiga['preco_vazio'],
            $tarifa_antiga['preco_cheia'],
            $tarifa_antiga['preco_ponta'],
            $tarifa_antiga['preco_simples'], // Mantém o valor, mas não será usado
            $tarifa_antiga['custo_potencia_diario'], // Usa o valor da coluna unificada
            $tarifa_antiga['data_inicio'],
            $tarifa_antiga['data_fim']
        );
        $stmt_insert->execute();
        echo " -> Criada nova tarifa: '$nome_bh'\n";

        // 2. Criar a nova tarifa Simples
        $nome_s = "$nome_base (Simples)";
        $modalidade_s = 'simples';
        $stmt_insert->bind_param(
            "ssddddsss",
            $nome_s,
            $modalidade_s,
            $tarifa_antiga['preco_vazio'], // Mantém, mas não usado
            $tarifa_antiga['preco_cheia'], // Mantém, mas não usado
            $tarifa_antiga['preco_ponta'], // Mantém, mas não usado
            $tarifa_antiga['preco_simples'],
            $tarifa_antiga['custo_potencia_diario'], // Usa o valor da coluna unificada
            $tarifa_antiga['data_inicio'],
            $tarifa_antiga['data_fim']
        );
        $stmt_insert->execute();
        echo " -> Criada nova tarifa: '$nome_s'\n";

        // 3. Apagar a tarifa antiga
        $mysqli->query("DELETE FROM tarifas WHERE id = $id_antigo");
        echo " -> Tarifa antiga #$id_antigo removida.\n";
    }

    $mysqli->commit();
    echo "\nSUCESSO: Migração de modalidades concluída!\n";

} catch (Exception $e) {
    $mysqli->rollback();
    die("\nERRO: A migração de modalidades falhou. Nenhuma alteração foi guardada. Detalhe: " . $e->getMessage() . "\n");
}

$mysqli->close();
?>