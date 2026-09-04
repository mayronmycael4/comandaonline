<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if ($method === 'GET') {
    $clienteId = (int)($_GET['cliente_id'] ?? 0);
    if ($clienteId <= 0) jsonResponse(['error' => 'cliente_id obrigatorio'], 400);

    $stmt = $pdo->prepare('SELECT id, cliente_id, tipo_consentimento, aceito, origem, observacao, created_at FROM cliente_consentimento WHERE cliente_id = ? ORDER BY tipo_consentimento');
    $stmt->execute([$clienteId]);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST' || $method === 'PUT') {
    $clienteId = (int)($data['cliente_id'] ?? 0);
    $tipo = trim((string)($data['tipo_consentimento'] ?? 'marketing'));
    $aceito = !empty($data['aceito']) ? 1 : 0;
    if ($clienteId <= 0) jsonResponse(['error' => 'cliente_id obrigatorio'], 400);

    $stmt = $pdo->prepare('SELECT id FROM clientes WHERE id = ? LIMIT 1');
    $stmt->execute([$clienteId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'cliente nao encontrado'], 404);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO cliente_consentimento (cliente_id, tipo_consentimento, aceito, origem, observacao) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE aceito = VALUES(aceito), origem = VALUES(origem), observacao = VALUES(observacao), created_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            $clienteId,
            $tipo,
            $aceito,
            trim((string)($data['origem'] ?? 'sistema')),
            trim((string)($data['observacao'] ?? '')) ?: null
        ]);
    } catch (Throwable $e) {
        jsonResponse(['error' => 'falha ao registrar consentimento', 'details' => $e->getMessage()], 500);
    }

    auditLog($pdo, 'consentimento_lgpd_registrado', 'cliente_consentimento', $clienteId, [
        'tipo_consentimento' => $tipo,
        'aceito' => $aceito
    ], $actor);

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
