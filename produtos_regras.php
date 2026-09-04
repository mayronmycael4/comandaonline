<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'produtos')) {
    denyAndAudit($pdo, $actor, 'produtos', 'produtos_regras', null, ['acao' => $method]);
}

$tipo = strtolower(trim((string)(
    $data['tipo'] ?? $_GET['tipo'] ?? 'variacoes'
)));

function getRulesTable(string $tipo): ?string {
    if ($tipo === 'variacoes') return 'produto_variacoes';
    if ($tipo === 'adicionais') return 'produto_adicionais';
    if ($tipo === 'promocoes') return 'produto_promocoes';
    if ($tipo === 'combos') return 'produto_combos';
    return null;
}

$tabela = getRulesTable($tipo);
if (!$tabela) {
    jsonResponse(['error' => 'tipo invalido'], 400);
}

if ($method === 'GET') {
    if ($tipo === 'combos') {
        $stmt = $pdo->query("SELECT * FROM produto_combos WHERE is_active = 1 ORDER BY nome");
        $combos = $stmt->fetchAll();
        foreach ($combos as &$combo) {
            $s = $pdo->prepare("SELECT ci.*, p.nome AS produto_nome FROM produto_combos_itens ci JOIN produtos p ON p.id = ci.produto_id WHERE ci.combo_id = ? ORDER BY p.nome");
            $s->execute([(int)$combo['id']]);
            $combo['itens'] = $s->fetchAll();
        }
        jsonResponse($combos);
    }

    $where = ['is_active = 1'];
    $params = [];
    if (isset($_GET['produto_id'])) {
        $where[] = 'produto_id = ?';
        $params[] = (int)$_GET['produto_id'];
    }
    if (isset($_GET['categoria']) && $_GET['categoria'] !== '') {
        $where[] = 'categoria = ?';
        $params[] = (string)$_GET['categoria'];
    }

    $sql = "SELECT * FROM {$tabela} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    if ($tipo === 'combos') {
        $nome = trim((string)($data['nome'] ?? ''));
        $preco = round((float)($data['preco_combo'] ?? 0), 2);
        $itens = is_array($data['itens'] ?? null) ? $data['itens'] : [];
        if ($nome === '' || $preco <= 0 || count($itens) === 0) {
            jsonResponse(['error' => 'nome, preco_combo e itens obrigatorios'], 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO produto_combos (nome, descricao, preco_combo, regras, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([
                $nome,
                $data['descricao'] ?? null,
                $preco,
                isset($data['regras']) ? json_encode($data['regras'], JSON_UNESCAPED_UNICODE) : null
            ]);
            $comboId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO produto_combos_itens (combo_id, produto_id, quantidade, obrigatorio) VALUES (?, ?, ?, ?)");
            foreach ($itens as $i) {
                $produtoId = (int)($i['produto_id'] ?? 0);
                $qtd = round((float)($i['quantidade'] ?? 1), 2);
                if ($produtoId <= 0 || $qtd <= 0) continue;
                $stmtItem->execute([$comboId, $produtoId, $qtd, !empty($i['obrigatorio']) ? 1 : 0]);
            }

            auditLog($pdo, 'produto_combo_criado', 'produto_combos', $comboId, ['nome' => $nome], $actor);
            $pdo->commit();
            jsonResponse(['success' => true, 'id' => $comboId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    if ($tipo === 'variacoes') {
        $stmt = $pdo->prepare("INSERT INTO produto_variacoes (produto_id, grupo, nome, sku, preco_delta, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            (int)($data['produto_id'] ?? 0),
            trim((string)($data['grupo'] ?? 'geral')),
            trim((string)($data['nome'] ?? '')),
            trim((string)($data['sku'] ?? '')) ?: null,
            round((float)($data['preco_delta'] ?? 0), 2),
            !empty($data['is_default']) ? 1 : 0
        ]);
        jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($tipo === 'adicionais') {
        $stmt = $pdo->prepare("INSERT INTO produto_adicionais (produto_id, categoria, nome, preco, obrigatorio, limite_min, limite_max, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            isset($data['produto_id']) ? (int)$data['produto_id'] : null,
            trim((string)($data['categoria'] ?? '')) ?: null,
            trim((string)($data['nome'] ?? '')),
            round((float)($data['preco'] ?? 0), 2),
            !empty($data['obrigatorio']) ? 1 : 0,
            (int)($data['limite_min'] ?? 0),
            max(1, (int)($data['limite_max'] ?? 3))
        ]);
        jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($tipo === 'promocoes') {
        $stmt = $pdo->prepare("INSERT INTO produto_promocoes (nome, tipo, valor, produto_id, categoria, dia_semana, hora_inicio, hora_fim, regras, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            trim((string)($data['nome'] ?? '')),
            trim((string)($data['tipo_promocao'] ?? $data['tipo'] ?? 'percentual')),
            round((float)($data['valor'] ?? 0), 2),
            isset($data['produto_id']) ? (int)$data['produto_id'] : null,
            trim((string)($data['categoria'] ?? '')) ?: null,
            trim((string)($data['dia_semana'] ?? '')) ?: null,
            trim((string)($data['hora_inicio'] ?? '')) ?: null,
            trim((string)($data['hora_fim'] ?? '')) ?: null,
            isset($data['regras']) ? json_encode($data['regras'], JSON_UNESCAPED_UNICODE) : null
        ]);
        jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
}

if ($method === 'PUT') {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    if ($tipo === 'combos') {
        $stmt = $pdo->prepare("UPDATE produto_combos SET nome = ?, descricao = ?, preco_combo = ?, regras = ?, is_active = ? WHERE id = ?");
        $stmt->execute([
            trim((string)($data['nome'] ?? '')),
            trim((string)($data['descricao'] ?? '')) ?: null,
            round((float)($data['preco_combo'] ?? 0), 2),
            isset($data['regras']) ? json_encode($data['regras'], JSON_UNESCAPED_UNICODE) : null,
            !empty($data['is_active']) ? 1 : 0,
            $id
        ]);
        jsonResponse(['success' => true]);
    }

    $fields = [];
    $params = [];
    foreach (['nome', 'grupo', 'sku', 'categoria', 'dia_semana', 'hora_inicio', 'hora_fim'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = ?";
            $v = trim((string)$data[$f]);
            $params[] = $v !== '' ? $v : null;
        }
    }
    foreach (['preco_delta', 'preco', 'valor'] as $fnum) {
        if (array_key_exists($fnum, $data)) {
            $fields[] = "{$fnum} = ?";
            $params[] = round((float)$data[$fnum], 2);
        }
    }
    foreach (['limite_min', 'limite_max'] as $fint) {
        if (array_key_exists($fint, $data)) {
            $fields[] = "{$fint} = ?";
            $params[] = (int)$data[$fint];
        }
    }
    foreach (['is_default', 'obrigatorio', 'is_active'] as $fbool) {
        if (array_key_exists($fbool, $data)) {
            $fields[] = "{$fbool} = ?";
            $params[] = !empty($data[$fbool]) ? 1 : 0;
        }
    }

    if (empty($fields)) {
        jsonResponse(['error' => 'nenhum campo para atualizar'], 400);
    }

    $params[] = $id;
    $stmt = $pdo->prepare("UPDATE {$tabela} SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);

    jsonResponse(['success' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    $stmt = $pdo->prepare("UPDATE {$tabela} SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
