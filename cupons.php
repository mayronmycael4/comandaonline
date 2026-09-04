<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'PDV_DESCONTO_APLICAR')) {
    denyAndAudit($pdo, $actor, 'PDV_DESCONTO_APLICAR', 'cupons', null, ['acao' => 'gerenciar_cupons']);
}

if ($method === 'GET') {
    if (isset($_GET['codigo'])) {
        $codigo = strtoupper(trim((string)$_GET['codigo']));
        $stmt = $pdo->prepare('SELECT * FROM cupons WHERE codigo = ? LIMIT 1');
        $stmt->execute([$codigo]);
        jsonResponse($stmt->fetch());
    }

    $somenteAtivos = isset($_GET['ativos']) ? !empty($_GET['ativos']) : true;
    if ($somenteAtivos) {
        $stmt = $pdo->query("SELECT * FROM cupons WHERE ativo = 1 ORDER BY updated_at DESC");
    } else {
        $stmt = $pdo->query("SELECT * FROM cupons ORDER BY updated_at DESC");
    }
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $codigo = strtoupper(trim((string)($data['codigo'] ?? '')));
    $tipo = strtolower(trim((string)($data['tipo_desconto'] ?? 'percentual')));
    $valor = round((float)($data['valor_desconto'] ?? 0), 2);
    if ($codigo === '' || $valor <= 0) jsonResponse(['error' => 'codigo e valor_desconto obrigatorios'], 400);

    if (!in_array($tipo, ['percentual', 'valor'], true)) {
        jsonResponse(['error' => 'tipo_desconto invalido'], 400);
    }

    $stmt = $pdo->prepare('INSERT INTO cupons (codigo, tipo_desconto, valor_desconto, valor_minimo_pedido, validade_inicio, validade_fim, limite_uso, ativo, regras) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $codigo,
        $tipo,
        $valor,
        round((float)($data['valor_minimo_pedido'] ?? 0), 2),
        !empty($data['validade_inicio']) ? $data['validade_inicio'] : null,
        !empty($data['validade_fim']) ? $data['validade_fim'] : null,
        isset($data['limite_uso']) ? (int)$data['limite_uso'] : null,
        !empty($data['ativo']) ? 1 : 0,
        isset($data['regras']) ? json_encode($data['regras'], JSON_UNESCAPED_UNICODE) : null
    ]);

    auditLog($pdo, 'cupom_criado', 'cupons', (int)$pdo->lastInsertId(), ['codigo' => $codigo], $actor);
    jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($method === 'PUT') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    $stmt = $pdo->prepare('UPDATE cupons SET tipo_desconto = ?, valor_desconto = ?, valor_minimo_pedido = ?, validade_inicio = ?, validade_fim = ?, limite_uso = ?, ativo = ?, regras = ? WHERE id = ?');
    $stmt->execute([
        strtolower(trim((string)($data['tipo_desconto'] ?? 'percentual'))),
        round((float)($data['valor_desconto'] ?? 0), 2),
        round((float)($data['valor_minimo_pedido'] ?? 0), 2),
        !empty($data['validade_inicio']) ? $data['validade_inicio'] : null,
        !empty($data['validade_fim']) ? $data['validade_fim'] : null,
        isset($data['limite_uso']) ? (int)$data['limite_uso'] : null,
        !empty($data['ativo']) ? 1 : 0,
        isset($data['regras']) ? json_encode($data['regras'], JSON_UNESCAPED_UNICODE) : null,
        $id
    ]);

    auditLog($pdo, 'cupom_atualizado', 'cupons', $id, [], $actor);
    jsonResponse(['success' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    $stmt = $pdo->prepare('UPDATE cupons SET ativo = 0 WHERE id = ?');
    $stmt->execute([$id]);
    auditLog($pdo, 'cupom_desativado', 'cupons', $id, [], extractAuditActor([]));

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
