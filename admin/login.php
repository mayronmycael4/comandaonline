<?php
require_once __DIR__.'/config.php';

if (!empty($_SESSION['admin_user_id'])) {
    redirect('index.php');
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim((string) ($_POST['email'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');

    $stmt = pdo_saas()->prepare('SELECT * FROM users WHERE email = ? AND is_superadmin = 1 LIMIT 1');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['password'])) {
        $_SESSION['admin_user_id'] = $usuario['id'];
        $_SESSION['admin_user_name'] = $usuario['name'];
        $_SESSION['admin_user_email'] = $usuario['email'];
        redirect('index.php');
    }

    $erro = 'Credenciais invalidas.';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Login - Painel Comanda Online</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <h1>Painel Comanda Online</h1>
        <?php if ($erro): ?>
            <div class="flash flash-error"><?= e($erro) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-field">
                <label>E-mail</label>
                <input type="email" name="email" required autofocus>
            </div>
            <div class="form-field">
                <label>Senha</label>
                <input type="password" name="senha" required>
            </div>
            <button type="submit" class="btn" style="width:100%">Entrar</button>
        </form>
    </div>
</div>
</body>
</html>
