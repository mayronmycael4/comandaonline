<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'estoque')) {
    denyAndAudit($pdo, $actor, 'estoque', 'produto_fichas_tecnicas', null, ['acao' => 'gerenciar_fichas']);
}

if ($method === 'GET') {
    $produtoId = (int)($_GET['produto_id'] ?? 0);
    if ($produtoId <= 0) jsonResponse(['error' => 'produto_id obrigatorio'], 400);

    $stmt = $pdo->prepare("SELECT pft.id, pft.produto_id, pft.estoque_id, pft.quantidade, pft.unidade, e.nome AS estoque_nome, e.unidade AS estoque_unidade
                           FROM produto_fichas_tecnicas pft
                           JOIN estoque e ON e.id = pft.estoque_id
                           WHERE pft.produto_id = ? AND pft.is_active = 1
                           ORDER BY e.nome");
    $stmt->execute([$produtoId]);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $produtoId = (int)($data['produto_id'] ?? 0);
    $itens = is_array($data['itens'] ?? null) ? $data['itens'] : [];
    if ($produtoId <= 0 || count($itens) === 0) {
        jsonResponse(['error' => 'produto_id e itens sao obrigatorios'], 400);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE produto_fichas_tecnicas SET is_active = 0 WHERE produto_id = ?')->execute([$produtoId]);

        $stmtIns = $pdo->prepare("INSERT INTO produto_fichas_tecnicas (produto_id, estoque_id, quantidade, unidade, is_active)
                                  VALUES (?, ?, ?, ?, 1)
                                  ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade), unidade = VALUES(unidade), is_active = 1, updated_at = CURRENT_TIMESTAMP");

        foreach ($itens as $item) {
            $estoqueId = (int)($item['estoque_id'] ?? 0);
            $qtd = round((float)($item['quantidade'] ?? 0), 4);
            $un = trim((string)($item['unidade'] ?? ''));
            if ($estoqueId <= 0 || $qtd <= 0) continue;
            $stmtIns->execute([$produtoId, $estoqueId, $qtd, $un !== '' ? $un : null]);
        }

        auditLog($pdo, 'ficha_tecnica_atualizada', 'produto_fichas_tecnicas', $produtoId, [
            'itens' => $itens
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    $stmt = $pdo->prepare('UPDATE produto_fichas_tecnicas SET is_active = 0 WHERE id = ?');
    $stmt->execute([$id]);
    auditLog($pdo, 'ficha_tecnica_item_removido', 'produto_fichas_tecnicas', $id, [], extractAuditActor([]));
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
