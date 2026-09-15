<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/provisioning.php';

require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'empresa_id invalido';
    exit;
}

$stmt = pdo_saas()->prepare('SELECT id, nome, slug, db_name, table_prefix, provisionado_em FROM empresas WHERE id = ?');
$stmt->execute([$id]);
$empresa = $stmt->fetch();

if (!$empresa || empty($empresa['slug']) || empty($empresa['provisionado_em'])) {
    http_response_code(404);
    echo 'instancia nao encontrada ou nao provisionada';
    exit;
}

tenant_sincronizar_arquivos_da_aplicacao((string) $empresa['slug']);
tenant_gerar_configuracao_runtime((string) $empresa['slug'], (string) $empresa['db_name'], (string) ($empresa['table_prefix'] ?? ''));
tenant_criar_compatibilidade_raiz((string) $empresa['slug']);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'empresa_id' => (int) $empresa['id'],
    'slug' => $empresa['slug'],
    'synced_at' => date('c'),
], JSON_UNESCAPED_SLASHES);
