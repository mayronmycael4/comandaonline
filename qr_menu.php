<?php
require_once 'config.php';

function qrEnsureTables(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS qr_menu_pedidos (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        comanda_id INT NOT NULL,
        mesa_numero VARCHAR(50) NOT NULL,
        cliente_nome VARCHAR(120) NOT NULL,
        observacao_cliente VARCHAR(255) NULL,
        payload_hash VARCHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_qr_pedidos_mesa_data (mesa_numero, created_at),
        INDEX idx_qr_pedidos_comanda_data (comanda_id, created_at),
        FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qr_menu_pedido_itens (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        qr_pedido_id BIGINT NOT NULL,
        comanda_item_id INT NOT NULL,
        produto_id INT NOT NULL,
        produto_nome VARCHAR(255) NOT NULL,
        quantidade DECIMAL(10,2) NOT NULL,
        valor_unitario DECIMAL(10,2) NOT NULL,
        variacao_nome VARCHAR(120) NULL,
        adicionais_json JSON NULL,
        observacao_item VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_qr_item_pedido (qr_pedido_id),
        INDEX idx_qr_item_comanda_item (comanda_item_id),
        FOREIGN KEY (qr_pedido_id) REFERENCES qr_menu_pedidos(id) ON DELETE CASCADE,
        FOREIGN KEY (comanda_item_id) REFERENCES comanda_itens(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS qr_menu_idempotencia (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        mesa_numero VARCHAR(50) NOT NULL,
        payload_hash VARCHAR(64) NOT NULL,
        janela_slot BIGINT NOT NULL,
        qr_pedido_id BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_qr_idempotencia (mesa_numero, payload_hash, janela_slot),
        INDEX idx_qr_idempotencia_data (created_at),
        FOREIGN KEY (qr_pedido_id) REFERENCES qr_menu_pedidos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function qrSecret(): string {
    $envSecret = trim((string)(getenv('QR_MENU_SECRET') ?: ''));
    if ($envSecret !== '') return $envSecret;
    return hash('sha256', __FILE__ . '|' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

function qrExpectedToken(string $mesa): string {
    return substr(hash_hmac('sha256', 'mesa:' . $mesa, qrSecret()), 0, 24);
}

function qrValidateMesaAndToken(string $mesa, string $token): void {
    if ($mesa === '' || $token === '') {
        jsonResponse(['error' => 'Mesa e token sao obrigatorios'], 400);
    }
    $expected = qrExpectedToken($mesa);
    if (!hash_equals($expected, $token)) {
        jsonResponse(['error' => 'Token QR invalido para esta mesa'], 403);
    }
}

function qrGetFirstActiveFuncionarioId(PDO $pdo): int {
    $stmt = $pdo->query("SELECT id FROM funcionarios WHERE is_active = 1 ORDER BY is_admin DESC, id ASC LIMIT 1");
    $id = (int)($stmt->fetchColumn() ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'Nenhum funcionario ativo encontrado para abrir comanda'], 400);
    }
    return $id;
}

function qrGetOrCreateComandaId(PDO $pdo, string $mesa): int {
    $stmt = $pdo->prepare("SELECT id FROM comandas WHERE numero_mesa = ? AND status = 'aberta' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$mesa]);
    $existing = (int)($stmt->fetchColumn() ?? 0);
    if ($existing > 0) return $existing;

    $funcionarioId = qrGetFirstActiveFuncionarioId($pdo);
    $stmt = $pdo->prepare("INSERT INTO comandas (numero_mesa, funcionario_id, status, total) VALUES (?, ?, 'aberta', 0)");
    $stmt->execute([$mesa, $funcionarioId]);
    return (int)$pdo->lastInsertId();
}

function qrHasColumn(PDO $pdo, string $table, string $column): bool {
    $safeColumn = str_replace("'", "''", $column);
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$safeColumn}'");
    return (bool)$stmt->fetch();
}

function qrLoadCatalogo(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, nome, categoria, preco, descricao, imagem_url, setor, is_active, is_disponivel FROM produtos WHERE is_active = 1 ORDER BY categoria, nome");
    $produtos = [];
    foreach ($stmt->fetchAll() as $p) {
        if (isset($p['is_disponivel']) && (int)$p['is_disponivel'] === 0) {
            continue;
        }
        $id = (int)$p['id'];
        $produtos[$id] = [
            'id' => $id,
            'nome' => (string)$p['nome'],
            'categoria' => (string)($p['categoria'] ?? 'outros'),
            'preco' => (float)$p['preco'],
            'descricao' => (string)($p['descricao'] ?? ''),
            'imagem_url' => (string)($p['imagem_url'] ?? ''),
            'setor' => (string)($p['setor'] ?? 'cozinha'),
            'variacoes' => [],
            'adicionais' => []
        ];
    }

    if (count($produtos) === 0) {
        return ['categorias' => [], 'produtos' => []];
    }

    $produtoIds = implode(',', array_map('intval', array_keys($produtos)));

    if ($produtoIds !== '' && qrHasColumn($pdo, 'produto_variacoes', 'produto_id')) {
        $rows = $pdo->query("SELECT id, produto_id, grupo, nome, preco_delta, is_default, is_active FROM produto_variacoes WHERE is_active = 1 AND produto_id IN ({$produtoIds}) ORDER BY grupo, nome")->fetchAll();
        foreach ($rows as $row) {
            $pid = (int)$row['produto_id'];
            if (!isset($produtos[$pid])) continue;
            $produtos[$pid]['variacoes'][] = [
                'id' => (int)$row['id'],
                'grupo' => (string)$row['grupo'],
                'nome' => (string)$row['nome'],
                'preco_delta' => (float)$row['preco_delta'],
                'is_default' => (int)$row['is_default'] === 1
            ];
        }
    }

    if ($produtoIds !== '' && qrHasColumn($pdo, 'produto_adicionais', 'produto_id')) {
        $rows = $pdo->query("SELECT id, produto_id, nome, preco, obrigatorio, limite_min, limite_max, is_active FROM produto_adicionais WHERE is_active = 1 AND produto_id IN ({$produtoIds}) ORDER BY nome")->fetchAll();
        foreach ($rows as $row) {
            $pid = (int)$row['produto_id'];
            if (!isset($produtos[$pid])) continue;
            $produtos[$pid]['adicionais'][] = [
                'id' => (int)$row['id'],
                'nome' => (string)$row['nome'],
                'preco' => (float)$row['preco'],
                'obrigatorio' => (int)$row['obrigatorio'] === 1,
                'limite_min' => (int)$row['limite_min'],
                'limite_max' => (int)$row['limite_max']
            ];
        }
    }

    $categoriasMap = [];
    foreach ($produtos as $produto) {
        $cat = $produto['categoria'] !== '' ? $produto['categoria'] : 'outros';
        if (!isset($categoriasMap[$cat])) {
            $categoriasMap[$cat] = [
                'id' => $cat,
                'nome' => ucfirst(str_replace('_', ' ', $cat))
            ];
        }
    }

    return [
        'categorias' => array_values($categoriasMap),
        'produtos' => array_values($produtos)
    ];
}

function qrNormalizeItemsForHash(array $items): array {
    $normalized = [];
    foreach ($items as $item) {
        $adicionais = array_map('intval', $item['adicionais'] ?? []);
        sort($adicionais);
        $normalized[] = [
            'produto_id' => (int)($item['produto_id'] ?? 0),
            'quantidade' => (float)($item['quantidade'] ?? 0),
            'variacao_id' => (int)($item['variacao_id'] ?? 0),
            'adicionais' => $adicionais,
            'observacao' => trim((string)($item['observacao'] ?? ''))
        ];
    }
    usort($normalized, static function ($a, $b) {
        return [$a['produto_id'], $a['variacao_id'], json_encode($a['adicionais'])] <=> [$b['produto_id'], $b['variacao_id'], json_encode($b['adicionais'])];
    });
    return $normalized;
}

function qrFetchPedidosMesa(PDO $pdo, string $mesa): array {
    $stmt = $pdo->prepare("SELECT p.id, p.comanda_id, p.cliente_nome, p.observacao_cliente, p.created_at,
            SUM(CASE WHEN ci.kitchen_status IN ('recebido','em_preparo') THEN 1 ELSE 0 END) AS pendentes,
            SUM(CASE WHEN ci.kitchen_status = 'pronto' THEN 1 ELSE 0 END) AS prontos,
            SUM(CASE WHEN ci.kitchen_status = 'entregue' THEN 1 ELSE 0 END) AS entregues,
            COUNT(pi.id) AS total_itens
        FROM qr_menu_pedidos p
        JOIN qr_menu_pedido_itens pi ON pi.qr_pedido_id = p.id
        LEFT JOIN comanda_itens ci ON ci.id = pi.comanda_item_id
        WHERE p.mesa_numero = ?
        GROUP BY p.id, p.comanda_id, p.cliente_nome, p.observacao_cliente, p.created_at
        ORDER BY p.id DESC
        LIMIT 20");
    $stmt->execute([$mesa]);
    $pedidos = $stmt->fetchAll();

    foreach ($pedidos as &$pedido) {
        $pendentes = (int)($pedido['pendentes'] ?? 0);
        $prontos = (int)($pedido['prontos'] ?? 0);
        $entregues = (int)($pedido['entregues'] ?? 0);
        $status = 'enviado';
        if ($entregues > 0 && $pendentes === 0 && $prontos === 0) {
            $status = 'entregue';
        } elseif ($prontos > 0 && $pendentes === 0) {
            $status = 'pronto';
        } elseif ($pendentes > 0) {
            $status = 'em_preparo';
        }
        $pedido['status'] = $status;

        $stmtItens = $pdo->prepare("SELECT produto_nome, quantidade, valor_unitario, variacao_nome, adicionais_json, observacao_item
            FROM qr_menu_pedido_itens WHERE qr_pedido_id = ? ORDER BY id ASC");
        $stmtItens->execute([(int)$pedido['id']]);
        $itens = $stmtItens->fetchAll();
        foreach ($itens as &$it) {
            $it['adicionais'] = $it['adicionais_json'] ? json_decode((string)$it['adicionais_json'], true) : [];
            unset($it['adicionais_json']);
        }
        $pedido['itens'] = $itens;
    }
    unset($pedido);
    return $pedidos;
}

qrEnsureTables($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? 'init')));
    $mesa = trim((string)($_GET['mesa'] ?? ''));
    $token = trim((string)($_GET['token'] ?? ''));
    qrValidateMesaAndToken($mesa, $token);

    if ($action === 'pedidos') {
        jsonResponse(['success' => true, 'pedidos' => qrFetchPedidosMesa($pdo, $mesa)]);
    }

    $comandaId = qrGetOrCreateComandaId($pdo, $mesa);
    $catalogo = qrLoadCatalogo($pdo);

    $empresaNome = 'Comanda Online';
    try {
        $stmtEmp = $pdo->query("SELECT nome FROM empresa ORDER BY id ASC LIMIT 1");
        $empresaNome = (string)($stmtEmp->fetchColumn() ?: $empresaNome);
    } catch (Throwable $e) {
        // Mantem fallback.
    }

    $stmtCooldown = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed FROM qr_menu_pedidos WHERE mesa_numero = ? ORDER BY id DESC LIMIT 1");
    $stmtCooldown->execute([$mesa]);
    $elapsed = (int)($stmtCooldown->fetchColumn() ?? 999);
    $cooldown = max(0, 45 - $elapsed);

    jsonResponse([
        'success' => true,
        'mesa' => $mesa,
        'comanda_id' => $comandaId,
        'empresa_nome' => $empresaNome,
        'categorias' => $catalogo['categorias'],
        'produtos' => $catalogo['produtos'],
        'pedidos' => qrFetchPedidosMesa($pdo, $mesa),
        'cooldown_segundos' => $cooldown
    ]);
}

if ($method === 'POST') {
    $data = getJsonInput();
    $action = strtolower(trim((string)($data['action'] ?? 'enviar')));
    if ($action !== 'enviar') {
        jsonResponse(['error' => 'Acao nao suportada'], 400);
    }

    $mesa = trim((string)($data['mesa'] ?? ''));
    $token = trim((string)($data['token'] ?? ''));
    qrValidateMesaAndToken($mesa, $token);

    $clienteNome = trim((string)($data['cliente_nome'] ?? ''));
    $observacaoCliente = trim((string)($data['observacao_cliente'] ?? ''));
    $itens = is_array($data['itens'] ?? null) ? $data['itens'] : [];

    if ($clienteNome === '') {
        jsonResponse(['error' => 'Nome do cliente e obrigatorio'], 400);
    }
    if (count($itens) === 0) {
        jsonResponse(['error' => 'Carrinho vazio'], 400);
    }

    $normalizedItems = qrNormalizeItemsForHash($itens);
    $payloadHash = hash('sha256', json_encode([
        'mesa' => $mesa,
        'itens' => $normalizedItems
    ], JSON_UNESCAPED_UNICODE));

    $slot = (int)floor(time() / 30);
    $hasObsCol = qrHasColumn($pdo, 'comanda_itens', 'observacoes');
    $hasKitchenCol = qrHasColumn($pdo, 'comanda_itens', 'kitchen_status');
    $hasKitchenSetorCol = qrHasColumn($pdo, 'comanda_itens', 'kitchen_setor');
    $hasEnviadoCol = qrHasColumn($pdo, 'comanda_itens', 'enviado_producao_at');

    $pdo->beginTransaction();
    try {
        $stmtGate = $pdo->prepare("INSERT INTO qr_menu_idempotencia (mesa_numero, payload_hash, janela_slot) VALUES (?, ?, ?)");
        try {
            $stmtGate->execute([$mesa, $payloadHash, $slot]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $stmtDup = $pdo->prepare("SELECT qr_pedido_id FROM qr_menu_idempotencia WHERE mesa_numero = ? AND payload_hash = ? AND janela_slot = ? LIMIT 1");
                $stmtDup->execute([$mesa, $payloadHash, $slot]);
                $pedidoExistenteId = (int)($stmtDup->fetchColumn() ?? 0);
                $pdo->commit();
                jsonResponse([
                    'success' => true,
                    'duplicado' => true,
                    'pedido_id' => $pedidoExistenteId,
                    'mensagem' => 'Pedido igual detectado dentro da janela de 30 segundos.'
                ]);
            }
            throw $e;
        }

        $comandaId = qrGetOrCreateComandaId($pdo, $mesa);

        $stmtProduto = $pdo->prepare("SELECT id, nome, preco, categoria, setor, is_active, is_disponivel FROM produtos WHERE id = ? LIMIT 1");
        $stmtVariacao = $pdo->prepare("SELECT id, nome, preco_delta, grupo FROM produto_variacoes WHERE id = ? AND produto_id = ? AND is_active = 1 LIMIT 1");
        $stmtAdicional = $pdo->prepare("SELECT id, nome, preco FROM produto_adicionais WHERE id = ? AND produto_id = ? AND is_active = 1 LIMIT 1");
        $stmtFicha = $pdo->prepare("SELECT ft.estoque_id, ft.quantidade AS consumo_unitario, e.nome AS estoque_nome, e.quantidade AS estoque_qtd
            FROM produto_fichas_tecnicas ft
            JOIN estoque e ON e.id = ft.estoque_id
            WHERE ft.produto_id = ? AND ft.is_active = 1");

        $pedidoTotal = 0.0;
        $itensResolvidos = [];

        foreach ($itens as $item) {
            $produtoId = (int)($item['produto_id'] ?? 0);
            $quantidade = round((float)($item['quantidade'] ?? 0), 2);
            if ($produtoId <= 0 || $quantidade <= 0) {
                jsonResponse(['error' => 'Item invalido no pedido'], 400);
            }

            $stmtProduto->execute([$produtoId]);
            $produto = $stmtProduto->fetch();
            if (!$produto || (int)$produto['is_active'] !== 1 || (int)$produto['is_disponivel'] !== 1) {
                jsonResponse(['error' => 'Produto indisponivel para pedido'], 400);
            }

            $stmtFicha->execute([$produtoId]);
            $fichas = $stmtFicha->fetchAll();
            foreach ($fichas as $ficha) {
                $consumoUnitario = (float)($ficha['consumo_unitario'] ?? 0);
                if ($consumoUnitario <= 0) {
                    continue;
                }
                $necessario = $consumoUnitario * $quantidade;
                $estoqueAtual = (float)($ficha['estoque_qtd'] ?? 0);
                if ($estoqueAtual < $necessario) {
                    jsonResponse([
                        'error' => 'Item sem estoque suficiente para este pedido.',
                        'produto_id' => $produtoId,
                        'produto_nome' => $produto['nome'],
                        'insumo' => $ficha['estoque_nome'] ?? null
                    ], 409);
                }
            }

            $variacaoId = (int)($item['variacao_id'] ?? 0);
            $variacaoNome = null;
            $precoBase = (float)$produto['preco'];
            if ($variacaoId > 0) {
                $stmtVariacao->execute([$variacaoId, $produtoId]);
                $variacao = $stmtVariacao->fetch();
                if (!$variacao) {
                    jsonResponse(['error' => 'Variacao invalida para o produto'], 400);
                }
                $precoBase += (float)$variacao['preco_delta'];
                $variacaoNome = (string)$variacao['nome'];
            }

            $adicionaisIds = array_map('intval', $item['adicionais'] ?? []);
            $adicionaisDetalhe = [];
            $precoAdicionais = 0.0;
            foreach ($adicionaisIds as $addId) {
                if ($addId <= 0) continue;
                $stmtAdicional->execute([$addId, $produtoId]);
                $adicional = $stmtAdicional->fetch();
                if (!$adicional) {
                    jsonResponse(['error' => 'Adicional invalido para o produto'], 400);
                }
                $precoAdicionais += (float)$adicional['preco'];
                $adicionaisDetalhe[] = [
                    'id' => (int)$adicional['id'],
                    'nome' => (string)$adicional['nome'],
                    'preco' => (float)$adicional['preco']
                ];
            }

            $valorUnitario = round($precoBase + $precoAdicionais, 2);
            $itemTotal = round($valorUnitario * $quantidade, 2);
            $pedidoTotal += $itemTotal;

            $itensResolvidos[] = [
                'produto_id' => $produtoId,
                'produto_nome' => (string)$produto['nome'],
                'categoria' => (string)($produto['categoria'] ?? 'outros'),
                'setor' => (string)($produto['setor'] ?? 'cozinha'),
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'variacao_nome' => $variacaoNome,
                'adicionais' => $adicionaisDetalhe,
                'observacao_item' => trim((string)($item['observacao'] ?? ''))
            ];
        }

        $stmtPedido = $pdo->prepare("INSERT INTO qr_menu_pedidos (comanda_id, mesa_numero, cliente_nome, observacao_cliente, payload_hash) VALUES (?, ?, ?, ?, ?)");
        $stmtPedido->execute([$comandaId, $mesa, $clienteNome, $observacaoCliente !== '' ? $observacaoCliente : null, $payloadHash]);
        $qrPedidoId = (int)$pdo->lastInsertId();

        $itemCols = ['comanda_id', 'produto_id', 'nome_item', 'categoria', 'quantidade', 'valor_unitario', 'total'];
        if ($hasObsCol) $itemCols[] = 'observacoes';
        if ($hasKitchenCol) $itemCols[] = 'kitchen_status';
        if ($hasKitchenSetorCol) $itemCols[] = 'kitchen_setor';
        if ($hasEnviadoCol) $itemCols[] = 'enviado_producao_at';
        $placeholders = implode(', ', array_fill(0, count($itemCols), '?'));
        $sqlInsertItem = "INSERT INTO comanda_itens (" . implode(', ', $itemCols) . ") VALUES (" . $placeholders . ")";
        $stmtInsertItem = $pdo->prepare($sqlInsertItem);

        $stmtQrItem = $pdo->prepare("INSERT INTO qr_menu_pedido_itens (qr_pedido_id, comanda_item_id, produto_id, produto_nome, quantidade, valor_unitario, variacao_nome, adicionais_json, observacao_item) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($itensResolvidos as $it) {
            $observacoesItem = [];
            $observacoesItem[] = 'Origem: QR';
            $observacoesItem[] = 'Cliente: ' . $clienteNome;
            if ($it['variacao_nome']) $observacoesItem[] = 'Variacao: ' . $it['variacao_nome'];
            if (count($it['adicionais']) > 0) {
                $nomesAdds = array_map(static fn($a) => $a['nome'], $it['adicionais']);
                $observacoesItem[] = 'Adicionais: ' . implode(', ', $nomesAdds);
            }
            if ($it['observacao_item'] !== '') $observacoesItem[] = 'Obs item: ' . $it['observacao_item'];
            if ($observacaoCliente !== '') $observacoesItem[] = 'Obs cliente: ' . $observacaoCliente;

            $paramsItem = [
                $comandaId,
                $it['produto_id'],
                $it['produto_nome'],
                $it['categoria'],
                $it['quantidade'],
                $it['valor_unitario'],
                round($it['quantidade'] * $it['valor_unitario'], 2)
            ];

            if ($hasObsCol) $paramsItem[] = implode(' | ', $observacoesItem);
            if ($hasKitchenCol) $paramsItem[] = 'recebido';
            if ($hasKitchenSetorCol) $paramsItem[] = $it['setor'] !== '' ? $it['setor'] : 'cozinha';
            if ($hasEnviadoCol) $paramsItem[] = date('Y-m-d H:i:s');

            $stmtInsertItem->execute($paramsItem);
            $comandaItemId = (int)$pdo->lastInsertId();

            $stmtQrItem->execute([
                $qrPedidoId,
                $comandaItemId,
                $it['produto_id'],
                $it['produto_nome'],
                $it['quantidade'],
                $it['valor_unitario'],
                $it['variacao_nome'],
                count($it['adicionais']) > 0 ? json_encode($it['adicionais'], JSON_UNESCAPED_UNICODE) : null,
                $it['observacao_item'] !== '' ? $it['observacao_item'] : null
            ]);
        }

        $stmtTotal = $pdo->prepare("UPDATE comandas SET total = COALESCE(total, 0) + ? WHERE id = ?");
        $stmtTotal->execute([round($pedidoTotal, 2), $comandaId]);

        $stmtGateUpdate = $pdo->prepare("UPDATE qr_menu_idempotencia SET qr_pedido_id = ? WHERE mesa_numero = ? AND payload_hash = ? AND janela_slot = ?");
        $stmtGateUpdate->execute([$qrPedidoId, $mesa, $payloadHash, $slot]);

        auditLog($pdo, 'qr_menu_pedido_enviado', 'qr_menu_pedidos', $qrPedidoId, [
            'comanda_id' => $comandaId,
            'mesa' => $mesa,
            'cliente_nome' => $clienteNome,
            'itens' => count($itensResolvidos),
            'total' => round($pedidoTotal, 2)
        ], [
            'actor_nome' => 'cliente_qr',
            'actor_login' => 'qr:' . $mesa
        ]);

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'duplicado' => false,
            'pedido_id' => $qrPedidoId,
            'comanda_id' => $comandaId,
            'total' => round($pedidoTotal, 2),
            'mensagem' => 'Pedido enviado com sucesso!'
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
