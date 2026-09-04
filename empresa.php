<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT * FROM empresa ORDER BY id DESC LIMIT 1");
            $empresa = $stmt->fetch();
            jsonResponse($empresa ?: null);
            break;
            
        case 'POST':
            $data = getJsonInput();
            
            $stmt = $pdo->query("SELECT id FROM empresa LIMIT 1");
            $existing = $stmt->fetch();
            
            if ($existing) {
                $stmt = $pdo->prepare("UPDATE empresa SET nome = ?, cnpj = ?, endereco = ?, telefone = ?, email = ? WHERE id = ?");
                $stmt->execute([
                    $data['nome'] ?? '',
                    $data['cnpj'] ?? null,
                    $data['endereco'] ?? null,
                    $data['telefone'] ?? null,
                    $data['email'] ?? null,
                    $existing['id']
                ]);
                jsonResponse(['success' => true, 'id' => $existing['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO empresa (nome, cnpj, endereco, telefone, email) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['nome'] ?? '',
                    $data['cnpj'] ?? null,
                    $data['endereco'] ?? null,
                    $data['telefone'] ?? null,
                    $data['email'] ?? null
                ]);
                jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Método não permitido'], 405);
    }
} catch (PDOException $e) {
    if ($method === 'GET') {
        jsonResponse(null);
    }
    jsonResponse(['error' => 'Banco de dados ainda não instalado. Execute o setup inicial.'], 500);
}
