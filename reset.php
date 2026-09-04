<?php
require_once 'config.php';

$hostName = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$isLocalhost = in_array($hostName, ['localhost', '127.0.0.1', '::1'], true)
    || strpos($hostName, '.local') !== false;

if (!$isLocalhost) {
    jsonResponse(['error' => 'Endpoint desativado em producao'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$login = $data['login'] ?? '';
$senha = $data['senha'] ?? '';
$confirm = $data['confirm'] ?? '';

if ($confirm !== 'EXCLUIR TUDO') {
    jsonResponse(['error' => 'Confirmacao invalida'], 400);
}

if (empty($login) || empty($senha)) {
    jsonResponse(['error' => 'Login e senha sao obrigatorios'], 400);
}

// Verifica se e admin
$stmt = $pdo->prepare("SELECT id, senha, is_admin, is_active FROM funcionarios WHERE login = ? LIMIT 1");
$stmt->execute([$login]);
$user = $stmt->fetch();

if (!$user || (int)$user['is_active'] !== 1) {
    jsonResponse(['error' => 'Usuario invalido'], 401);
}

if ((int)$user['is_admin'] !== 1) {
    jsonResponse(['error' => 'Apenas administrador pode executar esta acao'], 403);
}

$senhaOk = password_verify($senha, $user['senha']) || $senha === $user['senha'];
if (!$senhaOk) {
    jsonResponse(['error' => 'Senha incorreta'], 401);
}

try {
    $pdo->beginTransaction();

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Ordem importa por FK
    $tables = [
        'comanda_itens',
        'cliente_historico',
        'lista_compras',
        'sessoes',
        'comandas',
        'clientes',
        'estoque',
        'produtos',
        'funcionarios',
        'empresa'
    ];

    foreach ($tables as $t) {
        $pdo->exec("TRUNCATE TABLE `$t`");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->commit();

    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['error' => 'Erro ao excluir dados: ' . $e->getMessage()], 500);
}
