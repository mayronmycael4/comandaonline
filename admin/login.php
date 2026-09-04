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
    <div class="admin-login-robot-rain" aria-hidden="true"></div>
    <div class="login-box admin-login-box">
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
<script>
const ADMIN_LOGIN_ROBOT_IMAGE = '../login-robot.svg';

document.addEventListener('DOMContentLoaded', () => {
    const rain = document.querySelector('.admin-login-robot-rain');
    if (!rain || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const isMobile = window.innerWidth < 768;
    const robotCount = isMobile ? 14 : 28;
    const minSize = isMobile ? 26 : 34;
    const maxSize = isMobile ? 52 : 76;

    for (let index = 0; index < robotCount; index += 1) {
        const robot = document.createElement('img');
        robot.className = 'admin-login-robot';
        robot.src = ADMIN_LOGIN_ROBOT_IMAGE;
        robot.alt = '';
        robot.style.left = `${Math.random() * 100}%`;
        robot.style.setProperty('--robot-size', `${Math.round(minSize + Math.random() * (maxSize - minSize))}px`);
        robot.style.setProperty('--robot-duration', `${(8 + Math.random() * 8).toFixed(2)}s`);
        robot.style.setProperty('--robot-delay', `${(-Math.random() * 16).toFixed(2)}s`);
        robot.style.setProperty('--robot-opacity', (0.18 + Math.random() * 0.3).toFixed(2));
        robot.style.setProperty('--robot-rotation', `${Math.round(-18 + Math.random() * 36)}deg`);
        rain.appendChild(robot);
    }
});
</script>
</body>
</html>
