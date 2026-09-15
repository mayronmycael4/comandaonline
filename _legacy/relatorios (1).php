<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$tipo = $_GET['tipo'] ?? 'dia'; // dia, semana, mes, ano, periodo

if ($tipo === 'cancelamentos') {
    $inicio = ($_GET['inicio'] ?? date('Y-m-d')) . ' 00:00:00';
    $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';
    $funcionarioId = isset($_GET['funcionario_id']) ? (int)$_GET['funcionario_id'] : 0;
    $produtoBusca = trim((string)($_GET['produto'] ?? ''));

    $filtroFunc = $funcionarioId > 0 ? ' AND al.actor_id = :fid ' : '';
    $sqlLogs = "
        SELECT al.*
        FROM action_log al
        WHERE al.created_at BETWEEN :inicio AND :fim
          AND al.acao IN ('comanda_cancelada', 'comanda_item_cancelado')
          {$filtroFunc}
        ORDER BY al.created_at DESC
    ";
    $stmt = $pdo->prepare($sqlLogs);
    $stmt->bindValue(':inicio', $inicio);
    $stmt->bindValue(':fim', $fim);
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
          AND ci.created_at BETWEEN :inicio AND :fim
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
    $stmt->bindValue(':inicio', $inicio);
    $stmt->bindValue(':fim', $fim);
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

    jsonResponse([
        'periodo' => ['inicio' => $inicio, 'fim' => $fim],
        'total_cancelamentos_log' => count($logs),
        'total_itens_cancelados' => array_sum(array_map(static fn($i) => (int)$i['quantidade'], $itensCancelados)),
        'cancelamentos_por_funcionario' => $porFuncionario,
        'cancelamentos_por_produto' => $porProduto,
        'logs' => $logs,
        'itens_cancelados' => $itensCancelados
    ]);
}

if ($tipo === 'gerencial') {
        $inicio = ($_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days'))) . ' 00:00:00';
        $fim = ($_GET['fim'] ?? date('Y-m-d')) . ' 23:59:59';

        $stmt = $pdo->prepare("
                SELECT ci.categoria,
                             AVG(TIMESTAMPDIFF(MINUTE, ci.created_at, ci.kitchen_pronto_at)) AS tempo_medio_preparo_min
                FROM comanda_itens ci
                JOIN comandas c ON c.id = ci.comanda_id
                WHERE ci.kitchen_pronto_at IS NOT NULL
                    AND ci.nome_item NOT LIKE ?
                    AND c.created_at BETWEEN ? AND ?
                GROUP BY ci.categoria
        ");
        $stmt->execute(['[CANCELADO] %', $inicio, $fim]);
        $tempoPreparoPorCategoria = $stmt->fetchAll();

        $stmt = $pdo->prepare("
                SELECT AVG(TIMESTAMPDIFF(MINUTE, c.created_at, c.fechamento_data)) AS tempo_medio_ate_entrega_min
                FROM comandas c
                WHERE c.status = 'fechada'
                    AND c.fechamento_data IS NOT NULL
                    AND c.fechamento_data BETWEEN ? AND ?
        ");
        $stmt->execute([$inicio, $fim]);
        $tempoMedioEntrega = (float)($stmt->fetchColumn() ?? 0);

        $stmt = $pdo->prepare("
                SELECT f.nome AS funcionario,
                             COUNT(*) AS total_cancelamentos
                FROM action_log al
                LEFT JOIN funcionarios f ON f.id = al.actor_id
                WHERE al.created_at BETWEEN ? AND ?
                    AND al.acao IN ('comanda_cancelada', 'comanda_item_cancelado')
                GROUP BY al.actor_id, f.nome
                ORDER BY total_cancelamentos DESC
        ");
        $stmt->execute([$inicio, $fim]);
        $cancelamentosPorFuncionario = $stmt->fetchAll();

        $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(al.created_at, '%H:00') AS hora,
                             COUNT(*) AS total_cancelamentos
                FROM action_log al
                WHERE al.created_at BETWEEN ? AND ?
                    AND al.acao IN ('comanda_cancelada', 'comanda_item_cancelado')
                GROUP BY DATE_FORMAT(al.created_at, '%H:00')
                ORDER BY hora ASC
        ");
        $stmt->execute([$inicio, $fim]);
        $cancelamentosPorHora = $stmt->fetchAll();

        $stmt = $pdo->prepare("
                SELECT DATE(lc.created_at) AS dia,
                             COUNT(*) AS rupturas,
                             SUM(lc.quantidade_necessaria) AS reposicao_necessaria
                FROM lista_compras lc
                WHERE lc.status = 'pendente'
                    AND lc.created_at BETWEEN ? AND ?
                GROUP BY DATE(lc.created_at)
                ORDER BY dia ASC
        ");
        $stmt->execute([$inicio, $fim]);
        $rupturaPorDia = $stmt->fetchAll();

        $stmt = $pdo->prepare("
                SELECT e.nome AS item_estoque,
                             e.quantidade,
                             e.quantidade_minima,
                             COALESCE(SUM(ci.quantidade), 0) AS vendas_relacionadas
                FROM estoque e
                LEFT JOIN comanda_itens ci ON LOWER(ci.nome_item) LIKE CONCAT('%', LOWER(e.nome), '%')
                LEFT JOIN comandas c ON c.id = ci.comanda_id AND c.created_at BETWEEN ? AND ?
                WHERE e.quantidade <= e.quantidade_minima
                GROUP BY e.id, e.nome, e.quantidade, e.quantidade_minima
                ORDER BY vendas_relacionadas DESC
        ");
        $stmt->execute([$inicio, $fim]);
        $impactoRuptura = $stmt->fetchAll();

        jsonResponse([
                'periodo' => ['inicio' => $inicio, 'fim' => $fim],
                'tempo_medio_preparo_por_categoria' => $tempoPreparoPorCategoria,
                'tempo_medio_ate_entrega_min' => $tempoMedioEntrega,
                'cancelamentos_por_funcionario' => $cancelamentosPorFuncionario,
                'cancelamentos_por_hora' => $cancelamentosPorHora,
                'ruptura_por_dia' => $rupturaPorDia,
                'impacto_ruptura_estoque' => $impactoRuptura
        ]);
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
$stmt = $pdo->prepare("
    SELECT c.*, f.nome as funcionario_nome
    FROM comandas c
    LEFT JOIN funcionarios f ON c.funcionario_id = f.id
    WHERE c.status = 'fechada'
    AND c.fechamento_data BETWEEN ? AND ?
    ORDER BY c.fechamento_data DESC
");
$stmt->execute([$inicio, $fim]);
$comandas = $stmt->fetchAll();

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

jsonResponse([
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
]);
