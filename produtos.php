<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            jsonResponse($stmt->fetch());
        } elseif (isset($_GET['categoria'])) {
            $stmt = $pdo->prepare("SELECT * FROM produtos WHERE categoria = ? AND is_active = 1 ORDER BY nome");
            $stmt->execute([$_GET['categoria']]);
            jsonResponse($stmt->fetchAll());
        } else {
            $stmt = $pdo->query("SELECT * FROM produtos WHERE is_active = 1 ORDER BY categoria, nome");
            jsonResponse($stmt->fetchAll());
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        $setor = trim((string)($data['setor'] ?? 'cozinha'));
        $setor = (string)($data['setor_producao'] ?? $setor);
        if (!in_array($setor, ['cozinha','churrasqueira','bar','entrega_imediata'], true)) jsonResponse(['error'=>'Setor de producao invalido.'],400);
        $requerPreparo = isset($data['requer_preparo']) ? (int)(bool)$data['requer_preparo'] : (int)($setor !== 'entrega_imediata');
        
        $stmt = $pdo->prepare("INSERT INTO produtos (nome, categoria, preco, descricao, setor, setor_producao, requer_preparo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['nome'],
            $data['categoria'],
            $data['preco'],
            $data['descricao'] ?? null,
            $setor, $setor, $requerPreparo
        ]);
        
        jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;
        
    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? 0;
        $setor = trim((string)($data['setor'] ?? 'cozinha'));
        $setor = (string)($data['setor_producao'] ?? $setor);
        if (!in_array($setor, ['cozinha','churrasqueira','bar','entrega_imediata'], true)) jsonResponse(['error'=>'Setor de producao invalido.'],400);
        $requerPreparo = isset($data['requer_preparo']) ? (int)(bool)$data['requer_preparo'] : (int)($setor !== 'entrega_imediata');
        
        $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, categoria = ?, preco = ?, descricao = ?, setor = ?, setor_producao = ?, requer_preparo = ? WHERE id = ?");
        $stmt->execute([
            $data['nome'],
            $data['categoria'],
            $data['preco'],
            $data['descricao'] ?? null,
            $setor, $setor, $requerPreparo,
            $id
        ]);
        
        jsonResponse(['success' => true]);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE produtos SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
