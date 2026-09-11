<?php
// Teste local: nao altera empresas, bancos nem pastas de clientes existentes.
require_once __DIR__.'/../admin/provisioning.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$root = sys_get_temp_dir().'/comanda-provisioning-'.bin2hex(random_bytes(6));
mkdir($root);
mkdir($root.'/source');
mkdir($root.'/source/.git');
mkdir($root.'/source/clientes');
file_put_contents($root.'/source/.env.production', 'private');
file_put_contents($root.'/source/.htaccess', 'Require all granted');
file_put_contents($root.'/source/index.html', 'ok');
try {
    tenant_copiar_diretorio($root.'/source', $root.'/target', TENANTS_EXCLUDE);
    check(is_file($root.'/target/index.html'), 'Aplicacao ausente');
    check(is_file($root.'/target/.htaccess'), 'Configuracao Apache ausente');
    check(!file_exists($root.'/target/.env.production'), 'Arquivo privado copiado');
    check(!file_exists($root.'/target/.git'), 'Git copiado');
    check(!file_exists($root.'/target/clientes'), 'Instancias copiadas recursivamente');
    try {
        tenant_copiar_arquivos_da_aplicacao('../escape');
        throw new RuntimeException('Slug inseguro aceito');
    } catch (TenantProvisioningException $e) {
        check(str_contains($e->getMessage(), 'invalido'), 'Erro inesperado');
    }
    echo "PASS: copia da aplicacao, exclusoes e isolamento de caminhos\n";
} finally {
    // Somente o diretorio temporario criado por este processo.
    check(str_starts_with(realpath($root), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR), 'Diretorio fora da area temporaria');
    tenant_remover_diretorio($root);
}
