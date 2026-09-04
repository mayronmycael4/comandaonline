<?php
// Ponte de acesso direto (SSO) vinda do painel administrativo: valida o token
// assinado, autentica o administrador da instancia e entrega a sessao pronta.
require_once __DIR__.'/config.php';
header('Content-Type: text/html; charset=utf-8');

function sso_erro(string $mensagem): void
{
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:2rem;">'
        .'<h3>'.htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8').'</h3>'
        .'<p><a href="login.html">Ir para a tela de login</a></p></body>';
    exit;
}

$token = (string) ($_GET['token'] ?? '');
$partes = explode('.', $token, 2);
if (count($partes) !== 2) {
    sso_erro('Link de acesso invalido.');
}

[$payloadB64, $assinatura] = $partes;
$assinaturaEsperada = hash_hmac('sha256', $payloadB64, SSO_SHARED_SECRET);
if (!hash_equals($assinaturaEsperada, $assinatura)) {
    sso_erro('Link de acesso invalido.');
}

$payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
$payload = $payloadJson !== false ? json_decode($payloadJson, true) : null;

if (!is_array($payload) || empty($payload['login']) || (int) ($payload['exp'] ?? 0) < time()) {
    sso_erro('Link de acesso expirado. Gere um novo acesso pelo painel administrativo.');
}

$stmt = $pdo->prepare('SELECT * FROM funcionarios WHERE LOWER(login) = LOWER(?) AND is_active = 1');
$stmt->execute([$payload['login']]);
$funcionario = $stmt->fetch();

if (!$funcionario) {
    sso_erro('Usuario administrador nao encontrado nesta instancia.');
}

$permissoes = [];
if (!empty($funcionario['permissoes'])) {
    $decoded = json_decode($funcionario['permissoes'], true);
    $permissoes = is_array($decoded) ? array_values($decoded) : [];
}

try {
    $pdo->prepare('UPDATE funcionarios SET ultimo_login = NOW() WHERE id = ?')->execute([$funcionario['id']]);
    auditLog($pdo, 'login_sso_admin', 'funcionarios', $funcionario['id'], ['origem' => 'painel_administrativo']);
} catch (Throwable $e) {
    // nao bloqueia o acesso caso a auditoria falhe
}

$sessao = [
    'funcionarioId' => (int) $funcionario['id'],
    'nome' => $funcionario['nome'],
    'login' => $funcionario['login'],
    'isAdmin' => (bool) ($funcionario['is_admin'] ?? false),
    'permissoes' => $permissoes,
    'sessaoVersao' => (int) ($funcionario['sessao_versao'] ?? 1),
    'loggedInAt' => date('c'),
];

$adminUrl = is_string($payload['admin_url'] ?? null) ? $payload['admin_url'] : '';
$dadosSessao = [
    'sessao' => json_encode($sessao, JSON_UNESCAPED_UNICODE),
    'adminUrl' => $adminUrl,
];
?>
<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Entrando...</title></head>
<body>
<p style="font-family:sans-serif;padding:2rem;">Entrando no sistema...</p>
<script type="application/json" id="ssoDados"><?= json_encode($dadosSessao, JSON_UNESCAPED_UNICODE) ?></script>
<script src="sso_login.js"></script>
</body>
</html>
