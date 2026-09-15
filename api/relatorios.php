<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/time_contract.php';
$companyTimezone = comanda_company_timezone($pdo);
date_default_timezone_set($companyTimezone);

$method = $_SERVER['REQUEST_METHOD'];
$format = strtolower(trim((string)($_GET['format'] ?? 'json')));

function reportEpochBounds(string $inicio, string $fim): array {
    global $companyTimezone;
    try {
        return comanda_report_epoch_bounds(substr($inicio, 0, 10), substr($fim, 0, 10), $companyTimezone);
    } catch (InvalidArgumentException $e) {
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

function reportGroupTime(array $rows, string $key, string $format, array $metrics): array {
    global $companyTimezone;
    $groups = [];
    foreach ($rows as $row) {
        $bucket = (new DateTimeImmutable('@' . $row['epoch']))->setTimezone(comanda_timezone($companyTimezone))->format($format);
        if (!isset($groups[$bucket])) $groups[$bucket] = [$key => $bucket] + array_fill_keys($metrics, 0);
        foreach ($metrics as $metric) $groups[$bucket][$metric] += (float)$row[$metric];
    }
    ksort($groups);
    return array_values($groups);
}

function isAssocArray(array $arr): bool {
    if (array() === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

function exportRowsAsCsv(string $filename, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    if (!$out) {
        jsonResponse(['error' => 'falha ao gerar csv'], 500);
    }

    if (count($rows) === 0) {
        fputcsv($out, ['sem_dados']);
        fclose($out);
        exit;
    }

    $allColumns = [];
    foreach ($rows as $row) {
        foreach (array_keys((array)$row) as $k) {
            if (!in_array($k, $allColumns, true)) $allColumns[] = $k;
        }
    }
    fputcsv($out, $allColumns);

    foreach ($rows as $row) {
        $line = [];
        foreach ($allColumns as $col) {
            $value = $row[$col] ?? null;
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $line[] = $value;
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

function reportResponse(string $tipo, array $payload, string $format): void {
    if ($format !== 'csv') {
        jsonResponse($payload);
    }

    $rows = [];
    foreach ($payload as $key => $value) {
        if (!is_array($value)) continue;
        if (count($value) === 0) continue;
        $first = $value[0] ?? null;
        if (is_array($first) && isAssocArray($first)) {
            $rows = $value;
            break;
        }
    }

    if (count($rows) === 0) {
        $rows = [array_map(static function ($v) {
            if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
            return $v;
        }, $payload)];
    }

    exportRowsAsCsv('relatorio_' . $tipo . '_' . date('Ymd_His') . '.csv', $rows);
}

if ($method !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$tipo = $_GET['tipo'] ?? 'dia'; // dia, semana, mes, ano, periodo

if ($tipo === 'ticket_medio') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d')) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);
    $agrupamento = strtolower(trim((string)($_GET['agrupar'] ?? 'dia')));
    if (!in_array($agrupamento, ['dia', 'turno', 'mesa'], true)) {
        $agrupamento = 'dia';
    }

        $stmt = $pdo->prepare("
                SELECT c.id, c.numero_mesa, c.total, UNIX_TIMESTAMP(c.fechamento_data) AS fechamento_epoch
                FROM comandas c
                WHERE c.status = 'fechada'
                    AND UNIX_TIMESTAMP(c.fechamento_data) >= ? AND UNIX_TIMESTAMP(c.fechamento_data) < ?
                ORDER BY c.fechamento_data ASC
        ");
    $stmt->execute([$inicioEpoch, $fimEpoch]);
    $comandas = $stmt->fetchAll();

    $grupos = [];
    foreach ($comandas as $comanda) {
        $dataFechamento = comanda_epoch_iso($comanda['fechamento_epoch']);
        $hora = (int)date('H', strtotime($dataFechamento));
        $mesa = (string)($comanda['numero_mesa'] ?? 'sem_mesa');
        $turno = 'noite';
        if ($hora >= 6 && $hora < 12) {
            $turno = 'manha';
        } elseif ($hora >= 12 && $hora < 18) {
            $turno = 'tarde';
        }

        $chave = $agrupamento === 'mesa' ? $mesa : ($agrupamento === 'turno' ? $turno : date('Y-m-d', strtotime($dataFechamento)));
        if (!isset($grupos[$chave])) {
            $grupos[$chave] = [
                'chave' => $chave,
                'comandas' => 0,
                'total' => 0,
            ];
            if ($agrupamento === 'mesa') {
                $grupos[$chave]['mesa'] = $mesa;
            } elseif ($agrupamento === 'turno') {
                $grupos[$chave]['turno'] = $turno;
            } else {
                $grupos[$chave]['data'] = $chave;
            }
        }

        $grupos[$chave]['comandas']++;
        $grupos[$chave]['total'] += (float)$comanda['total'];
    }

    $registros = [];
    foreach ($grupos as $grupo) {
        $grupo['ticket_medio'] = $grupo['comandas'] > 0 ? $grupo['total'] / $grupo['comandas'] : 0;
        $registros[] = $grupo;
    }

    reportResponse($tipo, [
        'periodo' => [
            'inicio' => $inicio,
            'fim' => $fim,
        ],
        'agrupamento' => $agrupamento,
        'registros' => $registros,
    ], $format);
}

if ($tipo === 'cancelamentos') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d')) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);
    $funcionarioId = isset($_GET['funcionario_id']) ? (int)$_GET['funcionario_id'] : 0;
    $produtoBusca = trim((string)($_GET['produto'] ?? ''));

    $filtroFunc = $funcionarioId > 0 ? ' AND al.actor_id = :fid ' : '';
    $sqlLogs = "
        SELECT al.*
        FROM action_log al
        WHERE UNIX_TIMESTAMP(al.created_at) >= :inicio AND UNIX_TIMESTAMP(al.created_at) < :fim
          AND al.acao IN ('comanda_cancelada', 'comanda_item_cancelado')
          {$filtroFunc}
        ORDER BY al.created_at DESC
    ";
    $stmt = $pdo->prepare($sqlLogs);
    $stmt->bindValue(':inicio', $inicioEpoch, PDO::PARAM_INT);
    $stmt->bindValue(':fim', $fimEpoch, PDO::PARAM_INT);
    if ($funcionarioId > 0) {
        $stmt->bindValue(':fid', $funcionarioId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $logs = $stmt->fetchAll();

    $sqlItens = "
        SELECT ci.id, ci.comanda_id, ci.nome_item, ci.categoria, ci.quantidade, ci.valor_unitario, ci.created_at,
               c.funcionario_id, f.nome AS funcionario_nome
        FROM comanda_itens ci
        JOIN comandas c ON c.id = ci.comanda_id
        LEFT JOIN funcionarios f ON f.id = c.funcionario_id
        WHERE ci.nome_item LIKE :cancelado
          AND UNIX_TIMESTAMP(ci.created_at) >= :inicio AND UNIX_TIMESTAMP(ci.created_at) < :fim
    ";
    if ($funcionarioId > 0) {
        $sqlItens .= ' AND c.funcionario_id = :fid ';
    }
    if ($produtoBusca !== '') {
        $sqlItens .= ' AND ci.nome_item LIKE :produto ';
    }
    $sqlItens .= ' ORDER BY ci.created_at DESC';

    $stmt = $pdo->prepare($sqlItens);
    $stmt->bindValue(':cancelado', '[CANCELADO] %');
    $stmt->bindValue(':inicio', $inicioEpoch, PDO::PARAM_INT);
    $stmt->bindValue(':fim', $fimEpoch, PDO::PARAM_INT);
    if ($funcionarioId > 0) {
        $stmt->bindValue(':fid', $funcionarioId, PDO::PARAM_INT);
    }
    if ($produtoBusca !== '') {
        $stmt->bindValue(':produto', '%' . $produtoBusca . '%');
    }
    $stmt->execute();
    $itensCancelados = $stmt->fetchAll();

    $porFuncionario = [];
    $porProduto = [];
    foreach ($logs as $log) {
        $nome = $log['actor_nome'] ?: ('ID ' . ($log['actor_id'] ?? 'N/A'));
        $porFuncionario[$nome] = ($porFuncionario[$nome] ?? 0) + 1;
    }
    foreach ($itensCancelados as $item) {
        $produto = preg_replace('/^\\[CANCELADO\\]\\s*/', '', (string)$item['nome_item']);
        $porProduto[$produto] = ($porProduto[$produto] ?? 0) + (int)$item['quantidade'];
    }

    reportResponse($tipo, [
        'periodo' => ['inicio' => $inicio, 'fim' => $fim],
        'total_cancelamentos_log' => count($logs),
        'total_itens_cancelados' => array_sum(array_map(static fn($i) => (int)$i['quantidade'], $itensCancelados)),
        'cancelamentos_por_funcionario' => $porFuncionario,
        'cancelamentos_por_produto' => $porProduto,
        'logs' => $logs,
        'itens_cancelados' => $itensCancelados
    ], $format);
}

if ($tipo === 'gerencial') {
        $inicio = ($_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days'))) . ' 00:00:00';
        $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);

        $stmt = $pdo->prepare("
                SELECT ci.categoria,
                             AVG(TIMESTAMPDIFF(MINUTE, ci.created_at, ci.kitchen_pronto_at)) AS tempo_medio_preparo_min
                FROM comanda_itens ci
                JOIN comandas c ON c.id = ci.comanda_id
                WHERE ci.kitchen_pronto_at IS NOT NULL
                    AND ci.nome_item NOT LIKE ?
                    AND UNIX_TIMESTAMP(c.created_at) >= ? AND UNIX_TIMESTAMP(c.created_at) < ?
                GROUP BY ci.categoria
        ");
        $stmt->execute(['[CANCELADO] %', $inicioEpoch, $fimEpoch]);
        $tempoPreparoPorCategoria = $stmt->fetchAll();

        $stmt = $pdo->prepare("
                SELECT AVG(TIMESTAMPDIFF(MINUTE, c.created_at, c.fechamento_data)) AS tempo_medio_ate_entrega_min
                FROM comandas c
                WHERE c.status = 'fechada'
                    AND c.fechamento_data IS NOT NULL
                    AND UNIX_TIMESTAMP(c.fechamento_data) >= ? AND UNIX_TIMESTAMP(c.fechamento_data) < ?
        ");
        $stmt->execute([$inicioEpoch, $fimEpoch]);
        $tempoMedioEntrega = (float)($stmt->fetchColumn() ?? 0);

        $stmt = $pdo->prepare("
                SELECT f.nome AS funcionario,
                             COUNT(*) AS total_cancelamentos
                FROM action_log al
                LEFT JOIN funcionarios f ON f.id = al.actor_id
                WHERE UNIX_TIMESTAMP(al.created_at) >= ? AND UNIX_TIMESTAMP(al.created_at) < ?
                    AND al.acao IN ('comanda_cancelada', 'comanda_item_cancelado')
                GROUP BY al.actor_id, f.nome
                ORDER BY total_cancelamentos DESC
        ");
        $stmt->execute([$inicioEpoch, $fimEpoch]);
        $cancelamentosPorFuncionario = $stmt->fetchAll();

        $stmt = $pdo->prepare("
                SELECT UNIX_TIMESTAMP(al.created_at) AS epoch, 1 AS total_cancelamentos
                FROM action_log al
                WHERE UNIX_TIMESTAMP(al.created_at) >= ? AND UNIX_TIMESTAMP(al.created_at) < ?
                    AND al.acao IN ('comanda_cancelada', 'comanda_item_cancelado')
                ORDER BY al.created_at ASC
        ");
        $stmt->execute([$inicioEpoch, $fimEpoch]);
        $cancelamentosPorHora = reportGroupTime($stmt->fetchAll(), 'hora', 'H:00', ['total_cancelamentos']);

        $stmt = $pdo->prepare("
                SELECT UNIX_TIMESTAMP(lc.created_at) AS epoch, 1 AS rupturas,
                             lc.quantidade_necessaria AS reposicao_necessaria
                FROM lista_compras lc
                WHERE lc.status = 'pendente'
                    AND UNIX_TIMESTAMP(lc.created_at) >= ? AND UNIX_TIMESTAMP(lc.created_at) < ?
                ORDER BY lc.created_at ASC
        ");
        $stmt->execute([$inicioEpoch, $fimEpoch]);
        $rupturaPorDia = reportGroupTime($stmt->fetchAll(), 'dia', 'Y-m-d', ['rupturas', 'reposicao_necessaria']);

        $stmt = $pdo->prepare("
                SELECT e.nome AS item_estoque,
                             e.quantidade,
                             e.quantidade_minima,
                             COALESCE(SUM(ci.quantidade), 0) AS vendas_relacionadas
                FROM estoque e
                LEFT JOIN comanda_itens ci ON LOWER(ci.nome_item) LIKE CONCAT('%', LOWER(e.nome), '%')
                LEFT JOIN comandas c ON c.id = ci.comanda_id AND UNIX_TIMESTAMP(c.created_at) >= ? AND UNIX_TIMESTAMP(c.created_at) < ?
                WHERE e.quantidade <= e.quantidade_minima
                GROUP BY e.id, e.nome, e.quantidade, e.quantidade_minima
                ORDER BY vendas_relacionadas DESC
        ");
        $stmt->execute([$inicioEpoch, $fimEpoch]);
        $impactoRuptura = $stmt->fetchAll();

        reportResponse($tipo, [
                'periodo' => ['inicio' => $inicio, 'fim' => $fim],
                'tempo_medio_preparo_por_categoria' => $tempoPreparoPorCategoria,
                'tempo_medio_ate_entrega_min' => $tempoMedioEntrega,
                'cancelamentos_por_funcionario' => $cancelamentosPorFuncionario,
                'cancelamentos_por_hora' => $cancelamentosPorHora,
                'ruptura_por_dia' => $rupturaPorDia,
                'impacto_ruptura_estoque' => $impactoRuptura
        ], $format);
}

if ($tipo === 'picos_hora') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days'))) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);

    $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(fechamento_data) AS epoch,
                                  1 AS total_comandas, total AS faturamento
                           FROM comandas
                           WHERE status = 'fechada' AND UNIX_TIMESTAMP(fechamento_data) >= ? AND UNIX_TIMESTAMP(fechamento_data) < ?
                           ORDER BY fechamento_data ASC");
    $stmt->execute([$inicioEpoch, $fimEpoch]);
    reportResponse($tipo, ['periodo' => ['inicio' => $inicio, 'fim' => $fim], 'picos_hora' => reportGroupTime($stmt->fetchAll(), 'hora', 'H:00', ['total_comandas', 'faturamento'])], $format);
}

if ($tipo === 'kds_gargalos') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days'))) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);
    $slaMin = max(1, min(180, (int)($_GET['sla_min'] ?? 15)));

    $stmt = $pdo->prepare("SELECT kitchen_setor,
                                  categoria,
                                  COUNT(*) AS total_itens,
                                  AVG(TIMESTAMPDIFF(MINUTE, created_at, kitchen_pronto_at)) AS tempo_medio_min,
                                  SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, created_at, kitchen_pronto_at) > ? THEN 1 ELSE 0 END) AS atrasados
                           FROM comanda_itens
                           WHERE kitchen_pronto_at IS NOT NULL
                             AND nome_item NOT LIKE ?
                             AND UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) < ?
                           GROUP BY kitchen_setor, categoria
                           ORDER BY atrasados DESC, tempo_medio_min DESC");
    $stmt->execute([$slaMin, '[CANCELADO] %', $inicioEpoch, $fimEpoch]);
    $rows = $stmt->fetchAll();

    $rows = array_map(static function ($r) {
        $total = max(1, (int)($r['total_itens'] ?? 0));
        $atrasados = (int)($r['atrasados'] ?? 0);
        $r['percentual_atraso'] = round(($atrasados / $total) * 100, 2);
        return $r;
    }, $rows);

    reportResponse($tipo, ['periodo' => ['inicio' => $inicio, 'fim' => $fim], 'sla_min' => $slaMin, 'gargalos' => $rows], $format);
}

if ($tipo === 'desvios') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d', strtotime('-30 days'))) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);

    $stmt = $pdo->prepare("SELECT COALESCE(actor_nome, CONCAT('ID ', actor_id)) AS usuario,
                                  acao,
                                  COUNT(*) AS total
                           FROM action_log
                           WHERE UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) < ?
                             AND acao IN ('comanda_cancelada','comanda_item_cancelado','comanda_desconto_aplicado','pagamento_estornado')
                           GROUP BY usuario, acao
                           ORDER BY total DESC");
    $stmt->execute([$inicioEpoch, $fimEpoch]);

    reportResponse($tipo, ['periodo' => ['inicio' => $inicio, 'fim' => $fim], 'desvios_por_usuario' => $stmt->fetchAll()], $format);
}

if ($tipo === 'lucro') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d', strtotime('-30 days'))) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    [$inicioEpoch, $fimEpoch] = reportEpochBounds($inicio, $fim);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor),0) AS faturamento
                           FROM pagamentos_comanda
                           WHERE status = 'confirmado' AND UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) < ?");
    $stmt->execute([$inicioEpoch, $fimEpoch]);
    $faturamento = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantidade * custo_unitario),0) AS cmv
                           FROM estoque_movimentacoes
                           WHERE tipo = 'saida_venda' AND UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) < ?");
    $stmt->execute([$inicioEpoch, $fimEpoch]);
    $cmv = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT tipo, COALESCE(SUM(valor),0) AS total FROM pagamentos_comanda
                           WHERE status = 'confirmado' AND UNIX_TIMESTAMP(created_at) >= ? AND UNIX_TIMESTAMP(created_at) < ?
                           GROUP BY tipo");
    $stmt->execute([$inicioEpoch, $fimEpoch]);
    $porTipo = $stmt->fetchAll();

    $taxas = 0.0;
    foreach ($porTipo as $linha) {
        $tipoPg = strtolower((string)($linha['tipo'] ?? ''));
        $totalTipo = (float)($linha['total'] ?? 0);
        if (strpos($tipoPg, 'credito') !== false || strpos($tipoPg, 'debito') !== false || strpos($tipoPg, 'cartao') !== false) {
            $taxas += $totalTipo * 0.03;
        } elseif (strpos($tipoPg, 'pix') !== false) {
            $taxas += $totalTipo * 0.01;
        }
    }

    $lucroEstimado = $faturamento - $cmv - $taxas;

    reportResponse($tipo, [
        'periodo' => ['inicio' => $inicio, 'fim' => $fim],
        'faturamento' => round($faturamento, 2),
        'cmv_estimado' => round($cmv, 2),
        'taxas_estimadas' => round($taxas, 2),
        'lucro_estimado' => round($lucroEstimado, 2),
        'pagamentos_por_tipo' => $porTipo
    ], $format);
}

switch ($tipo) {
    case 'dia':
        $data = $_GET['data'] ?? date('Y-m-d');
        $inicio = $data . ' 00:00:00';
        $fim = $data . ' 23:59:59';
        break;
        
    case 'semana':
        $data = $_GET['data'] ?? date('Y-m-d');
        $ts = strtotime($data);
        $inicio = date('Y-m-d 00:00:00', strtotime('sunday this week -7 days', $ts));
        $fim = date('Y-m-d 23:59:59', strtotime('saturday this week', $ts));
        break;
        
    case 'mes':
        $ano = $_GET['ano'] ?? date('Y');
        $mes = $_GET['mes'] ?? date('m');
        $inicio = "$ano-$mes-01 00:00:00";
        $fim = date('Y-m-t 23:59:59', strtotime($inicio));
        break;
        
    case 'ano':
        $ano = $_GET['ano'] ?? date('Y');
        $inicio = "$ano-01-01 00:00:00";
        $fim = "$ano-12-31 23:59:59";
        break;
        
    case 'periodo':
        $inicio = $_GET['inicio'] . ' 00:00:00';
        $fim = $_GET['fim'] . ' 23:59:59';
        break;
        
    default:
        jsonResponse(['error' => 'Tipo de relatório inválido'], 400);
}

// Busca comandas fechadas no período
try {
    [$inicioEpoch, $fimEpoch] = comanda_report_epoch_bounds(substr($inicio, 0, 10), substr($fim, 0, 10), $companyTimezone);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
$stmt = $pdo->prepare("
    SELECT c.*, UNIX_TIMESTAMP(c.fechamento_data) AS fechamento_epoch, f.nome as funcionario_nome
    FROM comandas c
    LEFT JOIN funcionarios f ON c.funcionario_id = f.id
    WHERE c.status = 'fechada'
    AND UNIX_TIMESTAMP(c.fechamento_data) >= ? AND UNIX_TIMESTAMP(c.fechamento_data) < ?
    ORDER BY c.fechamento_data DESC
");
$stmt->execute([$inicioEpoch, $fimEpoch]);
$comandas = $stmt->fetchAll();
foreach ($comandas as &$row) {
    $row['fechamento_data'] = comanda_epoch_iso($row['fechamento_epoch']);
    unset($row['fechamento_epoch']);
}
unset($row);

$total = 0;
$porCategoria = [];
$porFuncionario = [];
$quantidadeItens = 0;

foreach ($comandas as $comanda) {
    $total += $comanda['total'];
    
    // Busca itens da comanda
    $stmt = $pdo->prepare("SELECT * FROM comanda_itens WHERE comanda_id = ?");
    $stmt->execute([$comanda['id']]);
    $itens = $stmt->fetchAll();
    
    foreach ($itens as $item) {
        $quantidadeItens += $item['quantidade'];
        
        $cat = $item['categoria'] ?? 'outros';
        $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + $item['total'];
    }
    
    $func = $comanda['funcionario_nome'] ?? 'Desconhecido';
    $porFuncionario[$func] = ($porFuncionario[$func] ?? 0) + $comanda['total'];
}

reportResponse($tipo, [
    'periodo' => [
        'inicio' => $inicio,
        'fim' => $fim
    ],
    'comandas' => count($comandas),
    'total' => $total,
    'quantidade_itens' => $quantidadeItens,
    'ticket_medio' => count($comandas) > 0 ? $total / count($comandas) : 0,
    'por_categoria' => $porCategoria,
    'por_funcionario' => $porFuncionario,
    'detalhes' => $comandas
], $format);
