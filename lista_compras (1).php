<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['pendentes'])) {
            // Retorna apenas itens pendentes
            $stmt = $pdo->query("
                SELECT lc.*, e.quantidade as estoque_atual 
                FROM lista_compras lc
                LEFT JOIN estoque e ON lc.estoque_id = e.id
                WHERE lc.status = 'pendente'
                ORDER BY lc.prioridade DESC, lc.created_at ASC
            ");
            jsonResponse($stmt->fetchAll());
        } else {
            $stmt = $pdo->query("
                SELECT lc.*, e.quantidade as estoque_atual 
                FROM lista_compras lc
                LEFT JOIN estoque e ON lc.estoque_id = e.id
                ORDER BY lc.created_at DESC
            ");
            jsonResponse($stmt->fetchAll());
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        
        // Adiciona item manualmente à lista de compras
        $stmt = $pdo->prepare("
            INSERT INTO lista_compras (estoque_id, nome_item, quantidade_necessaria, quantidade_minima, unidade, prioridade)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['estoque_id'] ?? null,
            $data['nome_item'],
            $data['quantidade_necessaria'],
            $data['quantidade_minima'] ?? 0,
            $data['unidade'] ?? 'un',
            $data['prioridade'] ?? 'media'
        ]);
        
        jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;
        
    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? 0;
        
        // Atualiza status (comprado ou cancelado)
        $stmt = $pdo->prepare("UPDATE lista_compras SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $id]);
        
        // Se foi comprado, adiciona ao estoque
        if ($data['status'] === 'comprado' && $data['estoque_id']) {
            $stmt = $pdo->prepare("SELECT quantidade FROM estoque WHERE id = ?");
            $stmt->execute([$data['estoque_id']]);
            $estoque = $stmt->fetch();
            
            if ($estoque) {
                $novaQtd = $estoque['quantidade'] + $data['quantidade_adicionada'];
                $stmt = $pdo->prepare("UPDATE estoque SET quantidade = ? WHERE id = ?");
                $stmt->execute([$novaQtd, $data['estoque_id']]);
            }
        }
        
        jsonResponse(['success' => true]);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM lista_compras WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
