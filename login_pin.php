<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$login = trim((string)($data['login'] ?? ''));
$pin = trim((string)($data['pin'] ?? ''));

if ($login === '' || $pin === '') {
    jsonResponse(['error' => 'login e pin obrigatorios'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM funcionarios WHERE login = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$login]);
$funcionario = $stmt->fetch();
if (!$funcionario) {
    jsonResponse(['error' => 'Usuario nao encontrado'], 401);
}

if (!empty($funcionario['blocked_until']) && strtotime((string)$funcionario['blocked_until']) > time()) {
    jsonResponse(['error' => 'Usuario temporariamente bloqueado'], 423);
}

$pinHash = (string)($funcionario['pin_hash'] ?? '');
if ($pinHash === '' || !password_verify($pin, $pinHash)) {
    $tentativas = (int)($funcionario['failed_login_attempts'] ?? 0) + 1;
    $blockedUntil = null;
    if ($tentativas >= 5) {
        $blockedUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $tentativas = 5;
    }
    $stmt = $pdo->prepare("UPDATE funcionarios SET failed_login_attempts = ?, blocked_until = ? WHERE id = ?");
    $stmt->execute([$tentativas, $blockedUntil, $funcionario['id']]);

    jsonResponse(['error' => 'PIN invalido'], 401);
}

if (!empty($funcionario['permissoes'])) {
    $decoded = json_decode($funcionario['permissoes'], true);
    $funcionario['permissoes'] = is_array($decoded) ? array_values(array_unique($decoded)) : [];
} else {
    $funcionario['permissoes'] = [];
}

$funcionario['role'] = normalizeRole($funcionario['role'] ?? (!empty($funcionario['is_admin']) ? 'admin' : 'garcom'));
$funcionario['nome_exibicao'] = $funcionario['nome_exibicao'] ?? $funcionario['nome'];
$funcionario['sessao_versao'] = isset($funcionario['sessao_versao']) ? (int)$funcionario['sessao_versao'] : 1;

$stmt = $pdo->prepare("UPDATE funcionarios SET failed_login_attempts = 0, blocked_until = NULL, ultimo_login = NOW() WHERE id = ?");
$stmt->execute([$funcionario['id']]);

unset($funcionario['senha']);
unset($funcionario['pin_hash']);

try {
    $token = bin2hex(random_bytes(32));
} catch (Exception $e) {
    $token = sha1(uniqid((string) mt_rand(), true));
}
$expires = date('Y-m-d H:i:s', strtotime('+12 hours'));

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
    // nao bloqueia autenticacao por PIN caso historico de sessoes falhe
}

auditLog($pdo, 'login_pin_sucesso', 'funcionarios', $funcionario['id'] ?? null, [
    'token_emitido' => !empty($token)
], [
    'actor_id' => $funcionario['id'] ?? null,
    'actor_nome' => $funcionario['nome_exibicao'] ?? $funcionario['nome'] ?? null,
    'actor_login' => $funcionario['login'] ?? null
]);

jsonResponse([
    'success' => true,
    'token' => $token,
    'expires' => $expires,
    'funcionario' => $funcionario
]);
