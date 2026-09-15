<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$login = trim($data['login'] ?? '');
$senha = (string) ($data['senha'] ?? '');

if (empty($login) || empty($senha)) {
    jsonResponse(['error' => 'Login e senha sao obrigatorios'], 400);
}

// Busca funcionario
$stmt = $pdo->prepare("SELECT * FROM funcionarios WHERE LOWER(login) = LOWER(?) AND is_active = 1");
$stmt->execute([$login]);
$funcionario = $stmt->fetch();

if (!$funcionario) {
    auditLog($pdo, 'login_falha', 'funcionarios', null, [
        'login_informado' => $login,
        'motivo' => 'usuario_nao_encontrado_ou_inativo'
    ], [
        'actor_login' => $login
    ]);
    jsonResponse(['error' => 'Login ou senha incorretos'], 401);
}

// Verifica senha
$senhaValida = false;

if (!empty($funcionario['senha'])) {
    $senhaBanco = trim((string) $funcionario['senha']);
    $senhaDigitada = $senha;
    $senhaDigitadaTrim = trim($senha);

    $senhaValida = password_verify($senhaDigitada, $senhaBanco)
        || password_verify($senhaDigitadaTrim, $senhaBanco)
        || $senhaDigitada === $senhaBanco
        || $senhaDigitadaTrim === $senhaBanco;

    if ($senhaValida && strpos($senhaBanco, '$') === 0 && password_needs_rehash($senhaBanco, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senhaDigitada, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE funcionarios SET senha = ? WHERE id = ?");
        $stmt->execute([$novoHash, $funcionario['id']]);
        $funcionario['senha'] = $novoHash;
    }
}

if (!$senhaValida) {
    auditLog($pdo, 'login_falha', 'funcionarios', $funcionario['id'] ?? null, [
        'login_informado' => $login,
        'motivo' => 'senha_invalida'
    ], [
        'actor_id' => $funcionario['id'] ?? null,
        'actor_nome' => $funcionario['nome'] ?? null,
        'actor_login' => $login
    ]);
    jsonResponse(['error' => 'Login ou senha incorretos'], 401);
}

if (!empty($funcionario['permissoes'])) {
    $decoded = json_decode($funcionario['permissoes'], true);
    $funcionario['permissoes'] = is_array($decoded) ? array_values(array_unique($decoded)) : [];
} else {
    $funcionario['permissoes'] = [];
}

$funcionario['sessao_versao'] = isset($funcionario['sessao_versao']) ? (int)$funcionario['sessao_versao'] : 1;

// Remove senha da resposta
unset($funcionario['senha']);

// Cria sessao
try {
    $token = bin2hex(random_bytes(32));
} catch (Exception $e) {
    $token = sha1(uniqid((string) mt_rand(), true));
}
$expires = date('Y-m-d H:i:s', strtotime('+8 hours'));

try {
    $stmt = $pdo->prepare("INSERT INTO sessoes (funcionario_id, token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $funcionario['id'],
        $token,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $expires
    ]);
} catch (PDOException $e) {
    // O front já usa localStorage para sessão; falha ao gravar histórico não deve bloquear login.
}

auditLog($pdo, 'login_sucesso', 'funcionarios', $funcionario['id'] ?? null, [
    'token_emitido' => !empty($token)
], [
    'actor_id' => $funcionario['id'] ?? null,
    'actor_nome' => $funcionario['nome'] ?? null,
    'actor_login' => $funcionario['login'] ?? $login
]);

jsonResponse([
    'success' => true,
    'funcionario' => $funcionario,
    'token' => $token,
    'expires_at' => $expires
]);
