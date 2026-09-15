<?php
function getAllowedOrigin() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . getAllowedOrigin());
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config_helper.php';

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$config = null;

$requiredTables = ['empresa', 'funcionarios', 'clientes', 'produtos', 'estoque', 'comandas', 'comanda_itens'];

try {
    $connHost = comanda_connect_db(['with_db' => false, 'timeout' => 6]);
    $pdoHost = $connHost['pdo'];
    $config = $connHost['config'];

    $stmt = $pdoHost->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
    $stmt->execute([$config['dbname']]);
    $dbExists = (bool) $stmt->fetchColumn();

    if (!$dbExists) {
        jsonResponse([
            'success' => true,
            'allow_setup' => true,
            'reason' => 'database_missing',
            'db_exists' => false,
            'tables_ready' => false,
            'missing_tables' => $requiredTables,
            'company_exists' => false,
            'admin_exists' => false
        ]);
    }

    $connDb = comanda_connect_db(['with_db' => true, 'timeout' => 6]);
    $pdoDb = $connDb['pdo'];

    $tables = $pdoDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $missing = array_values(array_diff($requiredTables, $tables));
    $tablesReady = empty($missing);

    if (!$tablesReady) {
        jsonResponse([
            'success' => true,
            'allow_setup' => true,
            'reason' => 'schema_incomplete',
            'db_exists' => true,
            'tables_ready' => false,
            'missing_tables' => $missing,
            'company_exists' => false,
            'admin_exists' => false
        ]);
    }

    $companyExists = false;
    $adminExists = false;

    try {
        $companyExists = (int) $pdoDb->query('SELECT COUNT(*) FROM empresa')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $companyExists = false;
    }

    try {
        $adminExists = (int) $pdoDb->query('SELECT COUNT(*) FROM funcionarios WHERE is_admin = 1 AND is_active = 1')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $adminExists = false;
    }

    jsonResponse([
        'success' => true,
        'allow_setup' => false,
        'reason' => 'initialized',
        'db_exists' => true,
        'tables_ready' => true,
        'missing_tables' => [],
        'company_exists' => $companyExists,
        'admin_exists' => $adminExists
    ]);

} catch (Throwable $e) {
    // Falha em status nunca deve abrir setup por seguranca.
    jsonResponse([
        'success' => false,
        'allow_setup' => false,
        'reason' => 'status_error',
        'error' => $e->getMessage()
    ], 500);
}
