<?php
require_once 'config.php';

const CANCELADO_PREFIXO = '[CANCELADO] ';

function isItemCancelado(?string $nomeItem): bool {
    return $nomeItem !== null && strpos($nomeItem, CANCELADO_PREFIXO) === 0;
}

function marcarNomeItemCancelado(?string $nomeItem): string {
    $nomeBase = trim((string) $nomeItem);
    if (isItemCancelado($nomeBase)) {
        return $nomeBase;
    }
    return CANCELADO_PREFIXO . $nomeBase;
}

function assinaturaItemComparacao(array $item): string {
    $nome = trim((string)($item['nome_item'] ?? $item['nome'] ?? ''));
    $categoria = trim((string)($item['categoria'] ?? ''));
    $quantidade = (int)($item['quantidade'] ?? 0);
    $valor = round((float)($item['valor_unitario'] ?? $item['valor'] ?? 0), 2);
    $observacoes = trim((string)($item['observacoes'] ?? ''));
    $produtoId = (string)($item['produto_id'] ?? '');

    return strtolower(implode('|', [
        $nome,
        $categoria,
        $quantidade,
        number_format($valor, 2, '.', ''),
        $observacoes,
        $produtoId
    ]));
}

function comandaTemMarcadorCancelada(?string $observacoes): bool {
    return $observacoes !== null && strpos($observacoes, '[COMANDA_CANCELADA]') !== false;
}

function normalizarStatusComandaVisivel(array $comanda, int $itensAtivosCount, int $itensCanceladosCount): array {
    $statusOriginal = (string)($comanda['status'] ?? 'aberta');
    if ($statusOriginal === 'cancelada') {
        return $comanda;
    }

    if (comandaTemMarcadorCancelada($comanda['observacoes'] ?? null)) {
        $comanda['status'] = 'cancelada';
        return $comanda;
    }

    if ($itensAtivosCount === 0 && $itensCanceladosCount > 0) {
        $comanda['status'] = 'cancelada';
    }

    return $comanda;
}

function calcularStatusOperacionalComanda(PDO $pdo, array $comanda, bool $hasKitchenStatus): string {
    $statusBase = (string)($comanda['status'] ?? 'aberta');
    if ($statusBase === 'cancelada') return 'cancelada';
    if ($statusBase === 'fechada') return 'fechada';

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, " .
        ($hasKitchenStatus ? "SUM(CASE WHEN kitchen_status IN ('recebido','em_preparo') THEN 1 ELSE 0 END) AS pendentes, SUM(CASE WHEN kitchen_status = 'pronto' THEN 1 ELSE 0 END) AS prontos, SUM(CASE WHEN kitchen_status = 'entregue' THEN 1 ELSE 0 END) AS entregues" : "0 AS pendentes, 0 AS prontos, 0 AS entregues") .
        " FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
    $stmt->execute([(int)$comanda['id'], CANCELADO_PREFIXO . '%']);
    $agg = $stmt->fetch() ?: ['total' => 0, 'pendentes' => 0, 'prontos' => 0];

    $total = (int)($agg['total'] ?? 0);
    $pendentes = (int)($agg['pendentes'] ?? 0);
    $prontos = (int)($agg['prontos'] ?? 0);

    if ($total === 0) return 'aberta';
    if (!$hasKitchenStatus) return 'em_atendimento';
    if ($pendentes > 0) return 'em_preparo';
    if ((int)($agg['entregues'] ?? 0) === $total) return 'em_atendimento';
    if ($prontos > 0 && $pendentes === 0) return 'pronta';
    return 'em_atendimento';
}

// === Detecta e migra colunas extras em comanda_itens ===
// Retorna array com colunas que REALMENTE existem após tentativa de ALTER TABLE.
function detectItemCols(PDO $pdo): array {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM comanda_itens')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }

    $toAdd = [
        'kitchen_status'    => "ENUM('recebido','em_preparo','pronto','entregue','cancelado') NOT NULL DEFAULT 'recebido'",
        'kitchen_pronto_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'kitchen_setor'     => "VARCHAR(40) NOT NULL DEFAULT 'cozinha'",
        'enviado_producao_at' => 'DATETIME NULL DEFAULT NULL',
        'observacoes'       => 'TEXT NULL DEFAULT NULL',
    ];

    foreach ($toAdd as $col => $def) {
        if (!in_array($col, $cols, true)) {
            try {
                $pdo->exec("ALTER TABLE comanda_itens ADD COLUMN {$col} {$def}");
                $cols[] = $col; // adicionada com sucesso
            } catch (Throwable $e) {
                error_log("[comandas.php] ALTER para '{$col}' falhou: " . $e->getMessage());
                // Não adiciona à lista — INSERT será ajustado abaixo
            }
        }
    }

    return array_flip($cols); // chave=nome da coluna para lookup em O(1)
}

