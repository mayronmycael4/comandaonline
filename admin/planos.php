<?php
require_once __DIR__.'/config.php';
$usuario = require_login();
$pdo = pdo_saas();

$planos = $pdo->query("
    SELECT p.*, COUNT(e.id) AS total_empresas
    FROM planos p
    LEFT JOIN empresas e ON e.plano_id = p.id
    GROUP BY p.id
    ORDER BY p.nome
")->fetchAll();

$tituloPagina = 'Planos';
require __DIR__.'/partials/header.php';
?>
<h1>Planos</h1>
<p><a href="plano_form.php" class="btn">+ Novo plano</a></p>

<div class="card">
    <table>
        <thead><tr><th>Nome</th><th>Valor mensal</th><th>Clientes</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($planos as $p): ?>
            <tr>
                <td><?= e($p['nome']) ?></td>
                <td>R$ <?= number_format((float) $p['valor_mensal'], 2, ',', '.') ?></td>
                <td><?= (int) $p['total_empresas'] ?></td>
                <td><?= $p['ativo'] ? '<span class="badge badge-verde">ativo</span>' : '<span class="badge badge-cinza">inativo</span>' ?></td>
                <td><a href="plano_form.php?id=<?= (int) $p['id'] ?>">Editar</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$planos): ?>
            <tr><td colspan="5" style="color:#6b7280;">Nenhum plano cadastrado.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__.'/partials/footer.php'; ?>
