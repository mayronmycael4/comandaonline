<?php
require_once 'config.php';

const CANCELADO_PREFIXO = '[CANCELADO] ';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$action = strtolower(trim((string)($data['action'] ?? '')));
$actor = extractAuditActor($data);

if ($action === '') {
    jsonResponse(['error' => 'Acao obrigatoria'], 400);
}

function getComandaById(PDO $pdo, int $comandaId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM comandas WHERE id = ? LIMIT 1');
    $stmt->execute([$comandaId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function updateComandaTotal(PDO $pdo, int $comandaId): void {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
    $stmt->execute([$comandaId, CANCELADO_PREFIXO . '%']);
    $total = (float)($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare('UPDATE comandas SET total = ?, versao = COALESCE(versao, 1) + 1 WHERE id = ?');
    $stmt->execute([$total, $comandaId]);
}

function appendOperationHistory(PDO $pdo, string $operation, array $payload, array $actor): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO comanda_operacoes_historico (operacao, payload, actor_id, actor_login, actor_nome) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $operation,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $actor['actor_id'] ?? null,
            $actor['actor_login'] ?? null,
            $actor['actor_nome'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Nao bloqueia operacao principal
    }
}

if ($action === 'transferir_mesa') {
    $comandaId = (int)($data['comanda_id'] ?? 0);
    $mesaDestino = trim((string)($data['numero_mesa_destino'] ?? ''));
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($comandaId <= 0 || $mesaDestino === '') {
        jsonResponse(['error' => 'comanda_id e numero_mesa_destino obrigatorios'], 400);
    }
    if ($motivo === '') {
        jsonResponse(['error' => 'Motivo obrigatorio para transferencia'], 400);
    }
    if (!actorHasPermission($pdo, $actor, 'COMANDA_TRANSFERIR')) {
        denyAndAudit($pdo, $actor, 'COMANDA_TRANSFERIR', 'comandas', $comandaId, ['acao' => 'transferir_mesa']);
    }

    $allowOccupied = !empty($data['allow_occupied']);

    $pdo->beginTransaction();
    try {
        $comanda = getComandaById($pdo, $comandaId);
        if (!$comanda) {
            jsonResponse(['error' => 'Comanda nao encontrada'], 404);
        }
        if (($comanda['status'] ?? '') !== 'aberta') {
            jsonResponse(['error' => 'Apenas comandas abertas podem ser transferidas'], 400);
        }

        if (!$allowOccupied) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM comandas WHERE numero_mesa = ? AND status = 'aberta' AND id <> ?");
            $stmt->execute([$mesaDestino, $comandaId]);
            $occupied = (int)$stmt->fetchColumn() > 0;
            if ($occupied) {
                jsonResponse(['error' => 'Mesa destino ja possui comanda aberta'], 409);
            }
        }

        $mesaOrigem = (string)($comanda['numero_mesa'] ?? '');
        $stmt = $pdo->prepare('UPDATE comandas SET numero_mesa = ?, versao = COALESCE(versao, 1) + 1 WHERE id = ?');
        $stmt->execute([$mesaDestino, $comandaId]);

        auditLog($pdo, 'comanda_transferida_mesa', 'comandas', $comandaId, [
            'mesa_origem' => $mesaOrigem,
            'mesa_destino' => $mesaDestino,
            'motivo' => $motivo
        ], $actor);

        appendOperationHistory($pdo, 'transferir_mesa', [
            'comanda_id' => $comandaId,
            'mesa_origem' => $mesaOrigem,
            'mesa_destino' => $mesaDestino,
            'motivo' => $motivo,
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'transferir_garcom') {
    $comandaId = (int)($data['comanda_id'] ?? 0);
    $funcDestino = (int)($data['funcionario_destino_id'] ?? 0);
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($comandaId <= 0 || $funcDestino <= 0) {
        jsonResponse(['error' => 'comanda_id e funcionario_destino_id obrigatorios'], 400);
    }
    if ($motivo === '') {
        jsonResponse(['error' => 'Motivo obrigatorio para transferencia'], 400);
    }
    if (!actorHasPermission($pdo, $actor, 'COMANDA_TRANSFERIR')) {
        denyAndAudit($pdo, $actor, 'COMANDA_TRANSFERIR', 'comandas', $comandaId, ['acao' => 'transferir_garcom']);
    }

    $pdo->beginTransaction();
    try {
        $comanda = getComandaById($pdo, $comandaId);
        if (!$comanda) jsonResponse(['error' => 'Comanda nao encontrada'], 404);

        $stmt = $pdo->prepare('SELECT id, nome FROM funcionarios WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$funcDestino]);
        $dest = $stmt->fetch();
        if (!$dest) jsonResponse(['error' => 'Funcionario destino invalido'], 400);

        $origemFuncId = (int)($comanda['funcionario_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE comandas SET funcionario_id = ?, versao = COALESCE(versao, 1) + 1 WHERE id = ?');
        $stmt->execute([$funcDestino, $comandaId]);

        auditLog($pdo, 'comanda_transferida_garcom', 'comandas', $comandaId, [
            'funcionario_origem_id' => $origemFuncId,
            'funcionario_destino_id' => $funcDestino,
            'motivo' => $motivo
        ], $actor);

        appendOperationHistory($pdo, 'transferir_garcom', [
            'comanda_id' => $comandaId,
            'funcionario_origem_id' => $origemFuncId,
            'funcionario_destino_id' => $funcDestino,
            'motivo' => $motivo,
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'juntar_comandas') {
    $destinoId = (int)($data['comanda_destino_id'] ?? 0);
    $origens = array_values(array_filter(array_map('intval', (array)($data['comandas_origem_ids'] ?? [])), fn($v) => $v > 0));
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($destinoId <= 0 || count($origens) === 0) {
        jsonResponse(['error' => 'comanda_destino_id e comandas_origem_ids obrigatorios'], 400);
    }
    if ($motivo === '') {
        jsonResponse(['error' => 'Motivo obrigatorio para juntar comandas'], 400);
    }
    if (!actorHasPermission($pdo, $actor, 'COMANDA_JUNTAR')) {
        denyAndAudit($pdo, $actor, 'COMANDA_JUNTAR', 'comandas', $destinoId, ['acao' => 'juntar_comandas']);
    }

    $pdo->beginTransaction();
    try {
        $destino = getComandaById($pdo, $destinoId);
        if (!$destino) jsonResponse(['error' => 'Comanda destino nao encontrada'], 404);
        if (($destino['status'] ?? '') !== 'aberta') jsonResponse(['error' => 'Comanda destino deve estar aberta'], 400);

        $migradas = [];
        foreach ($origens as $origemId) {
            if ($origemId === $destinoId) continue;
            $origem = getComandaById($pdo, $origemId);
            if (!$origem) continue;
            if (($origem['status'] ?? '') !== 'aberta') continue;

            $stmt = $pdo->prepare('UPDATE comanda_itens SET comanda_id = ? WHERE comanda_id = ? AND nome_item NOT LIKE ?');
            $stmt->execute([$destinoId, $origemId, CANCELADO_PREFIXO . '%']);

            $stmt = $pdo->prepare("UPDATE comandas SET status = 'cancelada', observacoes = CONCAT(COALESCE(observacoes, ''), ?) WHERE id = ?");
            $stmt->execute([" [JUNTADA_EM:$destinoId]", $origemId]);

            $migradas[] = $origemId;
        }

        updateComandaTotal($pdo, $destinoId);

        auditLog($pdo, 'comandas_juntadas', 'comandas', $destinoId, [
            'origens' => $migradas,
            'motivo' => $motivo
        ], $actor);

        appendOperationHistory($pdo, 'juntar_comandas', [
            'comanda_destino_id' => $destinoId,
            'comandas_origem_ids' => $migradas,
            'motivo' => $motivo,
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true, 'migradas' => $migradas]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'dividir_por_itens') {
    $origemId = (int)($data['comanda_origem_id'] ?? 0);
    $partes = (array)($data['partes'] ?? []);
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($origemId <= 0 || count($partes) < 2) {
        jsonResponse(['error' => 'comanda_origem_id e ao menos 2 partes obrigatorios'], 400);
    }
    if ($motivo === '') {
        jsonResponse(['error' => 'Motivo obrigatorio para dividir comanda'], 400);
    }
    if (!actorHasPermission($pdo, $actor, 'COMANDA_DIVIDIR')) {
        denyAndAudit($pdo, $actor, 'COMANDA_DIVIDIR', 'comandas', $origemId, ['acao' => 'dividir_por_itens']);
    }

    $pdo->beginTransaction();
    try {
        $origem = getComandaById($pdo, $origemId);
        if (!$origem) jsonResponse(['error' => 'Comanda origem nao encontrada'], 404);
        if (($origem['status'] ?? '') !== 'aberta') jsonResponse(['error' => 'Comanda origem deve estar aberta'], 400);

        $novasComandas = [];

        foreach ($partes as $idx => $parte) {
            $itemIds = array_values(array_filter(array_map('intval', (array)($parte['item_ids'] ?? [])), fn($v) => $v > 0));
            if (count($itemIds) === 0) continue;

            $stmt = $pdo->prepare("INSERT INTO comandas (numero_mesa, funcionario_id, cliente_id, status, total, observacoes) VALUES (?, ?, ?, 'aberta', 0, ?)");
            $obs = '[SUBCOMANDA_ORIGEM:' . $origemId . '] ' . trim((string)($parte['nome'] ?? ('Parte ' . ($idx + 1))));
            $stmt->execute([
                $origem['numero_mesa'],
                $origem['funcionario_id'],
                $origem['cliente_id'] ?? null,
                $obs
            ]);
            $novaId = (int)$pdo->lastInsertId();

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $params = array_merge([$novaId, $origemId], $itemIds);
            $sql = "UPDATE comanda_itens SET comanda_id = ? WHERE comanda_id = ? AND id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            updateComandaTotal($pdo, $novaId);
            $novasComandas[] = $novaId;
        }

        updateComandaTotal($pdo, $origemId);

        auditLog($pdo, 'comanda_dividida', 'comandas', $origemId, [
            'novas_comandas' => $novasComandas,
            'motivo' => $motivo
        ], $actor);

        appendOperationHistory($pdo, 'dividir_por_itens', [
            'comanda_origem_id' => $origemId,
            'novas_comandas' => $novasComandas,
            'motivo' => $motivo,
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true, 'novas_comandas' => $novasComandas]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'dividir_por_valor') {
    $origemId = (int)($data['comanda_origem_id'] ?? 0);
    $partes = (int)($data['partes'] ?? 0);
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($origemId <= 0 || $partes < 2) {
        jsonResponse(['error' => 'comanda_origem_id e partes >= 2 obrigatorios'], 400);
    }
    if ($motivo === '') {
        jsonResponse(['error' => 'Motivo obrigatorio para dividir comanda'], 400);
    }
    if (!actorHasPermission($pdo, $actor, 'COMANDA_DIVIDIR')) {
        denyAndAudit($pdo, $actor, 'COMANDA_DIVIDIR', 'comandas', $origemId, ['acao' => 'dividir_por_valor']);
    }

    $pdo->beginTransaction();
    try {
        $origem = getComandaById($pdo, $origemId);
        if (!$origem) jsonResponse(['error' => 'Comanda origem nao encontrada'], 404);
        if (($origem['status'] ?? '') !== 'aberta') jsonResponse(['error' => 'Comanda origem deve estar aberta'], 400);

        $stmt = $pdo->prepare("SELECT id, total FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ? ORDER BY id");
        $stmt->execute([$origemId, CANCELADO_PREFIXO . '%']);
        $itens = $stmt->fetchAll();
        if (count($itens) < $partes) {
            jsonResponse(['error' => 'Quantidade de itens insuficiente para dividir por valor'], 400);
        }

        $total = 0.0;
        foreach ($itens as $it) {
            $total += (float)($it['total'] ?? 0);
        }
        $totalCents = (int)round($total * 100);
        $base = intdiv($totalCents, $partes);
        $resto = $totalCents % $partes;

        $alvos = [];
        for ($i = 0; $i < $partes; $i++) {
            $alvo = $base + ($i < $resto ? 1 : 0);
            $alvos[] = $alvo;
        }

        $novasComandas = [];
        $gruposItens = array_fill(0, $partes, []);
        $somas = array_fill(0, $partes, 0);

        // estrategia gulosa: distribui itens para aproximar alvos sem perder centavos
        usort($itens, static function ($a, $b) {
            return ((float)$b['total']) <=> ((float)$a['total']);
        });

        foreach ($itens as $item) {
            $itemCents = (int)round(((float)($item['total'] ?? 0)) * 100);
            $melhorIdx = 0;
            $melhorScore = PHP_INT_MAX;
            for ($i = 0; $i < $partes; $i++) {
                $score = abs(($somas[$i] + $itemCents) - $alvos[$i]);
                if ($score < $melhorScore) {
                    $melhorScore = $score;
                    $melhorIdx = $i;
                }
            }
            $gruposItens[$melhorIdx][] = (int)$item['id'];
            $somas[$melhorIdx] += $itemCents;
        }

        foreach ($gruposItens as $idx => $itemIds) {
            if (count($itemIds) === 0) continue;

            $stmt = $pdo->prepare("INSERT INTO comandas (numero_mesa, funcionario_id, cliente_id, status, total, observacoes) VALUES (?, ?, ?, 'aberta', 0, ?)");
            $obs = '[SUBCOMANDA_ORIGEM:' . $origemId . '] Parte valor ' . ($idx + 1);
            $stmt->execute([
                $origem['numero_mesa'],
                $origem['funcionario_id'],
                $origem['cliente_id'] ?? null,
                $obs
            ]);
            $novaId = (int)$pdo->lastInsertId();

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $params = array_merge([$novaId, $origemId], $itemIds);
            $sql = "UPDATE comanda_itens SET comanda_id = ? WHERE comanda_id = ? AND id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            updateComandaTotal($pdo, $novaId);
            $novasComandas[] = $novaId;
        }

        updateComandaTotal($pdo, $origemId);

        auditLog($pdo, 'comanda_dividida_valor', 'comandas', $origemId, [
            'partes' => $partes,
            'novas_comandas' => $novasComandas,
            'motivo' => $motivo
        ], $actor);

        appendOperationHistory($pdo, 'dividir_por_valor', [
            'comanda_origem_id' => $origemId,
            'partes' => $partes,
            'novas_comandas' => $novasComandas,
            'motivo' => $motivo,
        ], $actor);

        $pdo->commit();
        jsonResponse(['success' => true, 'novas_comandas' => $novasComandas]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Acao nao suportada'], 400);
