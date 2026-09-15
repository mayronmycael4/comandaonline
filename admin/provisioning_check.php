<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/provisioning.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$empresaId = (int)($_GET['id'] ?? 0);
if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'empresa_id obrigatorio']);
    exit;
}

$stmt = pdo_saas()->prepare('SELECT id, nome, slug, db_name, table_prefix, provisionado_em FROM empresas WHERE id = ?');
$stmt->execute([$empresaId]);
$empresa = $stmt->fetch();

if (!$empresa) {
    http_response_code(404);
    echo json_encode(['error' => 'empresa nao encontrada']);
    exit;
}

$base = rtrim(TENANTS_CLIENTS_BASE_PATH, '/\\').DIRECTORY_SEPARATOR.$empresa['slug'];
$checks = [
    'base_dir' => is_dir($base),
    'root_htaccess' => is_file($base.DIRECTORY_SEPARATOR.'.htaccess'),
    'root_runtime' => is_file($base.DIRECTORY_SEPARATOR.'db_runtime_config.php'),
    'includes_dir' => is_dir($base.DIRECTORY_SEPARATOR.'includes'),
    'includes_runtime' => is_file($base.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'db_runtime_config.php'),
    'pages_login' => is_file($base.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'login.html'),
    'api_sso' => is_file($base.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'sso_login.php'),
];

$repaired = false;
if ($empresa['slug'] && $empresa['db_name'] && is_dir($base)) {
    $needsRuntime = !$checks['root_runtime'] || !$checks['includes_runtime'];
    if ($needsRuntime) {
        tenant_gerar_configuracao_runtime((string)$empresa['slug'], (string)$empresa['db_name'], (string)($empresa['table_prefix'] ?? ''));
        $repaired = true;
        $checks['root_runtime'] = is_file($base.DIRECTORY_SEPARATOR.'db_runtime_config.php');
        $checks['includes_runtime'] = is_file($base.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'db_runtime_config.php');
    }
}

echo json_encode([
    'empresa_id' => (int)$empresa['id'],
    'slug' => $empresa['slug'],
    'base_realpath' => realpath($base) ?: null,
    'public_login' => rtrim(TENANTS_BASE_URL, '/').'/'.$empresa['slug'].'/login.html',
    'provisionado_em' => $empresa['provisionado_em'],
    'repaired_runtime' => $repaired,
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
