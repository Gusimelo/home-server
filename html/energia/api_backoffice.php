<?php
// api_backoffice.php

require_once 'config.php';

$action = $_GET['action'] ?? null;

if ($action === 'import_omie') {
    $start_date = $_GET['start'] ?? null;
    $end_date = $_GET['end'] ?? null;

    if (!$start_date || !$end_date) {
        http_response_code(400);
        echo "ERRO: Data de início e de fim são obrigatórias.";
        exit;
    }

    // Headers para streaming da resposta
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    ob_end_flush(); // Termina qualquer buffer de saída existente

    // Caminho para o executável PHP e para o script
    $php_executable = 'php'; // Pode precisar de ser o caminho completo, ex: /usr/bin/php
    $script_path = __DIR__ . '/importador_omie_historico.php';

    // Construir o comando
    $command = sprintf(
        '%s %s %s %s 2>&1',
        escapeshellcmd($php_executable),
        escapeshellarg($script_path),
        escapeshellarg($start_date),
        escapeshellarg($end_date)
    );

    // Executar o comando usando proc_open para controlo de I/O
    $descriptorspec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr (redirecionado para stdout no comando com 2>&1)
    ];

    $process = proc_open($command, $descriptorspec, $pipes);

    if (is_resource($process)) {
        fclose($pipes[0]); // Não precisamos de enviar nada para o stdin

        // Ler a saída do script em tempo real e enviá-la para o cliente
        while ($line = fgets($pipes[1])) {
            echo $line;
            flush(); // Envia o output para o browser imediatamente
        }
        fclose($pipes[1]);
        proc_close($process);
    }
}
?>