<?php
require_once 'config.php';

// Endpoint específico para fechar comanda e registrar fidelidade

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$data = getJsonInput();
$comandaId = $data['comanda_id'] ?? 0;
$actor = extractAuditActor($data);

if (!$comandaId) {
    jsonResponse(['error' => 'ID da comanda é obrigatório'], 400);
}

$pdo->beginTransaction();

try {
    // Busca comanda
    $stmt = $pdo->prepare("
        SELECT c.*, cl.id as cliente_id, cl.pontos_fidelidade, cl.total_gasto, cl.total_visitas
        FROM comandas c
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        WHERE c.id = ?
    ");
    $stmt->execute([$comandaId]);
    $comanda = $stmt->fetch();
    
    if (!$comanda) {
        jsonResponse(['error' => 'Comanda não encontrada'], 404);
    }
    
    if ($comanda['status'] === 'fechada') {
        jsonResponse(['error' => 'Comanda já está fechada'], 400);
    }

    $statusAnterior = (string)($comanda['status'] ?? 'aberta');
    
    // Calcula duração
    $abertura = new DateTime($comanda['created_at']);
    $fechamento = new DateTime();
    $duracao = $abertura->diff($fechamento);
    $duracaoStr = $duracao->h . 'h ' . $duracao->i . 'min';
    
    // Fecha comanda
    $stmt = $pdo->prepare("
        UPDATE comandas 
        SET status = 'fechada', 
            fechamento_data = NOW(), 
            duracao = ? 
        WHERE id = ?
    ");
    $stmt->execute([$duracaoStr, $comandaId]);

    auditLog($pdo, 'comanda_fechada', 'comandas', $comandaId, [
        'duracao' => $duracaoStr,
        'total' => (float)($comanda['total'] ?? 0)
    ], $actor);
    registrarHistoricoStatusComanda($pdo, (int)$comandaId, $statusAnterior, 'fechada', $actor, 'fechamento_comanda');
    
    // Se tem cliente, atualiza fidelidade
    if ($comanda['cliente_id']) {
        $pontosGanhos = floor($comanda['total'] / 10); // 1 ponto a cada R$10
        
        // Atualiza cliente
        $novosPontos = $comanda['pontos_fidelidade'] + $pontosGanhos;
        $novoTotal = $comanda['total_gasto'] + $comanda['total'];
        $novasVisitas = $comanda['total_visitas'] + 1;
        
        $stmt = $pdo->prepare("
            UPDATE clientes 
            SET pontos_fidelidade = ?, 
                total_gasto = ?, 
                total_visitas = ?,
                ultima_visita = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$novosPontos, $novoTotal, $novasVisitas, $comanda['cliente_id']]);
        
        // Registra no histórico
        $stmt = $pdo->prepare("
            INSERT INTO cliente_historico (cliente_id, comanda_id, valor_total, pontos_ganhos)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$comanda['cliente_id'], $comandaId, $comanda['total'], $pontosGanhos]);

        auditLog($pdo, 'fidelidade_creditada', 'cliente_historico', $comandaId, [
            'cliente_id' => (int)$comanda['cliente_id'],
            'pontos_ganhos' => (int)$pontosGanhos,
            'valor_total' => (float)$comanda['total']
        ], $actor);
    }
    
    // Verifica itens de estoque consumidos e atualiza
    $stmt = $pdo->prepare("
        SELECT ci.*, p.categoria 
        FROM comanda_itens ci
        LEFT JOIN produtos p ON ci.produto_id = p.id
        WHERE ci.comanda_id = ?
    ");
    $stmt->execute([$comandaId]);
    $itens = $stmt->fetchAll();
    
    // Atualiza estoque se possível (simplificado)
    foreach ($itens as $item) {
        // Tenta encontrar item no estoque pelo nome
        $stmt = $pdo->prepare("SELECT id, quantidade, quantidade_minima FROM estoque WHERE LOWER(nome) LIKE LOWER(?) LIMIT 1");
        $stmt->execute(['%' . $item['nome_item'] . '%']);
        $estoqueItem = $stmt->fetch();
        
        if ($estoqueItem) {
            // Diminui do estoque (simplificado - 1 unidade por item)
            $novaQtd = max(0, $estoqueItem['quantidade'] - $item['quantidade']);
            $stmt = $pdo->prepare("UPDATE estoque SET quantidade = ? WHERE id = ?");
            $stmt->execute([$novaQtd, $estoqueItem['id']]);
            
            // Verifica se precisa adicionar à lista de compras
            if ($novaQtd <= $estoqueItem['quantidade_minima']) {
                // Verifica se já existe na lista de compras
                $stmt = $pdo->prepare("SELECT id FROM lista_compras WHERE estoque_id = ? AND status = 'pendente'");
                $stmt->execute([$estoqueItem['id']]);
                if (!$stmt->fetch()) {
                    // Adiciona à lista de compras
                    $qtdNecessaria = $estoqueItem['quantidade_minima'] * 2 - $novaQtd;
                    $stmt = $pdo->prepare("
                        INSERT INTO lista_compras (estoque_id, nome_item, quantidade_necessaria, quantidade_minima, unidade, prioridade)
                        SELECT id, nome, ?, quantidade_minima, unidade, 'alta' FROM estoque WHERE id = ?
                    ");
                    $stmt->execute([$qtdNecessaria, $estoqueItem['id']]);
                }
            }
        }
    }
    
    $pdo->commit();
    jsonResponse([
        'success' => true, 
        'duracao' => $duracaoStr,
        'pontos_ganhos' => $pontosGanhos ?? 0,
        'cliente_id' => $comanda['cliente_id']
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['error' => $e->getMessage()], 500);
}
