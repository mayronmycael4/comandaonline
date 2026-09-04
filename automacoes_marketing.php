<?php
require_once 'config.php';

exigirModulo($pdo, 'clientes');

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'relatorios')) {
    denyAndAudit($pdo, $actor, 'relatorios', 'marketing_automacoes_log', null, ['acao' => 'executar_automacoes']);
}

if ($method === 'GET') {
    $tipo = strtolower(trim((string)($_GET['tipo'] ?? 'aniversario')));

    if ($tipo === 'aniversario') {
        $stmt = $pdo->query("SELECT id, nome, contato, email FROM clientes WHERE DATE_FORMAT(data_nascimento, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')");
        jsonResponse(['tipo' => 'aniversario', 'clientes' => $stmt->fetchAll()]);
    }

    if ($tipo === 'retencao') {
        $dias = max(7, min(365, (int)($_GET['dias'] ?? 45)));
        $stmt = $pdo->prepare("SELECT id, nome, contato, email, ultima_visita FROM clientes WHERE ultima_visita IS NOT NULL AND ultima_visita < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY ultima_visita ASC");
        $stmt->execute([$dias]);
        jsonResponse(['tipo' => 'retencao', 'dias' => $dias, 'clientes' => $stmt->fetchAll()]);
    }

    jsonResponse(['error' => 'tipo invalido'], 400);
}

if ($method === 'POST') {
    $tipo = strtolower(trim((string)($data['tipo'] ?? 'aniversario')));
    $clientes = is_array($data['clientes'] ?? null) ? $data['clientes'] : [];

    if (count($clientes) === 0) {
        jsonResponse(['error' => 'clientes obrigatorio'], 400);
    }

    $stmtLog = $pdo->prepare('INSERT INTO marketing_automacoes_log (tipo, cliente_id, payload, status, executado_em) VALUES (?, ?, ?, ?, NOW())');

    $total = 0;
    foreach ($clientes as $clienteId) {
        $cid = (int)$clienteId;
        if ($cid <= 0) continue;

        $payload = ['cliente_id' => $cid, 'tipo' => $tipo, 'acao_sugerida' => 'contato_manual'];
        $stmtLog->execute([$tipo, $cid, json_encode($payload, JSON_UNESCAPED_UNICODE), 'executado']);

        criarNotificacaoFila(
            $pdo,
            (int)($actor['actor_id'] ?? 1),
            'marketing_automacao',
            'Acao de marketing sugerida',
            'Cliente #' . $cid . ' elegivel para campanha de ' . $tipo,
            $payload
        );

        $total++;
    }

    auditLog($pdo, 'marketing_automacao_executada', 'marketing_automacoes_log', null, [
        'tipo' => $tipo,
        'total_clientes' => $total
    ], $actor);

    jsonResponse(['success' => true, 'total_processado' => $total]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
