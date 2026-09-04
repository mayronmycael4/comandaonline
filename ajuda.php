<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

// Garante tabela existe
function ensureFeedbackTable($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            funcionario_id INT NULL,
            funcionario_nome VARCHAR(120) NULL,
            tipo ENUM('sugestao','erro','melhoria','outro') NOT NULL DEFAULT 'outro',
            mensagem TEXT NOT NULL,
            lido TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

try {
    ensureFeedbackTable($pdo);

    switch ($method) {
        case 'GET':
            if (isset($_GET['funcionario_id'])) {
                $fid = (int)$_GET['funcionario_id'];
                $stmt = $pdo->prepare("SELECT * FROM feedbacks WHERE funcionario_id = ? ORDER BY created_at DESC LIMIT 50");
                $stmt->execute([$fid]);
            } else {
                $stmt = $pdo->query("SELECT * FROM feedbacks ORDER BY created_at DESC LIMIT 200");
            }
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            $data = getJsonInput();
            $tipo = in_array($data['tipo'] ?? '', ['sugestao','erro','melhoria','outro']) ? $data['tipo'] : 'outro';
            $mensagem = trim($data['mensagem'] ?? '');
            if (!$mensagem) { jsonResponse(['error' => 'Mensagem obrigatória'], 400); }

            $stmt = $pdo->prepare("INSERT INTO feedbacks (funcionario_id, funcionario_nome, tipo, mensagem) VALUES (?,?,?,?)");
            $stmt->execute([
                isset($data['funcionario_id']) && $data['funcionario_id'] ? (int)$data['funcionario_id'] : null,
                $data['funcionario_nome'] ?? null,
                $tipo,
                $mensagem
            ]);
            jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'PUT':
            // Marcar como lido (admin)
            $data = getJsonInput();
            $id = (int)($data['id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE feedbacks SET lido = 1 WHERE id = ?")->execute([$id]);
            }
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['error' => 'Método não permitido'], 405);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
