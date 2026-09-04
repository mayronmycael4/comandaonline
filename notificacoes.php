<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $funcionarioId = isset($_GET['funcionario_id']) ? (int)$_GET['funcionario_id'] : 0;
    $status = trim((string)($_GET['status'] ?? 'pendente'));
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
    if ($funcionarioId <= 0) {
        jsonResponse(['error' => 'funcionario_id obrigatorio'], 400);
    }
    if (!in_array($status, ['pendente', 'lida', 'todos'], true)) {
        $status = 'pendente';
    }
    if ($limit < 1 || $limit > 100) {
        $limit = 15;
    }

    $sql = "SELECT id, funcionario_id, tipo, titulo, mensagem, payload, status, lida_em, created_at
            FROM notificacoes_fila
            WHERE funcionario_id = ?";
    $params = [$funcionarioId];
    if ($status !== 'todos') {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY created_at DESC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        $row['payload'] = is_array($payload) ? $payload : null;
    }

    jsonResponse($rows);
}

if ($method === 'PUT') {
    $data = getJsonInput();
    $notifId = isset($data['id']) ? (int)$data['id'] : 0;
    $funcionarioId = isset($data['funcionario_id']) ? (int)$data['funcionario_id'] : 0;
    $actor = extractAuditActor($data);

    if ($notifId <= 0 || $funcionarioId <= 0) {
        jsonResponse(['error' => 'id e funcionario_id obrigatorios'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, funcionario_id, status FROM notificacoes_fila WHERE id = ? LIMIT 1");
    $stmt->execute([$notifId]);
    $notif = $stmt->fetch();

    if (!$notif) {
        jsonResponse(['error' => 'Notificacao nao encontrada'], 404);
    }

    if ((int)$notif['funcionario_id'] !== $funcionarioId) {
        denyAndAudit($pdo, $actor, 'comandas', 'notificacoes_fila', $notifId, [
            'acao' => 'marcar_notificacao_lida',
            'funcionario_id_informado' => $funcionarioId,
            'funcionario_id_notificacao' => (int)$notif['funcionario_id']
        ]);
    }

    $stmt = $pdo->prepare("UPDATE notificacoes_fila SET status = 'lida', lida_em = NOW() WHERE id = ?");
    $stmt->execute([$notifId]);

    auditLog($pdo, 'notificacao_lida', 'notificacoes_fila', $notifId, [
        'funcionario_id' => $funcionarioId
    ], $actor);

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