$_itemCols          = detectItemCols($pdo);
$_hasObservacoes    = isset($_itemCols['observacoes']);
$_hasKitchenStatus  = isset($_itemCols['kitchen_status']);
$_hasKitchenProAt   = isset($_itemCols['kitchen_pronto_at']);
$_hasKitchenSetor   = isset($_itemCols['kitchen_setor']);
$_hasEnviadoProdAt  = isset($_itemCols['enviado_producao_at']);

// Detecta se a tabela clientes tem coluna observacoes
$_clienteHasObs = false;
try {
    $clienteCols = $pdo->query('SHOW COLUMNS FROM clientes')->fetchAll(PDO::FETCH_COLUMN);
    $_clienteHasObs = in_array('observacoes', $clienteCols, true);
    if (!$_clienteHasObs) {
        try {
            $pdo->exec("ALTER TABLE clientes ADD COLUMN observacoes TEXT NULL DEFAULT NULL");
            $_clienteHasObs = true;
        } catch (Throwable $e) {
            error_log("[comandas.php] ALTER clientes.observacoes falhou: " . $e->getMessage());
        }
    }
} catch (Throwable $e) { /* silencia */ }

// Detecta colunas da tabela comandas
$_comandasHasObs = false;
$_comandasHasFP  = false;
$_comandasHasVersao = false;
$_comandasStatusAceitaCancelada = true;
try {
    $cmdCols = $pdo->query('SHOW COLUMNS FROM comandas')->fetchAll(PDO::FETCH_ASSOC);
    $cmdColNames = array_map(static fn ($row) => $row['Field'] ?? '', $cmdCols);
    $_comandasHasObs = in_array('observacoes',     $cmdColNames, true);
    $_comandasHasFP  = in_array('forma_pagamento', $cmdColNames, true);
    $_comandasHasVersao = in_array('versao', $cmdColNames, true);

    if (!$_comandasHasFP) {
            try { $pdo->exec("ALTER TABLE comandas ADD COLUMN forma_pagamento VARCHAR(50) NULL DEFAULT NULL"); $_comandasHasFP = true; }
            catch (Throwable $e) { error_log("[comandas.php] ALTER comandas.forma_pagamento falhou: " . $e->getMessage()); }
    }

    if (!$_comandasHasVersao) {
            try { $pdo->exec("ALTER TABLE comandas ADD COLUMN versao INT NOT NULL DEFAULT 1"); $_comandasHasVersao = true; }
            catch (Throwable $e) { error_log("[comandas.php] ALTER comandas.versao falhou: " . $e->getMessage()); }
        }

        if (!$_comandasHasObs) {
            try { $pdo->exec("ALTER TABLE comandas ADD COLUMN observacoes TEXT NULL DEFAULT NULL"); $_comandasHasObs = true; }
            catch (Throwable $e) { error_log("[comandas.php] ALTER comandas.observacoes falhou: " . $e->getMessage()); }
    }

    $statusCol = null;
    foreach ($cmdCols as $col) {
        if (($col['Field'] ?? '') === 'status') {
            $statusCol = $col;
            break;
        }
    }

    if ($statusCol && strpos((string)($statusCol['Type'] ?? ''), 'enum(') === 0) {
        $statusType = (string)($statusCol['Type'] ?? '');
        if (strpos($statusType, "'cancelada'") === false) {
            try {
                $pdo->exec("ALTER TABLE comandas MODIFY COLUMN status ENUM('aberta','fechada','cancelada') NOT NULL DEFAULT 'aberta'");
                $_comandasStatusAceitaCancelada = true;
            } catch (Throwable $e) {
                $_comandasStatusAceitaCancelada = false;
                error_log("[comandas.php] ALTER comandas.status para incluir cancelada falhou: " . $e->getMessage());
            }
        }
    }
} catch (Throwable $e) { /* silencia */ }

/**
 * Monta SQL de INSERT em comanda_itens respeitando colunas disponíveis.
 * Retorna [sql_string, params_array].
 */
