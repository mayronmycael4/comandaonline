<?php
require_once 'config.php';

$mesa = trim((string)($_GET['mesa'] ?? ''));
if ($mesa === '') {
    jsonResponse(['error' => 'Informe a mesa'], 400);
}

$envSecret = trim((string)(getenv('QR_MENU_SECRET') ?: ''));
$secret = $envSecret !== ''
    ? $envSecret
    : hash('sha256', __DIR__ . DIRECTORY_SEPARATOR . 'qr_menu.php' . '|' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$token = substr(hash_hmac('sha256', 'mesa:' . $mesa, $secret), 0, 24);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$menuUrl = $scheme . '://' . $host . ($basePath !== '' ? $basePath . '/' : '/') . 'menu-mobile.html?mesa=' . rawurlencode($mesa) . '&token=' . rawurlencode($token);

jsonResponse([
    'success' => true,
    'mesa' => $mesa,
    'token' => $token,
    'url' => $menuUrl
]);
