<?php
// --- SCRIPT DE CRON PARA ENVIO DE LEMBRETES DE VENCIMENTO COM GMAIL SMTP (HTML) ---

// Importar as classes do PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Definir o fuso horário
date_default_timezone_set('Europe/Lisbon');

// Carregar o autoloader do Composer
require dirname(__FILE__).'/../../vendor/autoload.php';
// Incluir as configurações da aplicação
require_once 'config.php';

echo "--- Iniciando script de lembretes de vencimento (SMTP HTML) em " . date('Y-m-d H:i:s') . " ---\n";

if (!isset($person_emails) || empty($person_emails)) {
    die("O array de emails (\$person_emails) não está configurado em config.php. A terminar.\n");
}

// LIGAÇÃO À BASE DE DADOS
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Falha na ligação à base de dados: " . $conn->connect_error . "\n");
}
$conn->set_charset("utf8mb4");
echo "Ligação à base de dados estabelecida.\n";

// DETERMINAR A DATA DE AMANHÃ
$tomorrow = date('Y-m-d', strtotime('+1 day'));
echo "A procurar pagamentos pendentes para amanhã: " . $tomorrow . "\n";

// CONSULTAR A BASE DE DADOS
$sql = "SELECT person, description, amount FROM transactions WHERE status = 'pending' AND transaction_date = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $tomorrow);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Nenhum pagamento a vencer amanhã. A terminar.\n";
    $conn->close();
    exit;
}

echo "Encontrados " . $result->num_rows . " pagamentos.\n";

// AGRUPAR PAGAMENTOS POR PESSOA
$payments_by_person = [];
while ($row = $result->fetch_assoc()) {
    $payments_by_person[$row['person']][] = $row;
}
$stmt->close();
$conn->close();

// PROCESSAR E ENVIAR EMAILS
echo "A processar emails...\n";

foreach ($payments_by_person as $person => $payments) {
    echo "A processar pagamentos para: " . $person . "\n";

    if (isset($person_emails[$person]) && filter_var($person_emails[$person], FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer(true);

        try {
            // Configurações do Servidor SMTP
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';

            // Remetente e Destinatário
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($person_emails[$person], $person);

            // --- INÍCIO DAS ALTERAÇÕES ---

            // Conteúdo do Email
            $mail->isHTML(true); // Definir o formato do email como HTML
            $mail->Subject = "Lembrete: Contas a Vencer Amanhã (" . date('d/m/Y', strtotime($tomorrow)) . ")";
            
            // Construir as linhas da tabela de pagamentos
            $total_amount = 0;
            $table_rows_html = '';
            $table_rows_plain = '';
            foreach ($payments as $payment) {
                $amount_formatted = number_format($payment['amount'], 2, ',', ' ') . ' €';
                $table_rows_html .= '<tr>
                                        <td style="border-bottom: 1px solid #eee; padding: 8px;">' . htmlspecialchars($payment['description']) . '</td>
                                        <td style="border-bottom: 1px solid #eee; padding: 8px; text-align: right; font-family: \'Courier New\', Courier, monospace;">' . $amount_formatted . '</td>
                                    </tr>';
                $table_rows_plain .= "- " . $payment['description'] . ": " . $amount_formatted . "\n";
                $total_amount += $payment['amount'];
            }
            $total_amount_formatted = number_format($total_amount, 2, ',', ' ') . ' €';

            // Corpo do email em HTML
            $mail->Body = '
                <!DOCTYPE html>
                <html lang="pt">
                <head><meta charset="UTF-8"></head>
                <body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
                    <div style="max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                        <h2 style="color: #4f46e5; border-bottom: 2px solid #eee; padding-bottom: 10px;">Lembrete de Pagamentos</h2>
                        <p>Olá ' . htmlspecialchars($person) . ',</p>
                        <p>Este é um lembrete automático sobre os seguintes pagamentos que vencem <strong>amanhã, dia ' . date('d/m/Y', strtotime($tomorrow)) . '</strong>:</p>
                        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                            <thead>
                                <tr>
                                    <th style="border-bottom: 2px solid #ddd; padding: 8px; text-align: left; color: #555;">Descrição</th>
                                    <th style="border-bottom: 2px solid #ddd; padding: 8px; text-align: right; color: #555;">Valor</th>
                                </tr>
                            </thead>
                            <tbody>' . $table_rows_html . '</tbody>
                            <tfoot>
                                <tr>
                                    <td style="padding: 10px 8px 8px; font-weight: bold; text-align: right;">Total a Pagar:</td>
                                    <td style="padding: 10px 8px 8px; font-weight: bold; text-align: right; font-family: \'Courier New\', Courier, monospace; color: #d9534f;">' . $total_amount_formatted . '</td>
                                </tr>
                            </tfoot>
                        </table>
                        <p style="margin-top: 20px;">Cumprimentos,<br>O seu Gestor de Despesas</p>
                    </div>
                </body>
                </html>';

            // Corpo alternativo em texto simples (para clientes de email que não suportam HTML)
            $mail->AltBody = "Olá " . $person . ",\n\n" .
                             "Este é um lembrete automático sobre os seguintes pagamentos que vencem amanhã, dia " . date('d/m/Y', strtotime($tomorrow)) . ":\n\n" .
                             $table_rows_plain . "\n" .
                             "Total a pagar: " . $total_amount_formatted . "\n\n" .
                             "Cumprimentos,\nO seu Gestor de Despesas\n";

            // --- FIM DAS ALTERAÇÕES ---

            $mail->send();
            echo '-> Email enviado com sucesso para ' . $person . ' (' . $person_emails[$person] . ")\n";
        } catch (Exception $e) {
            echo "-> ERRO: Falha ao enviar email para " . $person . ". Detalhes: {$mail->ErrorInfo}\n";
        }
    } else {
        echo "-> AVISO: Nenhum email válido configurado para " . $person . ". Email não enviado.\n";
    }
}

echo "--- Script concluído em " . date('Y-m-d H:i:s') . " ---\n";
?>