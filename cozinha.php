<?php
require_once 'config.php';

exigirModulo($pdo, 'cozinha');

const CANCELADO_PREFIXO = '[CANCELADO] ';
const CANCELADO_PENDENTE_LIMITE_SEGUNDOS = 300;

function itemFoiCancelado(?string $nomeItem, ?string $statusComanda): bool {
    if ($statusComanda === 'cancelada') {
        return true;
    }
    return $nomeItem !== null && strpos($nomeItem, CANCELADO_PREFIXO) === 0;
}

function nomeItemSemPrefixoCancelado(?string $nomeItem): string {
    $nome = (string) $nomeItem;
    if (strpos($nome, CANCELADO_PREFIXO) === 0) {
        return substr($nome, strlen(CANCELADO_PREFIXO));
    }
    return $nome;
}

function assinaturaItemCozinha(array $row): string {
    $nome = trim(strtolower(nomeItemSemPrefixoCancelado($row['nome_item'] ?? '')));
    $categoria = trim(strtolower((string)($row['categoria'] ?? '')));
    $quantidade = (int)($row['quantidade'] ?? 0);
    $valor = round((float)($row['valor_unitario'] ?? 0), 2);
    $observacoes = trim(strtolower((string)($row['item_obs'] ?? '')));
    $produtoId = (string)($row['produto_id'] ?? '');

    return implode('|', [
        $nome,
        $categoria,
        $quantidade,
        number_format($valor, 2, '.', ''),
        $observacoes,
        $produtoId
    ]);
}

// === Migration: add kitchen columns if missing ===
// Retorna o conjunto de colunas reais após tentativa de migração.
function ensureKitchenColumns(PDO $pdo): array {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM comanda_itens')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('[cozinha.php] SHOW COLUMNS falhou: ' . $e->getMessage());
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
                $cols[] = $col;
            } catch (Throwable $e) {
                // Ambiente remoto pode não ter permissão; a query será ajustada abaixo.
                error_log("[cozinha.php] ALTER TABLE para {$col} falhou: " . $e->getMessage());
            }
        }
    }

    return $cols;
}

$existingCols = ensureKitchenColumns($pdo);
$hasKitchenStatus   = in_array('kitchen_status',    $existingCols, true);
$hasKitchenProntoAt = in_array('kitchen_pronto_at', $existingCols, true);
$hasObservacoes     = in_array('observacoes',       $existingCols, true);

// Detecta também se comandas.observacoes existe (pode faltar em schemas antigos/remotos)
$hasComandasObs = false;
try {
    $cmdCols = $pdo->query('SHOW COLUMNS FROM comandas')->fetchAll(PDO::FETCH_COLUMN);
    $hasComandasObs = in_array('observacoes', $cmdCols, true);
} catch (Throwable $e) { /* silencia */ }

$method = $_SERVER['REQUEST_METHOD'];

// GET  → retorna itens pendentes (ou todos se ?todos=1)
// PUT  → marca item (ou comanda inteira) como pronto
// POST → alias de PUT para clientes sem suporte a PUT

