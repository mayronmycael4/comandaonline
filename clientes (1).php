<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $cliente = $stmt->fetch();
            
            if ($cliente) {
                // Busca histórico
                $stmt = $pdo->prepare("
                    SELECT ch.*, c.numero_mesa, c.created_at as data_comanda 
                    FROM cliente_historico ch 
                    JOIN comandas c ON ch.comanda_id = c.id 
                    WHERE ch.cliente_id = ? 
                    ORDER BY ch.created_at DESC
                ");
                $stmt->execute([$_GET['id']]);
                $cliente['historico'] = $stmt->fetchAll();
            }
            
            jsonResponse($cliente);
        } elseif (isset($_GET['cpf'])) {
            $cpf = preg_replace('/[^0-9]/', '', $_GET['cpf']);
            $stmt = $pdo->prepare("SELECT * FROM clientes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?");
            $stmt->execute([$cpf]);
            jsonResponse($stmt->fetch());
        } else {
            $stmt = $pdo->query("SELECT * FROM clientes ORDER BY nome");
            jsonResponse($stmt->fetchAll());
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        
        // Limpa CPF
        $cpf = isset($data['cpf']) ? preg_replace('/[^0-9]/', '', $data['cpf']) : null;
        
        // Verifica se CPF já existe (se informado)
        if ($cpf) {
            $stmt = $pdo->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?");
            $stmt->execute([$cpf]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'CPF já cadastrado'], 400);
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO clientes (nome, cpf, contato, email) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['nome'] ?? '',
            $data['cpf'] ?? null,
            $data['contato'] ?? null,
            $data['email'] ?? null
        ]);
        
        jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;
        
    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? 0;
        
        $stmt = $pdo->prepare("UPDATE clientes SET nome = ?, cpf = ?, contato = ?, email = ? WHERE id = ?");
        $stmt->execute([
            $data['nome'] ?? '',
            $data['cpf'] ?? null,
            $data['contato'] ?? null,
            $data['email'] ?? null,
            $id
        ]);
        
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
