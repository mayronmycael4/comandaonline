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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        tenant_sincronizar_arquivos_da_aplicacao((string) $empresa['slug']);
        tenant_criar_compatibilidade_raiz((string) $empresa['slug']);
        flash_set('status', 'Arquivos da instancia atualizados. Banco e configuracao preservados.');
    } catch (Throwable $e) {
        error_log('[tenant_sync] empresa '.$id.': '.$e->getMessage());
        flash_set('error', 'Falha ao atualizar os arquivos da instancia. Consulte o log do servidor.');
    }
    redirect('empresas.php');
}

$tituloPagina = 'Atualizar instancia';
require __DIR__.'/partials/header.php';
?>
<h1>Atualizar instancia</h1>
<p><?= e($empresa['nome']) ?></p>
<form method="post">
    <?= csrf_field() ?>
    <button class="btn" type="submit">Atualizar arquivos</button>
    <a href="empresas.php">Voltar</a>
</form>
<?php require __DIR__.'/partials/footer.php'; ?>
