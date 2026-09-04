<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (!$actor['actor_id'] && isset($_GET['actor_id'])) {
    $actor = [
        'actor_id' => (int)$_GET['actor_id'],
        'actor_nome' => trim((string)($_GET['actor_nome'] ?? '')),
        'actor_login' => trim((string)($_GET['actor_login'] ?? '')),
        'role' => trim((string)($_GET['role'] ?? '')),
    ];
}

if ($method !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

if (!actorHasPermission($pdo, $actor, 'SISTEMA_VER_LOGS')) {
    denyAndAudit($pdo, $actor, 'SISTEMA_VER_LOGS', 'monitoramento', null, ['acao' => 'consultar_monitoramento']);
}

$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

$stmt = $pdo->prepare("SELECT * FROM action_log ORDER BY created_at DESC LIMIT " . $limit);
$stmt->execute();
$actionLogs = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM api_request_log ORDER BY created_at DESC LIMIT " . $limit);
$stmt->execute();
$apiLogs = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM error_events ORDER BY created_at DESC LIMIT " . $limit);
$stmt->execute();
$errorLogs = $stmt->fetchAll();

$stmt = $pdo->query("SELECT
    (SELECT COUNT(*) FROM action_log) AS total_action_log,
    (SELECT COUNT(*) FROM api_request_log) AS total_api_request_log,
    (SELECT COUNT(*) FROM error_events) AS total_error_events");
$totais = $stmt->fetch() ?: [];

jsonResponse([
    'success' => true,
    'totais' => $totais,
    'action_log' => $actionLogs,
    'api_request_log' => $apiLogs,
    'error_events' => $errorLogs,
]);
