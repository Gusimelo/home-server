<?php
// Ficheiro: config.php
// Contém todas as configurações e credenciais da aplicação.

// 1. Configuração da Base de Dados
define('DB_HOST', 'localhost');
define('DB_USER', 'hass');
define('DB_PASS', 'fcfheetl');
define('DB_NAME', 'energia');

// 2. Configuração do Home Assistant
define('HA_URL', 'http://10.0.3.11:8123');
define('HA_TOKEN', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiI4MzY0MjBjODBjNzk0MGVjYWUxNDQzZjY5MDE5NjZmMiIsImlhdCI6MTc2MDUyOTUzNSwiZXhwIjoyMDc1ODg5NTM1fQ.Mi_XzQWn6aMqngPtGMuG36JMalUiqxMC6UnfpAxCaBA');

// 3. Entidades (Sensores) do Home Assistant
// Colocar aqui os IDs exatos dos seus sensores de energia acumulada
define('HA_ENTITY_VAZIO', 'sensor.contador_total_vazio');
define('HA_ENTITY_CHEIA', 'sensor.contador_total_cheia');
define('HA_ENTITY_PONTA', 'sensor.contador_total_ponta');

// Sensor para o preço do tarifário dinâmico (ex: Coopérnico)
define('HA_ENTITY_PRECO_DINAMICO', 'sensor.coopernico_kwh'); // Substitua pelo ID exato do seu sensor

// --- CONSTANTES DE FATURAÇÃO ---
define('IVA_NORMAL', 0.23);
define('IVA_REDUZIDO', 0.06);
define('LIMITE_KWH_IVA_REDUZIDO', 200);
define('TAXA_SOCIAL_VALOR_KWH', 0.001657);
define('CAV_VALOR_DIARIO', 0.093634);
define('IECE_VALOR_KWH', 0.001000);

// Dia do mês em que o ciclo de faturação começa (ex: 16)
define('DIA_INICIO_CICLO', 16);

// --- IDENTIFICAÇÃO DO TARIFÁRIO ATUAL (para o comparador) ---
// Substitua pelos valores exatos do seu contrato para que a sua oferta seja destacada
define('MEU_COMERCIALIZADOR', 'EDPC'); // Nome exato do seu comercializador
define('MINHA_OFERTA', 'Eletricidade DD+FE - Digital 2025'); // Nome exato da sua oferta/plano
define('MEU_TIPO_TARIFA', 'Bi-Horario'); // O seu tipo de tarifa: 'Simples' ou 'Bi-Horario'

?>
