<?php
// --- CONFIGURAÇÃO GERAL DA APLICAÇÃO ---

// 1. DEFINIÇÃO DA BASE DE DADOS
$servername = "localhost";
$username = "hass";
$password = "fcfheetl";
$dbname = "expenses_pessoal";

// 2. PESSOAS QUE DIVIDEM AS DESPESAS
$cost_sharers = ['Gustavo', 'Filipa'];

// 3. CORES PARA CADA PESSOA (usado para realçar linhas nas tabelas)
// As cores devem ser classes de background do Tailwind CSS (ex: bg-blue-50, bg-green-50, etc.)
$person_colors = [
    'Gustavo' => 'transparent',
    'Filipa' => 'transparent',
];

// 4. CORES PARA OS AVATARES (Círculo com inicial)
$person_avatar_colors = [
    'Gustavo' => 'bg-blue-500 text-white',
    'Filipa' => 'bg-pink-500 text-white',
];

// 5. EMAILS PARA NOTIFICAÇÕES (NOVO)
$person_emails = [
    'Gustavo' => 'gustavoamelo@gmail.com',
    'Filipa' => 'filipamr@hotmail.com',
];


// 6. CONFIGURAÇÃO DE EMAIL SMTP (NOVO)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USERNAME', 'gustavoamelo@gmail.com');
define('SMTP_PASSWORD', 'xmmu lysk qegm uifw'); // Colar a Senha de App aqui!
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // Protocolo de encriptação
define('SMTP_FROM_EMAIL', 'gustavoamelo@gmail.com'); // O email que aparecerá como remetente
define('SMTP_FROM_NAME', 'Gestor de Despesas'); // O nome que aparecerá como remetente


?>