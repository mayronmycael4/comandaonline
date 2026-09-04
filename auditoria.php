<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$actor = extractAuditActor([]);
if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'SISTEMA_VER_LOGS')) {
    denyAndAudit($pdo, $actor, 'SISTEMA_VER_LOGS', 'action_log', null, ['acao' => 'consultar_auditoria']);
}

$inicio = trim((string)($_GET['inicio'] ?? date('Y-m-d')));
$fim = trim((string)($_GET['fim'] ?? date('Y-m-d')));
$inicioDt = $inicio . ' 00:00:00';
$fimDt = $fim . ' 23:59:59';

$acao = trim((string)($_GET['acao'] ?? ''));
$entidade = trim((string)($_GET['entidade'] ?? ''));
$actorId = (int)($_GET['actor_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$where = ['created_at BETWEEN ? AND ?'];
$params = [$inicioDt, $fimDt];

if ($acao !== '') {
    $where[] = 'acao = ?';
    $params[] = $acao;
}
if ($entidade !== '') {
    $where[] = 'entidade = ?';
    $params[] = $entidade;
}
if ($actorId > 0) {
    $where[] = 'actor_id = ?';
    $params[] = $actorId;
}
if ($q !== '') {
    $where[] = '(actor_nome LIKE ? OR actor_login LIKE ? OR acao LIKE ? OR entidade LIKE ? OR entidade_id LIKE ? OR JSON_EXTRACT(detalhes, "$") LIKE ?)';
    $term = '%' . $q . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sqlBase = ' FROM action_log WHERE ' . implode(' AND ', $where);

$stmtTotal = $pdo->prepare('SELECT COUNT(*)' . $sqlBase);
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();

$sql = 'SELECT id, actor_id, actor_nome, actor_login, acao, entidade, entidade_id, detalhes, ip_address, user_agent, created_at'
    . $sqlBase
    . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

jsonResponse([
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
    'registros' => $rows
]);
