<?php
require_once __DIR__.'/config.php';
$usuario = require_login();
$pdo = pdo_saas();

$empresas = $pdo->query("
    SELECT e.*, p.nome AS plano_nome, l.data_vencimento, l.status AS licenca_status
    FROM empresas e
    LEFT JOIN planos p ON p.id = e.plano_id
    LEFT JOIN licencas l ON l.empresa_id = e.id AND l.status = 'ativa'
    ORDER BY e.created_at DESC
")->fetchAll();

$tituloPagina = 'Clientes';
require __DIR__.'/partials/header.php';
?>
<h1>Clientes</h1>
<p><a href="empresa_form.php" class="btn">+ Cadastrar cliente</a></p>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>Plano</th>
                <th>Status</th>
                <th>Vencimento</th>
                <th>Instancia</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($empresas as $emp): ?>
            <tr>
                <td><?= e($emp['nome']) ?></td>
                <td><?= e($emp['plano_nome'] ?? '-') ?></td>
                <td>
                    <?php if ($emp['status'] === 'ativo'): ?>
                        <span class="badge badge-verde">ativo</span>
                    <?php elseif ($emp['status'] === 'bloqueado'): ?>
                        <span class="badge badge-vermelho">bloqueado</span>
                    <?php else: ?>
                        <span class="badge badge-cinza"><?= e($emp['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $emp['data_vencimento'] ? date('d/m/Y', strtotime($emp['data_vencimento'])) : '-' ?></td>
                <td>
                    <?php if ($emp['provisionado_em']): ?>
                        <span class="badge badge-verde">provisionada</span>
                    <?php elseif ($emp['provisionamento_erro']): ?>
                        <span class="badge badge-vermelho">falhou</span>
                    <?php else: ?>
                        <span class="badge badge-amarelo">pendente</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <a href="empresa_form.php?id=<?= (int) $emp['id'] ?>">Editar</a>
                    <?php if ($emp['provisionado_em']): ?>
                        · <a href="empresa_acessar.php?id=<?= (int) $emp['id'] ?>" target="_blank">Acessar</a>
                        · <a href="#" class="js-compartilhar" data-nome="<?= e($emp['nome']) ?>" data-url="<?= e(rtrim(TENANTS_BASE_URL,'/').'/'.$emp['slug'].'/login.html') ?>" data-login="<?= e($emp['login_admin']) ?>" data-email="<?= e($emp['email'] ?? '') ?>">Compartilhar</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$empresas): ?>
            <tr><td colspan="6" style="color:#6b7280;">Nenhum cliente cadastrado.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__.'/partials/footer.php'; ?>