if ($method === 'GET') {
    $somentePendentes = empty($_GET['todos']);
    $setorFiltro = trim((string)($_GET['setor'] ?? ''));

    // Monta SELECT adaptado às colunas disponíveis
    $selObservacoes     = $hasObservacoes     ? 'ci.observacoes   AS item_obs,'  : "NULL AS item_obs,";
    $selKitchenStatus   = $hasKitchenStatus   ? 'ci.kitchen_status,'             : "'recebido' AS kitchen_status,";
    $selKitchenProntoAt = $hasKitchenProntoAt ? 'ci.kitchen_pronto_at,'          : 'NULL AS kitchen_pronto_at,';
    $selKitchenSetor    = in_array('kitchen_setor', $existingCols, true) ? 'ci.kitchen_setor,' : "'cozinha' AS kitchen_setor,";
    $selComandasObs     = $hasComandasObs     ? 'c.observacoes AS comanda_obs,'  : "NULL AS comanda_obs,";

    $sql = "
        SELECT SQL_NO_CACHE
            ci.id            AS item_id,
            ci.comanda_id,
            ci.produto_id,
            ci.nome_item,
            ci.categoria,
            ci.quantidade,
            ci.valor_unitario,
            {$selObservacoes}
            {$selKitchenStatus}
            {$selKitchenProntoAt}
            {$selKitchenSetor}
            ci.created_at    AS item_criado_em,
            TIMESTAMPDIFF(MINUTE, ci.created_at, NOW()) AS item_minutos_decorridos,
            c.numero_mesa,
            c.status         AS comanda_status,
            {$selComandasObs}
            c.updated_at     AS comanda_atualizada_em,
            c.created_at     AS comanda_criada_em,
            TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS comanda_minutos_decorridos,
            f.nome           AS funcionario_nome,
            cl.nome          AS cliente_nome
        FROM comanda_itens ci
        JOIN comandas      c  ON c.id  = ci.comanda_id
        LEFT JOIN funcionarios f ON f.id = c.funcionario_id
        LEFT JOIN clientes     cl ON cl.id = c.cliente_id
        WHERE c.status IN ('aberta', 'cancelada')
    ";

    if ($setorFiltro !== '') {
        $sql .= " AND ci.kitchen_setor = " . $pdo->quote($setorFiltro);
    }

    $sql .= " ORDER BY ci.created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $rowsPorComanda = [];
    foreach ($rows as $row) {
        $cid = (int) $row['comanda_id'];
        if (!isset($rowsPorComanda[$cid])) {
            $rowsPorComanda[$cid] = [];
        }
        $rowsPorComanda[$cid][] = $row;
    }

    // Agrupa por comanda com filtro de cancelamento fantasma
    $grupados = [];
    foreach ($rowsPorComanda as $cid => $rowsComanda) {
        $assinaturasAtivas = [];
        foreach ($rowsComanda as $row) {
            $isCancelado = strpos((string)$row['nome_item'], CANCELADO_PREFIXO) === 0;
            if (!$isCancelado && ($row['comanda_status'] ?? 'aberta') !== 'cancelada') {
                $sig = assinaturaItemCozinha($row);
                $assinaturasAtivas[$sig] = ($assinaturasAtivas[$sig] ?? 0) + 1;
            }
        }

        foreach ($rowsComanda as $row) {
            $itemCancelado = itemFoiCancelado($row['nome_item'], $row['comanda_status']);

            // Em comandas abertas, se existir item ativo idêntico, ignora cancelado legado duplicado.
            if ($itemCancelado && ($row['comanda_status'] ?? 'aberta') !== 'cancelada') {
                $sig = assinaturaItemCozinha($row);
                if (($assinaturasAtivas[$sig] ?? 0) > 0) {
                    $assinaturasAtivas[$sig]--;
                    continue;
                }
            }

            $kitchenStatus = $itemCancelado ? 'cancelado' : $row['kitchen_status'];

            // Em modo "pendentes", itens cancelados aparecem por 5 min e depois saem automaticamente.
            if ($somentePendentes && $kitchenStatus === 'cancelado') {
                $canceladoEm = ($row['comanda_status'] ?? 'aberta') === 'cancelada'
                    ? ($row['comanda_atualizada_em'] ?? $row['item_criado_em'] ?? null)
                    : ($row['item_criado_em'] ?? null);

                if ($canceladoEm) {
                    $canceladoEmTs = strtotime((string)$canceladoEm);
                    if ($canceladoEmTs !== false && (time() - $canceladoEmTs) >= CANCELADO_PENDENTE_LIMITE_SEGUNDOS) {
                        continue;
                    }
                }
            }

            if ($somentePendentes && in_array($kitchenStatus, ['pronto', 'entregue'], true)) {
                continue;
            }

            if (!isset($grupados[$cid])) {
                $grupados[$cid] = [
                    'comanda_id'        => $cid,
                    'numero_mesa'       => $row['numero_mesa'],
                    'comanda_status'    => $row['comanda_status'],
                    'comanda_obs'       => $row['comanda_obs'],
                    'funcionario_nome'  => $row['funcionario_nome'],
                    'cliente_nome'      => $row['cliente_nome'],
                    'comanda_criada_em' => $row['comanda_criada_em'],
                    'comanda_minutos_decorridos' => isset($row['comanda_minutos_decorridos']) ? (int)$row['comanda_minutos_decorridos'] : null,
                    'itens'             => [],
                ];
            }

            $grupados[$cid]['itens'][] = [
                'item_id'          => (int) $row['item_id'],
                'produto_id'       => $row['produto_id'],
                'nome_item'        => nomeItemSemPrefixoCancelado($row['nome_item']),
                'categoria'        => $row['categoria'],
                'quantidade'       => (int) $row['quantidade'],
                'valor_unitario'   => (float) $row['valor_unitario'],
                'observacoes'      => $row['item_obs'],
                'kitchen_status'   => $kitchenStatus,
                'kitchen_pronto_at'=> $row['kitchen_pronto_at'],
                'kitchen_setor'    => $row['kitchen_setor'] ?? 'cozinha',
                'item_criado_em'   => $row['item_criado_em'],
                'item_minutos_decorridos' => isset($row['item_minutos_decorridos']) ? (int)$row['item_minutos_decorridos'] : null,
            ];
        }
    }

    jsonResponse([
        'server_time_ms' => (int)(microtime(true) * 1000),
        'pedidos'        => array_values($grupados),
    ]);
}

