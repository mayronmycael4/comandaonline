<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/time_contract.php';
$companyTimezone = comanda_company_timezone($pdo);
$today = (new DateTimeImmutable('now', comanda_timezone($companyTimezone)))->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$actor = extractAuditActor([]);
if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'SISTEMA_VER_LOGS')) {
    denyAndAudit($pdo, $actor, 'SISTEMA_VER_LOGS', 'action_log', null, ['acao' => 'consultar_auditoria']);
}

$inicio = trim((string)($_GET['inicio'] ?? $today));
$fim = trim((string)($_GET['fim'] ?? $today));
try {
    [$inicioDt, $fimDt] = comanda_report_epoch_bounds($inicio, $fim, $companyTimezone);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}

$acao = trim((string)($_GET['acao'] ?? ''));
$entidade = trim((string)($_GET['entidade'] ?? ''));
$actorId = (int)($_GET['actor_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$where = ['UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) < ?'];
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

$sql = 'SELECT id, actor_id, actor_nome, actor_login, acao, entidade, entidade_id, detalhes, ip_address, user_agent, UNIX_TIMESTAMP(created_at) AS created_at'
    . $sqlBase
    . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$row) $row['created_at'] = comanda_epoch_iso($row['created_at']);
unset($row);

jsonResponse([
    'timezone' => $companyTimezone,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
    'registros' => $rows
]);
