<?php
require_once 'config.php';
require_once __DIR__ . '/time_contract.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT * FROM empresa ORDER BY id DESC LIMIT 1");
            $empresa = $stmt->fetch();
            if ($empresa) $empresa['server_time_ms'] = (int)(microtime(true) * 1000);
            jsonResponse($empresa ?: null);
            break;
            
        case 'POST':
            $data = getJsonInput();
            
            $stmt = $pdo->query("SELECT id, timezone FROM empresa ORDER BY id DESC LIMIT 1");
            $existing = $stmt->fetch();
            try {
                $timezone = comanda_timezone((string)($data['timezone'] ?? ($existing['timezone'] ?? 'America/Belem')))->getName();
            } catch (InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 400);
            }
            
            if ($existing) {
                $stmt = $pdo->prepare("UPDATE empresa SET nome = ?, cnpj = ?, endereco = ?, telefone = ?, email = ?, timezone = ? WHERE id = ?");
                $stmt->execute([
                    $data['nome'] ?? '',
                    $data['cnpj'] ?? null,
                    $data['endereco'] ?? null,
                    $data['telefone'] ?? null,
                    $data['email'] ?? null,
                    $timezone,
                    $existing['id']
                ]);
                jsonResponse(['success' => true, 'id' => $existing['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO empresa (nome, cnpj, endereco, telefone, email, timezone) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['nome'] ?? '',
                    $data['cnpj'] ?? null,
                    $data['endereco'] ?? null,
                    $data['telefone'] ?? null,
                    $data['email'] ?? null,
                    $timezone
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