elseif ($method === 'PUT' || $method === 'POST') {
    if (!$hasKitchenStatus) {
        // Colunas ainda não existem; retorna erro amigável para tentar depois
        jsonResponse(['error' => 'Colunas de cozinha ainda não disponíveis no banco. Aguarde e tente novamente.'], 503);
    }

    $data = getJsonInput();
    $actor = extractAuditActor($data);

    // Atualiza status de item individual (recebido/em_preparo/pronto/entregue/cancelado)
    if (!empty($data['item_id'])) {
        $statusNovo = strtolower(trim((string)($data['status'] ?? 'pronto')));
        if (!in_array($statusNovo, ['recebido', 'em_preparo', 'pronto', 'entregue', 'cancelado'], true)) {
            $statusNovo = 'pronto';
        }
        $prontoAt = '';
        if ($hasKitchenProntoAt) {
            if ($statusNovo === 'pronto') {
                $prontoAt = ', kitchen_pronto_at = NOW()';
            } elseif (in_array($statusNovo, ['recebido', 'em_preparo'], true)) {
                $prontoAt = ', kitchen_pronto_at = NULL';
            }
        }
        $stmt = $pdo->prepare("
            UPDATE comanda_itens
            SET kitchen_status = ?{$prontoAt}
            WHERE id = ?
        ");
        $stmt->execute([$statusNovo, (int) $data['item_id']]);
        auditLog($pdo, 'cozinha_item_status', 'comanda_itens', (int)$data['item_id'], [
            'comanda_id' => isset($data['comanda_id']) ? (int)$data['comanda_id'] : null
        ], $actor);
        jsonResponse(['success' => true, 'updated' => 'item', 'item_id' => (int) $data['item_id'], 'status' => $statusNovo]);
    }

    // Marcar todos os itens de uma comanda como pronto
    if (!empty($data['comanda_id'])) {
        $prontoAt = $hasKitchenProntoAt ? ', kitchen_pronto_at = NOW()' : '';
        $stmt = $pdo->prepare("
            UPDATE comanda_itens
            SET kitchen_status = 'pronto'{$prontoAt}
            WHERE comanda_id = ? AND kitchen_status IN ('recebido', 'em_preparo')
        ");
        $stmt->execute([(int) $data['comanda_id']]);

        if ($stmt->rowCount() > 0) {
            $stmtComanda = $pdo->prepare("
                SELECT c.id, c.numero_mesa, c.funcionario_id, cl.nome AS cliente_nome
                FROM comandas c
                LEFT JOIN clientes cl ON cl.id = c.cliente_id
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmtComanda->execute([(int)$data['comanda_id']]);
            $comandaMeta = $stmtComanda->fetch();

            if ($comandaMeta && !empty($comandaMeta['funcionario_id'])) {
                                $stmtItens = $pdo->prepare("
                    SELECT nome_item, quantidade
                    FROM comanda_itens
                    WHERE comanda_id = ?
                      AND nome_item NOT LIKE ?
                      AND kitchen_status = 'pronto'
                    ORDER BY created_at ASC
                ");
                $stmtItens->execute([(int)$data['comanda_id'], CANCELADO_PREFIXO . '%']);
                $itensProntos = $stmtItens->fetchAll();

                $itensResumo = array_map(static function ($i) {
                    return [
                        'nome_item' => (string)($i['nome_item'] ?? ''),
                        'quantidade' => (int)($i['quantidade'] ?? 0)
                    ];
                }, $itensProntos);

                $itensTexto = implode(', ', array_map(static function ($i) {
                    return (int)$i['quantidade'] . 'x ' . (string)$i['nome_item'];
                }, array_slice($itensResumo, 0, 4)));

                $restantes = count($itensResumo) > 4 ? ' +' . (count($itensResumo) - 4) . ' itens' : '';
                $clienteNome = trim((string)($comandaMeta['cliente_nome'] ?? ''));
                if ($clienteNome === '') {
                    $clienteNome = 'Nao identificado';
                }

                criarNotificacaoFila(
                    $pdo,
                    (int)$comandaMeta['funcionario_id'],
                    'pedido_pronto',
                    'Mesa ' . $comandaMeta['numero_mesa'] . ' pronta',
                    'Cliente: ' . $clienteNome . '. Pedido: ' . ($itensTexto !== '' ? $itensTexto : 'Verificar comanda') . $restantes . '.',
                    [
                        'comanda_id' => (int)$comandaMeta['id'],
                        'numero_mesa' => (string)$comandaMeta['numero_mesa'],
                        'cliente_nome' => $clienteNome,
                        'itens' => $itensResumo,
                        'origem' => 'cozinha'
                    ]
                );
            }
        }

        auditLog($pdo, 'cozinha_comanda_pronta', 'comandas', (int)$data['comanda_id'], [
            'acao' => 'todos_itens_prontos'
        ], $actor);
        jsonResponse(['success' => true, 'updated' => 'comanda', 'comanda_id' => (int) $data['comanda_id']]);
    }

    jsonResponse(['error' => 'item_id ou comanda_id obrigatório'], 400);
}

else {
    jsonResponse(['error' => 'Método não suportado'], 405);
}
