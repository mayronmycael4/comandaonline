<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$funcionarioId = isset($_GET['funcionario_id']) ? (int)$_GET['funcionario_id'] : 0;
if ($funcionarioId <= 0) {
    jsonResponse(['error' => 'funcionario_id obrigatorio'], 400);
}

$stmt = $pdo->prepare("SELECT id, sessao_versao, sessao_revogada_em, is_active FROM funcionarios WHERE id = ? LIMIT 1");
$stmt->execute([$funcionarioId]);
$funcionario = $stmt->fetch();

if (!$funcionario || (int)($funcionario['is_active'] ?? 0) !== 1) {
    jsonResponse(['valida' => false, 'motivo' => 'usuario_inativo_ou_inexistente']);
}

jsonResponse([
    'valida' => true,
    'funcionario_id' => (int)$funcionario['id'],
    'sessao_versao' => (int)($funcionario['sessao_versao'] ?? 1),
    'sessao_revogada_em' => $funcionario['sessao_revogada_em'] ?? null
]);
