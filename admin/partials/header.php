<?php
/** @var array $usuario */
/** @var string $tituloPagina */
$flash = flash_get();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tituloPagina ?? 'Painel Comanda Online') ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="topbar">
    <div>
        <span class="brand">Comanda Online · Painel</span>
        <a href="index.php">Dashboard</a>
        <a href="empresas.php">Clientes</a>
        <a href="planos.php">Planos</a>
    </div>
    <div>
        <span style="margin-right:1rem;color:#9ca3af;font-size:0.85rem;"><?= e($usuario['name'] ?? '') ?></span>
        <a href="logout.php">Sair</a>
    </div>
</div>
<div class="container">
    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['tipo']) ?>"><?= e($flash['mensagem']) ?></div>
    <?php endif; ?>
