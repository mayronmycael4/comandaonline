<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$action = strtolower(trim((string)($data['action'] ?? '')));
$actor = extractAuditActor($data);

if ($action !== 'estornar_pagamento') {
    jsonResponse(['error' => 'Acao nao suportada'], 400);
}

$pagamentoId = (int)($data['pagamento_id'] ?? 0);
$motivo = trim((string)($data['motivo'] ?? ''));
if ($pagamentoId <= 0) {
    jsonResponse(['error' => 'pagamento_id obrigatorio'], 400);
}
if ($motivo === '') {
    jsonResponse(['error' => 'Motivo obrigatorio para estorno'], 400);
}

if (!actorHasPermission($pdo, $actor, 'PDV_ESTORNO')) {
    denyAndAudit($pdo, $actor, 'PDV_ESTORNO', 'pagamentos_comanda', $pagamentoId, [
        'acao' => 'estornar_pagamento'
    ]);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM pagamentos_comanda WHERE id = ? FOR UPDATE');
    $stmt->execute([$pagamentoId]);
    $pagamento = $stmt->fetch();
    if (!$pagamento) {
        jsonResponse(['error' => 'Pagamento nao encontrado'], 404);
    }

    if (($pagamento['status'] ?? '') === 'estornado') {
        jsonResponse(['error' => 'Pagamento ja estornado'], 400);
    }

    $metadata = [];
    if (!empty($pagamento['metadata'])) {
        $decoded = json_decode((string)$pagamento['metadata'], true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $metadata['estorno'] = [
        'motivo' => $motivo,
        'actor_id' => $actor['actor_id'] ?? null,
        'actor_login' => $actor['actor_login'] ?? null,
        'at' => date('c')
    ];

    $stmt = $pdo->prepare("UPDATE pagamentos_comanda SET status='estornado', metadata=? WHERE id=?");
    $stmt->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), $pagamentoId]);

    auditLog($pdo, 'pagamento_estornado', 'pagamentos_comanda', $pagamentoId, [
        'comanda_id' => (int)$pagamento['comanda_id'],
        'tipo' => $pagamento['tipo'],
        'valor' => (float)$pagamento['valor'],
        'motivo' => $motivo
    ], $actor);

    $pdo->commit();
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
