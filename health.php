<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse([
        'status' => 'error',
        'error_code' => 'METHOD_NOT_ALLOWED',
        'message' => 'Metodo nao permitido'
    ], 405);
}

$dbStatus = 'ok';
$dbError = null;
try {
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $dbStatus = 'fail';
    $dbError = $e->getMessage();
}

$schemaVersion = getCurrentSchemaVersion($pdo);

$response = [
    'status' => $dbStatus === 'ok' ? 'ok' : 'degraded',
    'system_version' => getSystemVersion(),
    'schema_version' => $schemaVersion,
    'database' => [
        'status' => $dbStatus
    ],
    'server_time' => date('c')
];

if ($dbError) {
    $response['database']['message'] = $dbError;
}

jsonResponse($response, $dbStatus === 'ok' ? 200 : 503);
