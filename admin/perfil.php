<?php
require_once __DIR__.'/config.php';
$usuario = require_login();
$tituloPagina = 'Perfil';
require __DIR__.'/partials/header.php';
?>
<h1>Perfil</h1>
<div class="card">
    <h3>Dados do usuario</h3>
    <p>Nome: <strong><?= e($usuario['name']) ?></strong></p>
    <p>E-mail: <?= e($usuario['email']) ?></p>
    <p>Funcao: Superadministrador</p>
    <p>Escolha o tema no menu da sua conta, no rodape da barra lateral.</p>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>
