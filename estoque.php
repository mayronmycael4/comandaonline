<?php
require_once 'config.php';

exigirModulo($pdo, 'estoque');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM estoque WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            jsonResponse($stmt->fetch());
        } elseif (isset($_GET['alertas'])) {
            // Retorna itens com estoque baixo
            $stmt = $pdo->query("SELECT * FROM estoque WHERE quantidade <= quantidade_minima ORDER BY quantidade ASC");
            jsonResponse($stmt->fetchAll());
        } else {
            $stmt = $pdo->query("SELECT * FROM estoque ORDER BY nome");
            jsonResponse($stmt->fetchAll());
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        
        // Verifica se item já existe
        $stmt = $pdo->prepare("SELECT id, quantidade FROM estoque WHERE LOWER(nome) = LOWER(?)");
        $stmt->execute([$data['nome']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Atualiza quantidade
            $novaQtd = $existing['quantidade'] + $data['quantidade'];
            $stmt = $pdo->prepare("UPDATE estoque SET quantidade = ?, unidade = ?, quantidade_minima = ?, valor_unitario = ?, categoria = ? WHERE id = ?");
            $stmt->execute([
                $novaQtd,
                $data['unidade'],
                $data['quantidade_minima'] ?? 5,
                $data['valor_unitario'] ?? 0,
                $data['categoria'],
                $existing['id']
            ]);
            jsonResponse(['success' => true, 'id' => $existing['id'], 'updated' => true]);
        } else {
            // Insere novo
            $stmt = $pdo->prepare("INSERT INTO estoque (nome, categoria, quantidade, unidade, quantidade_minima, valor_unitario) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['nome'],
                $data['categoria'],
                $data['quantidade'],
                $data['unidade'],
                $data['quantidade_minima'] ?? 5,
                $data['valor_unitario'] ?? 0
            ]);
            jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        }
        break;
        
    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? 0;
        
        $stmt = $pdo->prepare("UPDATE estoque SET nome = ?, categoria = ?, quantidade = ?, unidade = ?, quantidade_minima = ?, valor_unitario = ? WHERE id = ?");
        $stmt->execute([
            $data['nome'],
            $data['categoria'],
            $data['quantidade'],
            $data['unidade'],
            $data['quantidade_minima'],
            $data['valor_unitario'],
            $id
        ]);
        
        jsonResponse(['success' => true]);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM estoque WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