function buildItemInsert(
    PDO $pdo,
    array $item,
    int $comandaId,
    bool $hasObs,
    bool $hasKitchen,
    bool $hasKitchenAt,
    bool $hasKitchenSetor,
    bool $hasEnviadoProdAt,
    string $kStatus,
    ?string $kProntoAt,
    string $kSetor = 'cozinha',
    ?string $enviadoProducaoAt = null
): array {
    $cols   = ['comanda_id', 'produto_id', 'nome_item', 'categoria', 'quantidade', 'valor_unitario', 'total'];
    $params = [$comandaId, $item['produto_id'] ?? null, $item['nome'], $item['categoria'] ?? null,
               $item['quantidade'], $item['valor'], $item['quantidade'] * $item['valor']];

    if ($hasObs) {
        $cols[]   = 'observacoes';
        $params[] = $item['observacoes'] ?? null;
    }
    if ($hasKitchen) {
        $cols[]   = 'kitchen_status';
        $params[] = $kStatus;
    }
    if ($hasKitchenSetor) {
        $cols[] = 'kitchen_setor';
        $params[] = trim($kSetor) !== '' ? $kSetor : 'cozinha';
    }
    if ($hasKitchenAt && $kProntoAt !== null) {
        $cols[]   = 'kitchen_pronto_at';
        $params[] = $kProntoAt;
    }
    if ($hasEnviadoProdAt && $enviadoProducaoAt !== null) {
        $cols[] = 'enviado_producao_at';
        $params[] = $enviadoProducaoAt;
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList      = implode(', ', $cols);
    return ["INSERT INTO comanda_itens ({$colList}) VALUES ({$placeholders})", $params];
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            // Busca comanda específica com itens
            $stmt = $pdo->prepare("
                SELECT c.*, f.nome as funcionario_nome, 
                       cl.nome as cliente_nome, cl.cpf as cliente_cpf, cl.contato as cliente_contato
                FROM comandas c
                LEFT JOIN funcionarios f ON c.funcionario_id = f.id
                LEFT JOIN clientes cl ON c.cliente_id = cl.id
                WHERE c.id = ?
            ");
            $stmt->execute([$_GET['id']]);
            $comanda = $stmt->fetch();
            
            if ($comanda) {
                $stmt = $pdo->prepare("SELECT * FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
                $stmt->execute([$_GET['id'], CANCELADO_PREFIXO . '%']);
                $comanda['itens'] = $stmt->fetchAll();

                $stmt = $pdo->prepare("SELECT id, nome_item, categoria, quantidade, valor_unitario, observacoes, created_at FROM comanda_itens WHERE comanda_id = ? AND nome_item LIKE ? ORDER BY created_at DESC, id DESC");
                $stmt->execute([$_GET['id'], CANCELADO_PREFIXO . '%']);
                $comanda['historico_cancelamentos'] = array_map(function ($item) {
                    $item['nome_item'] = preg_replace('/^' . preg_quote(CANCELADO_PREFIXO, '/') . '/', '', (string)($item['nome_item'] ?? ''));
                    return $item;
                }, $stmt->fetchAll());

                $comanda = normalizarStatusComandaVisivel(
                    $comanda,
                    count($comanda['itens']),
                    count($comanda['historico_cancelamentos'])
                );
                $comanda['status_operacional'] = calcularStatusOperacionalComanda($pdo, $comanda, $_hasKitchenStatus);
            }
            
            jsonResponse($comanda);
        } elseif (isset($_GET['funcionario_id'])) {
            $stmt = $pdo->prepare("
                SELECT c.*, f.nome as funcionario_nome,
                       cl.nome as cliente_nome, cl.cpf as cliente_cpf, cl.contato as cliente_contato
                FROM comandas c 
                LEFT JOIN funcionarios f ON c.funcionario_id = f.id
                LEFT JOIN clientes cl ON c.cliente_id = cl.id
                WHERE c.funcionario_id = ? 
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$_GET['funcionario_id']]);
            $comandas = $stmt->fetchAll();
            
            // Busca itens para cada comanda
            foreach ($comandas as &$comanda) {
                $stmt = $pdo->prepare("SELECT * FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
                $stmt->execute([$comanda['id'], CANCELADO_PREFIXO . '%']);
                $comanda['itens'] = $stmt->fetchAll();

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM comanda_itens WHERE comanda_id = ? AND nome_item LIKE ?");
                $stmt->execute([$comanda['id'], CANCELADO_PREFIXO . '%']);
                $qtdCancelados = (int)($stmt->fetchColumn() ?? 0);

                $comanda = normalizarStatusComandaVisivel($comanda, count($comanda['itens']), $qtdCancelados);
                $comanda['status_operacional'] = calcularStatusOperacionalComanda($pdo, $comanda, $_hasKitchenStatus);
            }
            unset($comanda);
            
            jsonResponse($comandas);
        } else {
            $stmt = $pdo->query("
                SELECT c.*, f.nome as funcionario_nome, cl.nome as cliente_nome 
                FROM comandas c 
                LEFT JOIN funcionarios f ON c.funcionario_id = f.id
                LEFT JOIN clientes cl ON c.cliente_id = cl.id
                ORDER BY c.created_at DESC
            ");
            $comandas = $stmt->fetchAll();
            
            // Busca itens para cada comanda
            foreach ($comandas as &$comanda) {
                $stmt = $pdo->prepare("SELECT * FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
                $stmt->execute([$comanda['id'], CANCELADO_PREFIXO . '%']);
                $comanda['itens'] = $stmt->fetchAll();

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM comanda_itens WHERE comanda_id = ? AND nome_item LIKE ?");
                $stmt->execute([$comanda['id'], CANCELADO_PREFIXO . '%']);
                $qtdCancelados = (int)($stmt->fetchColumn() ?? 0);

                $comanda = normalizarStatusComandaVisivel($comanda, count($comanda['itens']), $qtdCancelados);
                $comanda['status_operacional'] = calcularStatusOperacionalComanda($pdo, $comanda, $_hasKitchenStatus);
            }
            unset($comanda);
            
            jsonResponse($comandas);
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        $actor = extractAuditActor($data);
        $requestId = trim((string)($data['request_id'] ?? ''));
        
        $pdo->beginTransaction();
        
        try {
            if ($requestId !== '') {
                $stmt = $pdo->prepare("SELECT crd.comanda_id, c.cliente_id
                    FROM comanda_request_dedupe crd
                    JOIN comandas c ON c.id = crd.comanda_id
                    WHERE crd.request_id = ?
                    LIMIT 1");
                $stmt->execute([$requestId]);
                $dedupe = $stmt->fetch();
                if ($dedupe) {
                    $pdo->commit();
                    jsonResponse([
                        'success' => true,
                        'id' => (int)$dedupe['comanda_id'],
                        'cliente_id' => $dedupe['cliente_id'] ? (int)$dedupe['cliente_id'] : null,
                        'deduplicado' => true
                    ]);
                }
            }

            // Busca ou cria cliente se CPF informado
            $clienteId = null;
            if (!empty($data['cliente']['cpf'])) {
                $cpf = preg_replace('/[^0-9]/', '', $data['cliente']['cpf']);
                $stmt = $pdo->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?");
                $stmt->execute([$cpf]);
                $cliente = $stmt->fetch();
                
                if ($cliente) {
                    $clienteId = $cliente['id'];
                    // Atualiza info do cliente
                    $stmt = $pdo->prepare("UPDATE clientes SET nome = ?, contato = ?, ultima_visita = NOW() WHERE id = ?");
                    $stmt->execute([$data['cliente']['nome'], $data['cliente']['contato'], $clienteId]);
                } else {
                    // Cria novo cliente
                    $stmt = $pdo->prepare("INSERT INTO clientes (nome, cpf, contato, ultima_visita) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$data['cliente']['nome'], $data['cliente']['cpf'], $data['cliente']['contato']]);
                    $clienteId = $pdo->lastInsertId();
                }
            }
            
            // Cria comanda
            $stmt = $pdo->prepare("INSERT INTO comandas (numero_mesa, funcionario_id, cliente_id, status) VALUES (?, ?, ?, 'aberta')");
            $stmt->execute([
                $data['numero_mesa'],
                $data['funcionario_id'],
                $clienteId
            ]);
            $comandaId = $pdo->lastInsertId();

            if ($requestId !== '') {
                $stmt = $pdo->prepare("INSERT INTO comanda_request_dedupe (request_id, comanda_id) VALUES (?, ?)");
                $stmt->execute([$requestId, $comandaId]);
            }

            auditLog($pdo, 'comanda_aberta', 'comandas', $comandaId, [
                'numero_mesa' => $data['numero_mesa'] ?? null,
                'funcionario_responsavel_id' => $data['funcionario_id'] ?? null,
                'cliente_id' => $clienteId,
                'request_id' => $requestId !== '' ? $requestId : null
            ], $actor);
            registrarHistoricoStatusComanda($pdo, (int)$comandaId, null, 'aberta', $actor, 'comanda_criada');
            
            $pdo->commit();
            jsonResponse(['success' => true, 'id' => $comandaId, 'cliente_id' => $clienteId]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;
        
    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? 0;
        $versaoEsperada = isset($data['versao']) ? (int)$data['versao'] : 0;
        $actor = extractAuditActor($data);
        
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare("SELECT id, status, versao FROM comandas WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $comandaAtual = $stmt->fetch();
            if (!$comandaAtual) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(['error' => 'Comanda nao encontrada'], 404);
            }

            $versaoAtual = (int)($comandaAtual['versao'] ?? 1);
            if ($versaoEsperada > 0 && $versaoEsperada !== $versaoAtual) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                auditLog($pdo, 'comanda_conflito_versao', 'comandas', $id, [
                    'versao_esperada' => $versaoEsperada,
                    'versao_atual' => $versaoAtual
                ], $actor);
                jsonResponse([
                    'error' => 'Conflito de atualizacao. A comanda foi alterada por outro atendente.',
                    'code' => 'COMANDA_VERSION_CONFLICT',
                    'versao_atual' => $versaoAtual
                ], 409);
            }

            // Atualiza ou cria cliente
            $clienteId = null;
            if (!empty($data['cliente']['nome'])) {
                // Primeiro tenta buscar por CPF se informado
                if (!empty($data['cliente']['cpf'])) {
                    $cpf = preg_replace('/[^0-9]/', '', $data['cliente']['cpf']);
                    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?");
                    $stmt->execute([$cpf]);
                    $clienteExistente = $stmt->fetch();
                    
                    if ($clienteExistente) {
                        $clienteId = $clienteExistente['id'];
                    }
                }
                
                // Se não achou por CPF, tenta por nome + contato (para clientes sem CPF)
                if (!$clienteId && !empty($data['cliente']['contato'])) {
                    $stmt = $pdo->prepare("SELECT id FROM clientes WHERE nome = ? AND contato = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$data['cliente']['nome'], $data['cliente']['contato']]);
                    $clienteExistente = $stmt->fetch();
                    if ($clienteExistente) {
                        $clienteId = $clienteExistente['id'];
                    }
                }
                
                // Se achou cliente existente, atualiza
                if ($clienteId) {
                    if ($_clienteHasObs) {
                        $stmt = $pdo->prepare("UPDATE clientes SET nome = ?, contato = ?, cpf = COALESCE(?, cpf), observacoes = ? WHERE id = ?");
                        $stmt->execute([
                            $data['cliente']['nome'],
                            $data['cliente']['contato'] ?? null,
                            !empty($data['cliente']['cpf']) ? $data['cliente']['cpf'] : null,
                            $data['cliente']['observacoes'] ?? null,
                            $clienteId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE clientes SET nome = ?, contato = ?, cpf = COALESCE(?, cpf) WHERE id = ?");
                        $stmt->execute([
                            $data['cliente']['nome'],
                            $data['cliente']['contato'] ?? null,
                            !empty($data['cliente']['cpf']) ? $data['cliente']['cpf'] : null,
                            $clienteId
                        ]);
                    }
                } else {
                    // Cria novo cliente
                    if ($_clienteHasObs) {
                        $stmt = $pdo->prepare("INSERT INTO clientes (nome, contato, cpf, observacoes) VALUES (?, ?, ?, ?)");
                        $stmt->execute([
                            $data['cliente']['nome'],
                            $data['cliente']['contato'] ?? null,
                            !empty($data['cliente']['cpf']) ? $data['cliente']['cpf'] : null,
                            $data['cliente']['observacoes'] ?? null
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO clientes (nome, contato, cpf) VALUES (?, ?, ?)");
                        $stmt->execute([
                            $data['cliente']['nome'],
                            $data['cliente']['contato'] ?? null,
                            !empty($data['cliente']['cpf']) ? $data['cliente']['cpf'] : null,
                        ]);
                    }
                    $clienteId = $pdo->lastInsertId();
                }
                
                // Atualiza comanda com cliente_id
                if ($clienteId) {
                    $stmt = $pdo->prepare("UPDATE comandas SET cliente_id = ? WHERE id = ?");
                    $stmt->execute([$clienteId, $id]);
                }
            }
            
            // Busca itens ativos atuais para preservar status e identificar remocoes.
            $prontos = [];
            $statusItens = [];
            $setorItens = [];
            $enviadoProducao = [];
            $itensAtivosExistentes = [];
            $stmt = $pdo->prepare("
                SELECT id, produto_id, nome_item, categoria, quantidade, valor_unitario,
                       kitchen_status, kitchen_pronto_at, kitchen_setor, enviado_producao_at, observacoes
                FROM comanda_itens
                WHERE comanda_id = ? AND nome_item NOT LIKE ?
            ");
            $stmt->execute([$id, CANCELADO_PREFIXO . '%']);
            foreach ($stmt->fetchAll() as $row) {
                $itemId = (int) $row['id'];
                $itensAtivosExistentes[$itemId] = $row;
                $statusItens[$itemId] = (string)($row['kitchen_status'] ?? 'recebido');
                $setorItens[$itemId] = (string)($row['kitchen_setor'] ?? 'cozinha');
                $enviadoProducao[$itemId] = $row['enviado_producao_at'] ?? null;
                if ($_hasKitchenStatus && in_array(($row['kitchen_status'] ?? null), ['pronto', 'entregue'], true)) {
                    $prontos[$itemId] = $row['kitchen_pronto_at'];
                }
            }

            $itensRecebidos = $data['itens'] ?? [];
            $motivosRemocao = is_array($data['motivos_remocao'] ?? null) ? $data['motivos_remocao'] : [];

            $idsMantidos = [];
            foreach ($itensRecebidos as $item) {
                $itemIdCliente = (int) ($item['id'] ?? 0);
                if ($itemIdCliente > 0 && isset($itensAtivosExistentes[$itemIdCliente])) {
                    $idsMantidos[$itemIdCliente] = true;
                }
            }

            // Fallback por assinatura: evita cancelar item que permaneceu igual,
            // mas recebeu novo ID após regravação da comanda.
            $assinaturasEntrada = [];
            foreach ($itensRecebidos as $item) {
                $itemIdCliente = (int) ($item['id'] ?? 0);
                if ($itemIdCliente > 0 && isset($itensAtivosExistentes[$itemIdCliente])) {
                    continue;
                }
                $assinatura = assinaturaItemComparacao($item);
                $assinaturasEntrada[$assinatura] = ($assinaturasEntrada[$assinatura] ?? 0) + 1;
            }

            $itensCancelados = [];
            foreach ($itensAtivosExistentes as $itemId => $itemExistente) {
                if (isset($idsMantidos[$itemId])) {
                    continue;
                }

                $assinaturaExistente = assinaturaItemComparacao($itemExistente);
                if (($assinaturasEntrada[$assinaturaExistente] ?? 0) > 0) {
                    $assinaturasEntrada[$assinaturaExistente]--;
                    continue;
                }

                if (!isset($idsMantidos[$itemId])) {
                    $motivoRemocao = trim((string)($motivosRemocao[(string)$itemId] ?? $motivosRemocao[$itemExistente['nome_item'] ?? ''] ?? ''));
                    if ($motivoRemocao === '') {
                        jsonResponse(['error' => 'Motivo obrigatorio para remover item.', 'item_id' => $itemId], 400);
                    }

                    $statusItem = (string)($itemExistente['kitchen_status'] ?? 'recebido');
                    $jaEnviado = !empty($itemExistente['enviado_producao_at']) || in_array($statusItem, ['em_preparo', 'pronto', 'entregue'], true);
                    if ($jaEnviado && !actorHasPermission($pdo, $actor, 'COMANDA_ESTORNO_PRODUCAO')) {
                        denyAndAudit($pdo, $actor, 'COMANDA_ESTORNO_PRODUCAO', 'comanda_itens', $itemId, [
                            'acao' => 'remover_item_enviado_producao',
                            'motivo' => $motivoRemocao,
                            'status_item' => $statusItem
                        ]);
                    }

                    $itemExistente['_motivo_remocao'] = $motivoRemocao;
                    $itensCancelados[] = $itemExistente;
                }
            }

            // Regrava somente os itens ativos; os removidos serao regravados como cancelados.
            $totalItensAntes = count($itensAtivosExistentes);
            $stmt = $pdo->prepare("DELETE FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
            $stmt->execute([$id, CANCELADO_PREFIXO . '%']);

            // Insere novos itens adaptado às colunas disponíveis
            $total = 0;
            foreach ($itensRecebidos as $item) {
                $total += $item['quantidade'] * $item['valor'];

                $itemIdCliente = $item['id'] ?? null;
                $kStatus       = (string)($statusItens[$itemIdCliente] ?? 'recebido');
                if (!in_array($kStatus, ['recebido', 'em_preparo', 'pronto', 'entregue'], true)) {
                    $kStatus = 'recebido';
                }
                $kProntoAt     = $prontos[$itemIdCliente] ?? null;
                $kSetor        = (string)($item['setor'] ?? $setorItens[$itemIdCliente] ?? 'cozinha');
                $kEnviadoAt    = $enviadoProducao[$itemIdCliente] ?? null;

                [$sql, $params] = buildItemInsert(
                    $pdo, $item, $id,
                    $_hasObservacoes, $_hasKitchenStatus, $_hasKitchenProAt, $_hasKitchenSetor, $_hasEnviadoProdAt,
                    $kStatus, $kProntoAt, $kSetor, $kEnviadoAt
                );
                $pdo->prepare($sql)->execute($params);
            }

            foreach ($itensCancelados as $itemCancelado) {
                [$sql, $params] = buildItemInsert(
                    $pdo,
                    [
                        'produto_id'  => $itemCancelado['produto_id'],
                        'nome'        => marcarNomeItemCancelado($itemCancelado['nome_item']),
                        'categoria'   => $itemCancelado['categoria'],
                        'quantidade'  => (int) $itemCancelado['quantidade'],
                        'valor'       => (float) $itemCancelado['valor_unitario'],
                        'observacoes' => $itemCancelado['observacoes'] ?? null,
                    ],
                    $id,
                    $_hasObservacoes,
                    $_hasKitchenStatus,
                    $_hasKitchenProAt,
                    $_hasKitchenSetor,
                    $_hasEnviadoProdAt,
                    'cancelado',
                    $itemCancelado['kitchen_pronto_at'] ?? null,
                    (string)($itemCancelado['kitchen_setor'] ?? 'cozinha'),
                    $itemCancelado['enviado_producao_at'] ?? null
                );
                $pdo->prepare($sql)->execute($params);
            }

            $totalItensDepois = count($itensRecebidos);
            $itensAdicionados = max(0, $totalItensDepois - ($totalItensAntes - count($itensCancelados)));

            if ($itensAdicionados > 0) {
                auditLog($pdo, 'comanda_item_adicionado', 'comandas', $id, [
                    'quantidade_itens_adicionados' => $itensAdicionados,
                    'itens_ativos_total' => $totalItensDepois
                ], $actor);
            }

            if (!empty($itensCancelados)) {
                foreach ($itensCancelados as $itemCancelado) {
                    auditLog($pdo, 'comanda_item_cancelado', 'comanda_itens', $itemCancelado['id'] ?? null, [
                        'comanda_id' => $id,
                        'nome_item' => $itemCancelado['nome_item'] ?? null,
                        'categoria' => $itemCancelado['categoria'] ?? null,
                        'quantidade' => (int)($itemCancelado['quantidade'] ?? 0),
                        'valor_unitario' => (float)($itemCancelado['valor_unitario'] ?? 0),
                        'motivo' => $itemCancelado['_motivo_remocao'] ?? null,
                        'status_item_antes' => $itemCancelado['kitchen_status'] ?? null,
                        'enviado_producao_at' => $itemCancelado['enviado_producao_at'] ?? null
                    ], $actor);
                }
            }
            
            // Atualiza total, observacoes e forma_pagamento conforme colunas disponíveis
            $observacoes    = $data['cliente']['observacoes'] ?? null;
            $formaPagamento = $data['forma_pagamento'] ?? null;
            $sets   = ['total = ?'];
            $params = [$total];
            if ($_comandasHasObs) { $sets[] = 'observacoes = ?';     $params[] = $observacoes; }
            if ($_comandasHasFP)  { $sets[] = 'forma_pagamento = ?'; $params[] = $formaPagamento; }
            if ($_comandasHasVersao) { $sets[] = 'versao = versao + 1'; }
            $params[] = $id;
            $pdo->prepare("UPDATE comandas SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

            $versaoNova = $versaoAtual;
            if ($_comandasHasVersao) {
                $stmt = $pdo->prepare("SELECT versao FROM comandas WHERE id = ?");
                $stmt->execute([$id]);
                $versaoNova = (int)($stmt->fetchColumn() ?? $versaoAtual);
            }

            auditLog($pdo, 'comanda_atualizada', 'comandas', $id, [
                'total' => $total,
                'itens_ativos' => $totalItensDepois,
                'itens_cancelados_nesta_edicao' => count($itensCancelados),
                'forma_pagamento' => $formaPagamento
            ], $actor);
            
            $pdo->commit();
            jsonResponse(['success' => true, 'total' => $total, 'versao_nova' => $versaoNova]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? 0;
        $actor = extractAuditActor([]);
        $motivoCancelamento = trim((string)($_GET['motivo'] ?? ''));

        if (!actorHasPermission($pdo, $actor, 'COMANDA_CANCELAR')) {
            denyAndAudit($pdo, $actor, 'COMANDA_CANCELAR', 'comandas', $id, [
                'acao' => 'cancelar_comanda'
            ]);
        }

        if ($motivoCancelamento === '') {
            $motivoCancelamento = 'cancelamento_sem_motivo_informado';
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT status FROM comandas WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $statusAnterior = (string)($stmt->fetchColumn() ?? 'aberta');

            // Move itens ativos para historico de cancelamento com novo timestamp (created_at da nova linha)
            // Monta SELECT dinâmico para comanda_itens respeitando colunas existentes
            $selectCols = ['produto_id', 'nome_item', 'categoria', 'quantidade', 'valor_unitario'];
            if ($_hasObservacoes)   $selectCols[] = 'observacoes';
            if ($_hasKitchenStatus) $selectCols[] = 'kitchen_status';
            if ($_hasKitchenProAt)  $selectCols[] = 'kitchen_pronto_at';
            $stmt = $pdo->prepare("SELECT " . implode(', ', $selectCols) . " FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
            $stmt->execute([$id, CANCELADO_PREFIXO . '%']);
            $itensAtivos = $stmt->fetchAll();

            if (!empty($itensAtivos)) {
                $stmt = $pdo->prepare("DELETE FROM comanda_itens WHERE comanda_id = ? AND nome_item NOT LIKE ?");
                $stmt->execute([$id, CANCELADO_PREFIXO . '%']);

                foreach ($itensAtivos as $item) {
                    [$sql, $params] = buildItemInsert(
                        $pdo,
                        [
                            'produto_id'  => $item['produto_id'],
                            'nome'        => marcarNomeItemCancelado($item['nome_item'] ?? ''),
                            'categoria'   => $item['categoria'] ?? null,
                            'quantidade'  => (int)($item['quantidade'] ?? 0),
                            'valor'       => (float)($item['valor_unitario'] ?? 0),
                            'observacoes' => $item['observacoes'] ?? null,
                        ],
                        (int)$id,
                        $_hasObservacoes,
                        $_hasKitchenStatus,
                        $_hasKitchenProAt,
                        $_hasKitchenSetor,
                        $_hasEnviadoProdAt,
                        'cancelado',
                        null,
                        (string)($item['kitchen_setor'] ?? 'cozinha'),
                        $item['enviado_producao_at'] ?? null
                    );
                    $pdo->prepare($sql)->execute($params);
                }
            }

            $stmt = $pdo->prepare("UPDATE comandas SET status = 'cancelada', total = 0 WHERE id = ?");
            $stmt->execute([$id]);

            auditLog($pdo, 'comanda_cancelada', 'comandas', $id, [
                'itens_movidos_para_historico' => count($itensAtivos ?? []),
                'status_apos_cancelamento' => 'cancelada',
                'motivo' => $motivoCancelamento
            ], $actor);
            registrarHistoricoStatusComanda($pdo, (int)$id, $statusAnterior, 'cancelada', $actor, $motivoCancelamento);

                $cmdSelectCols = $_comandasHasObs ? 'status, observacoes' : 'status';
                $stmt = $pdo->prepare("SELECT {$cmdSelectCols} FROM comandas WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $comandaAtual = $stmt->fetch() ?: ['status' => '', 'observacoes' => null];
            $statusAtual = (string)($comandaAtual['status'] ?? '');

            $cancelamentoPorStatus = ($statusAtual === 'cancelada');
            if (!$cancelamentoPorStatus) {
                $obsAtual = (string)($comandaAtual['observacoes'] ?? '');
                if (!comandaTemMarcadorCancelada($obsAtual)) {
                    $obsNovo = trim('[COMANDA_CANCELADA] ' . $obsAtual);
                    if ($_comandasHasObs) {
                        $stmt = $pdo->prepare("UPDATE comandas SET observacoes = ? WHERE id = ?");
                        $stmt->execute([$obsNovo, $id]);
                    }
                }
            }

            $pdo->commit();
            jsonResponse([
                'success' => true,
                'status_cancelada_aplicado' => $cancelamentoPorStatus,
                'status_efetivo' => $cancelamentoPorStatus ? 'cancelada' : 'cancelada_virtual'
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
