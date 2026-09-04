<?php
require_once 'config.php';

// Endpoint específico para reabrir comanda fechada

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

try {
    // Busca comanda
    $stmt = $pdo->prepare("
        SELECT c.* 
        FROM comandas c
        WHERE c.id = ?
    ");
    $stmt->execute([$comandaId]);
    $comanda = $stmt->fetch();
    
    if (!$comanda) {
        jsonResponse(['error' => 'Comanda não encontrada'], 404);
    }
    
    if ($comanda['status'] !== 'fechada') {
        jsonResponse(['error' => 'Apenas comandas fechadas podem ser reabertas'], 400);
    }

    $statusAnterior = (string)($comanda['status'] ?? 'fechada');
    
    // Reabre comanda
    $stmt = $pdo->prepare("
        UPDATE comandas 
        SET status = 'aberta', 
            fechamento_data = NULL, 
            duracao = NULL 
        WHERE id = ?
    ");
    $stmt->execute([$comandaId]);

    auditLog($pdo, 'comanda_reaberta', 'comandas', $comandaId, [
        'total_original' => (float)($comanda['total'] ?? 0)
    ], $actor);
    registrarHistoricoStatusComanda($pdo, (int)$comandaId, $statusAnterior, 'aberta', $actor, 'reabertura_comanda');
    
    // Busca cliente para reverter pontos se aplicável
    if ($comanda['cliente_id']) {
        $stmt = $pdo->prepare("
            SELECT pontos_fidelidade, total_gasto, total_visitas 
            FROM clientes 
            WHERE id = ?
        ");
        $stmt->execute([$comanda['cliente_id']]);
        $cliente = $stmt->fetch();
        
        if ($cliente) {
            // Calcula pontos que foram ganhos (aproximadamente)
            $pontosRemovidos = floor($comanda['total'] / 10);
            $novosPontos = max(0, $cliente['pontos_fidelidade'] - $pontosRemovidos);
            $novoTotal = max(0, $cliente['total_gasto'] - $comanda['total']);
            $novasVisitas = max(0, $cliente['total_visitas'] - 1);
            
            $stmt = $pdo->prepare("
                UPDATE clientes 
                SET pontos_fidelidade = ?, 
                    total_gasto = ?, 
                    total_visitas = ?
                WHERE id = ?
            ");
            $stmt->execute([$novosPontos, $novoTotal, $novasVisitas, $comanda['cliente_id']]);
            
            // Registra no histórico
            $stmt = $pdo->prepare("
                INSERT INTO cliente_historico (cliente_id, comanda_id, valor_total, pontos_ganhos)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$comanda['cliente_id'], $comandaId, -$comanda['total'], -$pontosRemovidos]);

            auditLog($pdo, 'fidelidade_estornada', 'cliente_historico', $comandaId, [
                'cliente_id' => (int)$comanda['cliente_id'],
                'pontos_estornados' => (int)$pontosRemovidos,
                'valor_estornado' => (float)$comanda['total']
            ], $actor);
        }
    }
    
    jsonResponse([
        'success' => true,
        'message' => 'Comanda reabertas com sucesso'
    ]);
    
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
