<?php
require_once 'config.php';

exigirModulo($pdo, 'cozinha');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$action = strtolower(trim((string)($data['action'] ?? 'gerar')));
$actor = extractAuditActor($data);

if (!actorHasPermission($pdo, $actor, 'cozinha')) {
    denyAndAudit($pdo, $actor, 'cozinha', 'kds_impressao_log', null, ['acao' => $action]);
}

$comandaId = (int)($data['comanda_id'] ?? 0);
$setor = trim((string)($data['setor'] ?? ''));
if ($action === 'gerar' && ($comandaId <= 0 || $setor === '')) {
    jsonResponse(['error' => 'comanda_id e setor sao obrigatorios'], 400);
}

if ($action === 'gerar') {
    $stmt = $pdo->prepare("SELECT ci.id, ci.nome_item, ci.quantidade, ci.observacoes, ci.kitchen_setor, ci.kitchen_status,
                                  c.numero_mesa, c.status AS comanda_status
                           FROM comanda_itens ci
                           JOIN comandas c ON c.id = ci.comanda_id
                           WHERE ci.comanda_id = ?
                             AND ci.nome_item NOT LIKE ?
                             AND ci.kitchen_setor = ?
                             AND ci.kitchen_status IN ('recebido','em_preparo','pronto')
                           ORDER BY ci.created_at ASC, ci.id ASC");
    $stmt->execute([$comandaId, '[CANCELADO] %', $setor]);
    $itens = $stmt->fetchAll();

    if (count($itens) === 0) {
        jsonResponse(['success' => true, 'duplicado' => false, 'sem_itens' => true, 'ticket' => null]);
    }

    $payloadBase = [
        'comanda_id' => $comandaId,
        'setor' => $setor,
        'itens' => array_map(static function ($i) {
            return [
                'id' => (int)$i['id'],
                'nome_item' => (string)$i['nome_item'],
                'quantidade' => (float)$i['quantidade'],
                'observacoes' => (string)($i['observacoes'] ?? ''),
                'kitchen_status' => (string)($i['kitchen_status'] ?? 'recebido')
            ];
        }, $itens)
    ];

    $payloadHash = hash('sha256', json_encode($payloadBase, JSON_UNESCAPED_UNICODE));

    $stmt = $pdo->prepare("SELECT id, status, created_at, impresso_em FROM kds_impressao_log WHERE comanda_id = ? AND setor = ? AND payload_hash = ? LIMIT 1");
    $stmt->execute([$comandaId, $setor, $payloadHash]);
    $existente = $stmt->fetch();

    if ($existente) {
        jsonResponse([
            'success' => true,
            'duplicado' => true,
            'sem_itens' => false,
            'ticket' => [
                'id' => (int)$existente['id'],
                'comanda_id' => $comandaId,
                'setor' => $setor,
                'hash' => $payloadHash,
                'status' => $existente['status'],
                'numero_mesa' => (string)($itens[0]['numero_mesa'] ?? ''),
                'itens' => $payloadBase['itens'],
                'created_at' => $existente['created_at'],
                'impresso_em' => $existente['impresso_em']
            ]
        ]);
    }

    $stmt = $pdo->prepare("INSERT INTO kds_impressao_log (comanda_id, setor, payload_hash, payload, status, actor_id) VALUES (?, ?, ?, ?, 'gerado', ?)");
    $stmt->execute([
        $comandaId,
        $setor,
        $payloadHash,
        json_encode($payloadBase, JSON_UNESCAPED_UNICODE),
        $actor['actor_id'] ?? null
    ]);

    $printId = (int)$pdo->lastInsertId();

    auditLog($pdo, 'kds_ticket_gerado', 'kds_impressao_log', $printId, [
        'comanda_id' => $comandaId,
        'setor' => $setor,
        'payload_hash' => $payloadHash,
        'itens' => count($payloadBase['itens'])
    ], $actor);

    jsonResponse([
        'success' => true,
        'duplicado' => false,
        'sem_itens' => false,
        'ticket' => [
            'id' => $printId,
            'comanda_id' => $comandaId,
            'setor' => $setor,
            'hash' => $payloadHash,
            'status' => 'gerado',
            'numero_mesa' => (string)($itens[0]['numero_mesa'] ?? ''),
            'itens' => $payloadBase['itens']
        ]
    ]);
}

if ($action === 'confirmar') {
    $printId = (int)($data['print_id'] ?? 0);
    if ($printId <= 0) {
        jsonResponse(['error' => 'print_id obrigatorio para confirmar'], 400);
    }

    $stmt = $pdo->prepare("UPDATE kds_impressao_log SET status = 'impresso', impresso_em = NOW(), actor_id = COALESCE(?, actor_id) WHERE id = ?");
    $stmt->execute([$actor['actor_id'] ?? null, $printId]);

    auditLog($pdo, 'kds_ticket_impresso', 'kds_impressao_log', $printId, [], $actor);
    jsonResponse(['success' => true, 'print_id' => $printId]);
}

if ($action === 'reimprimir') {
    $printId = (int)($data['print_id'] ?? 0);
    if ($printId <= 0) {
        jsonResponse(['error' => 'print_id obrigatorio para reimprimir'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, comanda_id, setor, payload_hash, payload, status, created_at, impresso_em FROM kds_impressao_log WHERE id = ? LIMIT 1");
    $stmt->execute([$printId]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['error' => 'ticket nao encontrado'], 404);
    }

    auditLog($pdo, 'kds_ticket_reimpresso', 'kds_impressao_log', $printId, [], $actor);

    jsonResponse([
        'success' => true,
        'duplicado' => false,
        'ticket' => [
            'id' => (int)$row['id'],
            'comanda_id' => (int)$row['comanda_id'],
            'setor' => (string)$row['setor'],
            'hash' => (string)$row['payload_hash'],
            'status' => (string)$row['status'],
            'payload' => json_decode((string)$row['payload'], true),
            'created_at' => $row['created_at'],
            'impresso_em' => $row['impresso_em']
        ]
    ]);
}

jsonResponse(['error' => 'Acao nao suportada'], 400);
