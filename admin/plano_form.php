<?php
require_once __DIR__.'/config.php';
$usuario = require_login();
$pdo = pdo_saas();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$plano = null;
$modulosAtivos = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM planos WHERE id = ?');
    $stmt->execute([$id]);
    $plano = $stmt->fetch();
    if (!$plano) {
        flash_set('error', 'Plano nao encontrado.');
        redirect('planos.php');
    }
    $modulosAtivos = json_decode((string) $plano['modulos'], true) ?: [];
}

$tituloPagina = $plano ? 'Editar plano: '.$plano['nome'] : 'Novo plano';
require __DIR__.'/partials/header.php';
?>
<h1><?= e($tituloPagina) ?></h1>

<div class="card">
    <form method="post" action="plano_salvar.php">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="<?= $plano ? 'atualizar' : 'criar' ?>">
        <?php if ($plano): ?><input type="hidden" name="plano_id" value="<?= (int) $plano['id'] ?>"><?php endif; ?>

        <div class="grid grid-3">
            <div class="form-field"><label>Nome *</label><input type="text" name="nome" required value="<?= e($plano['nome'] ?? '') ?>"></div>
            <div class="form-field"><label>Valor mensal (R$) *</label><input type="number" step="0.01" name="valor_mensal" required value="<?= e((string) ($plano['valor_mensal'] ?? '0.00')) ?>"></div>
            <div class="form-field"><label>Limite de usuarios</label><input type="number" name="limite_usuarios" value="<?= e((string) ($plano['limite_usuarios'] ?? '')) ?>"></div>
        </div>

        <div class="form-field">
            <label><input type="checkbox" name="ativo" value="1" <?= ($plano['ativo'] ?? 1) ? 'checked' : '' ?>> Plano ativo</label>
        </div>

        <h3>Modulos disponiveis</h3>
        <div class="checkbox-lista">
            <?php foreach (MODULOS_DISPONIVEIS as $chave => $descricao): ?>
                <label>
                    <input type="checkbox" name="modulos[]" value="<?= e($chave) ?>" <?= in_array($chave, $modulosAtivos, true) ? 'checked' : '' ?>>
                    <?= e($descricao) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-field" style="margin-top:0.85rem;"><label>Observacoes</label><textarea name="observacoes"><?= e($plano['observacoes'] ?? '') ?></textarea></div>

        <div class="acoes">
            <a href="planos.php" class="btn btn-secundario">Voltar</a>
            <button type="submit" class="btn">Salvar</button>
        </div>
    </form>
</div>

<?php require __DIR__.'/partials/footer.php'; ?>
