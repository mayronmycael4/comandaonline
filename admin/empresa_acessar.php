<?php
// Redireciona o administrador direto para o sistema do cliente (SSO), sem tela de login intermediaria.
require_once __DIR__.'/config.php';
$usuario = require_login();
$pdo = pdo_saas();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM empresas WHERE id = ?');
$stmt->execute([$id]);
$empresa = $stmt->fetch();

if (!$empresa) {
    flash_set('error', 'Cliente nao encontrado.');
    redirect('empresas.php');
}

$url = montar_url_acesso_direto($empresa);
if (!$url) {
    flash_set('error', 'Esta instancia ainda nao foi provisionada, entao nao e possivel acessar diretamente.');
    redirect('empresa_form.php?id='.$id);
}

redirect($url);
