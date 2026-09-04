<?php
require_once 'config.php';

exigirModulo($pdo, 'estoque');

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'ESTOQUE_AJUSTAR')) {
    denyAndAudit($pdo, $actor, 'ESTOQUE_AJUSTAR', 'estoque_movimentacoes', null, ['acao' => 'movimentar_estoque']);
}

if ($method === 'GET') {
    $estoqueId = (int)($_GET['estoque_id'] ?? 0);
    $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));

    if ($estoqueId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM estoque_movimentacoes WHERE estoque_id = ? ORDER BY created_at DESC LIMIT ' . $limit);
        $stmt->execute([$estoqueId]);
    } else {
        $stmt = $pdo->query('SELECT * FROM estoque_movimentacoes ORDER BY created_at DESC LIMIT ' . $limit);
    }

    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $estoqueId = (int)($data['estoque_id'] ?? 0);
    $tipo = strtolower(trim((string)($data['tipo'] ?? 'ajuste')));
    $quantidade = round((float)($data['quantidade'] ?? 0), 4);
    $custoUnit = round((float)($data['custo_unitario'] ?? 0), 4);
    $motivo = trim((string)($data['motivo'] ?? ''));
    $documentoOrigem = trim((string)($data['documento_origem'] ?? ''));
    $fornecedorNome = trim((string)($data['fornecedor_nome'] ?? ''));
    $metadados = $data['metadados'] ?? null;

    if ($estoqueId <= 0 || $quantidade <= 0) {
        jsonResponse(['error' => 'estoque_id e quantidade sao obrigatorios'], 400);
    }

    $tiposSaida = ['saida_venda', 'perda', 'ajuste_saida'];
    $tiposEntrada = ['entrada_compra', 'suprimento', 'ajuste_entrada'];

    if (in_array($tipo, ['ajuste_saida', 'ajuste_entrada', 'perda'], true) && $motivo === '') {
        jsonResponse(['error' => 'motivo obrigatorio para ajuste/perda'], 400);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT quantidade, custo_medio FROM estoque WHERE id = ? FOR UPDATE');
        $stmt->execute([$estoqueId]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['error' => 'item de estoque nao encontrado'], 404);

        $qAtual = (float)$row['quantidade'];
        $custoAtual = (float)$row['custo_medio'];

        if (in_array($tipo, $tiposSaida, true)) {
            if ($quantidade > $qAtual) {
                jsonResponse(['error' => 'quantidade insuficiente no estoque'], 400);
            }
            $qNova = $qAtual - $quantidade;
            $custoFinal = $custoAtual;
        } else {
            $qNova = $qAtual + $quantidade;
            if (in_array($tipo, $tiposEntrada, true) && $custoUnit > 0) {
                $custoFinal = $qNova > 0 ? (($qAtual * $custoAtual) + ($quantidade * $custoUnit)) / $qNova : $custoAtual;
            } else {
                $custoFinal = $custoAtual;
            }
        }

        $stmt = $pdo->prepare('UPDATE estoque SET quantidade = ?, custo_medio = ? WHERE id = ?');
        $stmt->execute([round($qNova, 4), round($custoFinal, 4), $estoqueId]);

        $stmt = $pdo->prepare('INSERT INTO estoque_movimentacoes (estoque_id, tipo, quantidade, custo_unitario, comanda_id, referencia_tipo, referencia_id, documento_origem, fornecedor_nome, motivo, metadados, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $estoqueId,
            $tipo,
            $quantidade,
            $custoUnit,
            isset($data['comanda_id']) ? (int)$data['comanda_id'] : null,
            $data['referencia_tipo'] ?? null,
            isset($data['referencia_id']) ? (string)$data['referencia_id'] : null,
            $documentoOrigem !== '' ? $documentoOrigem : null,
            $fornecedorNome !== '' ? $fornecedorNome : null,
            $motivo !== '' ? $motivo : null,
            is_array($metadados) ? json_encode($metadados, JSON_UNESCAPED_UNICODE) : (is_string($metadados) ? $metadados : null),
            $actor['actor_id'] ?? null
        ]);

        auditLog($pdo, 'estoque_movimentado', 'estoque_movimentacoes', (int)$pdo->lastInsertId(), [
            'estoque_id' => $estoqueId,
            'tipo' => $tipo,
            'quantidade' => $quantidade,
            'custo_unitario' => $custoUnit,
            'motivo' => $motivo
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true, 'quantidade_atual' => round($qNova, 4), 'custo_medio' => round($custoFinal, 4)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
