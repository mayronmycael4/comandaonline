<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/provisioning.php';
$usuario = require_login();
$pdo = pdo_saas();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$empresa = null;
$licenca = null;
$pagamentos = [];
$historico = [];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM empresas WHERE id = ?');
    $stmt->execute([$id]);
    $empresa = $stmt->fetch();
    if (!$empresa) {
        flash_set('error', 'Cliente nao encontrado.');
        redirect('empresas.php');
    }

    $stmt = $pdo->prepare("SELECT * FROM licencas WHERE empresa_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$id]);
    $licenca = $stmt->fetch() ?: null;

    if ($licenca) {
        $stmt = $pdo->prepare('SELECT * FROM licenca_pagamentos WHERE licenca_id = ? ORDER BY data_pagamento DESC');
        $stmt->execute([$licenca['id']]);
        $pagamentos = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare('SELECT a.*, u.name AS user_nome FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.empresa_id = ? ORDER BY a.created_at DESC LIMIT 30');
    $stmt->execute([$id]);
    $historico = $stmt->fetchAll();
}

$planos = $pdo->query("SELECT * FROM planos WHERE ativo = 1 ORDER BY nome")->fetchAll();

$acessoGerado = null;
if ($empresa && !empty($_SESSION['acesso_gerado']) && (int) $_SESSION['acesso_gerado']['empresa_id'] === (int) $empresa['id']) {
    $acessoGerado = $_SESSION['acesso_gerado'];
    unset($_SESSION['acesso_gerado']);
}

$tituloPagina = $empresa ? 'Editar cliente: '.$empresa['nome'] : 'Cadastrar cliente';
require __DIR__.'/partials/header.php';
?>
<h1><?= e($tituloPagina) ?></h1>

<?php if ($empresa): ?>
    <div class="card">
        <h3>Instancia do cliente</h3>
        <?php if ($empresa['provisionado_em']): ?>
            <p>Status: Provisionada em <?= date('d/m/Y H:i', strtotime($empresa['provisionado_em'])) ?></p>
            <p>Banco de dados: <strong><?= e($empresa['db_name']) ?></strong></p>
            <p>Link de acesso: <a href="<?= e(TENANTS_BASE_URL.'/'.$empresa['slug'].'/login.html') ?>" target="_blank"><?= e(TENANTS_BASE_URL.'/'.$empresa['slug'].'/login.html') ?></a></p>
            <p>Login do administrador: <strong><?= e($empresa['login_admin']) ?></strong></p>
            <div class="acoes">
                <a href="empresa_acessar.php?id=<?= (int) $empresa['id'] ?>" class="btn" target="_blank">Acessar Página</a>
                <a href="#" class="btn btn-secundario js-compartilhar" data-nome="<?= e($empresa['nome']) ?>" data-url="<?= e(rtrim(TENANTS_BASE_URL,'/').'/'.$empresa['slug'].'/login.html') ?>" data-login="<?= e($empresa['login_admin']) ?>" data-senha="<?= e($acessoGerado['senha'] ?? '') ?>" data-email="<?= e($empresa['email'] ?? '') ?>">Compartilhar Acesso</a>
            </div>
        <?php elseif ($empresa['provisionamento_erro']): ?>

            <p style="color:var(--cor-erro);">Falha no provisionamento: <?= e($empresa['provisionamento_erro']) ?></p>
            <form method="post" action="empresa_acoes.php" class="grid grid-4" style="align-items:end;">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="reprovisionar">
                <input type="hidden" name="empresa_id" value="<?= (int) $empresa['id'] ?>">
                <div class="form-field"><label>Nome do administrador *</label><input type="text" name="admin_nome" required></div>
                <div class="form-field"><label>Login de acesso *</label><input type="text" name="admin_login" value="<?= e($empresa['login_admin']) ?>" required></div>
                <div class="form-field"><label>Senha inicial *</label><input type="password" name="admin_senha" required minlength="8"></div>
                <button type="submit" class="btn">Tentar provisionar novamente</button>
            </form>
        <?php else: ?>
            <p style="color:#6b7280;">Esta empresa ainda nao foi provisionada.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="post" action="empresa_salvar.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="<?= $empresa ? 'atualizar' : 'criar' ?>">
        <?php if ($empresa): ?><input type="hidden" name="empresa_id" value="<?= (int) $empresa['id'] ?>"><?php endif; ?>

        <div class="grid grid-2">
            <div class="form-field"><label>Nome *</label><input type="text" name="nome" required value="<?= e($empresa['nome'] ?? '') ?>"></div>
            <div class="form-field"><label>Nome exibido</label><input type="text" name="nome_exibicao" value="<?= e($empresa['nome_exibicao'] ?? '') ?>"></div>
            <div class="form-field"><label>Telefone</label><input type="text" name="telefone" value="<?= e($empresa['telefone'] ?? '') ?>"></div>
            <div class="form-field"><label>WhatsApp</label><input type="text" name="whatsapp" value="<?= e($empresa['whatsapp'] ?? '') ?>"></div>
            <div class="form-field"><label>E-mail</label><input type="email" name="email" value="<?= e($empresa['email'] ?? '') ?>"></div>
            <div class="form-field"><label>Segmento</label><input type="text" name="segmento" value="<?= e($empresa['segmento'] ?? '') ?>"></div>
            <div class="form-field"><label>Cidade</label><input type="text" name="cidade" value="<?= e($empresa['cidade'] ?? '') ?>"></div>
            <div class="form-field"><label>Estado</label><input type="text" maxlength="2" name="estado" value="<?= e($empresa['estado'] ?? '') ?>"></div>
            <div class="form-field" style="grid-column: span 2;"><label>Endereco</label><input type="text" name="endereco" value="<?= e($empresa['endereco'] ?? '') ?>"></div>
            <div class="form-field">
                <label>Plano *</label>
                <select name="plano_id" required>
                    <?php foreach ($planos as $plano): ?>
                        <option value="<?= (int) $plano['id'] ?>" <?= ($empresa['plano_id'] ?? null) == $plano['id'] ? 'selected' : '' ?>><?= e($plano['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field"><label>Cor primaria</label><input type="color" name="cor_primaria" value="<?= e($empresa['cor_primaria'] ?? '#4f46e5') ?>"></div>
            <div class="form-field"><label>Cor secundaria</label><input type="color" name="cor_secundaria" value="<?= e($empresa['cor_secundaria'] ?? '#111827') ?>"></div>
            <div class="form-field">
                <label>Logotipo (PNG/JPG, opcional)</label>
                <?php if (!empty($empresa['logo_path'])): ?>
                    <img src="storage/logos/<?= e(basename($empresa['logo_path'])) ?>" alt="Logo atual" style="height:40px;margin-bottom:0.4rem;border-radius:4px;display:block;">
                <?php endif; ?>
                <input type="file" name="logo" accept="image/*">
            </div>
            <div class="form-field" style="grid-column: span 2;"><label>Mensagem de boas-vindas</label><textarea name="mensagem_boas_vindas"><?= e($empresa['mensagem_boas_vindas'] ?? '') ?></textarea></div>
        </div>

        <?php if (!$empresa): ?>
            <h3 style="margin-top:1rem;">Acesso inicial do administrador</h3>
            <div class="grid grid-3">
                <div class="form-field"><label>Nome do administrador *</label><input type="text" name="admin_nome" required></div>
                <div class="form-field"><label>Login de acesso *</label><input type="text" name="admin_login" required></div>
                <div class="form-field"><label>Senha inicial *</label><input type="password" name="admin_senha" required minlength="8"></div>
            </div>
        <?php endif; ?>

        <div class="acoes">
            <a href="empresas.php" class="btn btn-secundario">Voltar</a>
            <button type="submit" class="btn">Salvar</button>
        </div>
    </form>
</div>

<?php if ($empresa): ?>
    <div class="card">
        <h3>Licenca e mensalidade</h3>
        <?php if ($licenca): ?>
            <p>Status atual: <strong><?= e($licenca['status']) ?></strong> · Vencimento: <strong><?= $licenca['data_vencimento'] ? date('d/m/Y', strtotime($licenca['data_vencimento'])) : '-' ?></strong> · Valor: <strong>R$ <?= number_format((float) $licenca['valor_mensalidade'], 2, ',', '.') ?></strong></p>

            <form method="post" action="empresa_acoes.php" class="grid grid-4" style="align-items:end;">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="renovar">
                <input type="hidden" name="empresa_id" value="<?= (int) $empresa['id'] ?>">
                <div class="form-field"><label>Valor pago *</label><input type="number" step="0.01" name="valor_pago" required value="<?= e((string) $licenca['valor_mensalidade']) ?>"></div>
                <div class="form-field"><label>Data do pagamento *</label><input type="date" name="data_pagamento" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-field"><label>Dias adicionais *</label><input type="number" name="dias_adicionais" required value="30"></div>
                <button type="submit" class="btn">Confirmar pagamento e renovar</button>
            </form>

            <hr style="margin:1rem 0;border:none;border-top:1px solid #e5e7eb;">
            <h3>Regras de aviso e carencia</h3>
            <form method="post" action="empresa_acoes.php" class="grid grid-4" style="align-items:end;">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="atualizar_licenca">
                <input type="hidden" name="empresa_id" value="<?= (int) $empresa['id'] ?>">
                <div class="form-field"><label>Dias de carencia *</label><input type="number" name="dias_carencia" required value="<?= (int) $licenca['dias_carencia'] ?>"></div>
                <div class="form-field"><label>Dias de aviso antes do vencimento *</label><input type="number" name="dias_aviso_antecedencia" required value="<?= (int) $licenca['dias_aviso_antecedencia'] ?>"></div>
                <div class="form-field"><label><input type="checkbox" name="aviso_fechavel" value="1" <?= $licenca['aviso_fechavel'] ? 'checked' : '' ?>> Cliente pode fechar o aviso</label></div>
                <div class="form-field"><label><input type="checkbox" name="aviso_recorrente" value="1" <?= $licenca['aviso_recorrente'] ? 'checked' : '' ?>> Aviso reaparece a cada acesso</label></div>
                <button type="submit" class="btn">Salvar regras</button>
            </form>
        <?php else: ?>
            <p style="color:#6b7280;">Nenhuma licenca registrada.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Acesso do administrador</h3>
        <p>Usuario administrador (na instancia do cliente): <strong><?= e($empresa['login_admin'] ?? '-') ?></strong></p>
        <form method="post" action="empresa_acoes.php" class="grid grid-3" style="align-items:end;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="resetar_senha">
            <input type="hidden" name="empresa_id" value="<?= (int) $empresa['id'] ?>">
            <div class="form-field"><label>Nova senha *</label><input type="password" name="nova_senha" required minlength="8"></div>
            <button type="submit" class="btn">Redefinir senha</button>
        </form>
    </div>

    <div class="card">
        <h3>Bloqueio</h3>
        <form method="post" action="empresa_acoes.php" class="acoes">
            <?= csrf_field() ?>
            <input type="hidden" name="empresa_id" value="<?= (int) $empresa['id'] ?>">
            <?php if ($empresa['status'] === 'bloqueado'): ?>
                <input type="hidden" name="acao" value="desbloquear">
                <button type="submit" class="btn btn-sucesso">Desbloquear cliente</button>
            <?php else: ?>
                <input type="hidden" name="acao" value="bloquear">
                <button type="submit" class="btn btn-perigo">Bloquear cliente</button>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Historico de pagamentos</h3>
        <?php if (!$pagamentos): ?>
            <p style="color:#6b7280;">Nenhum pagamento registrado.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Data</th><th>Valor</th><th>Vencimento anterior</th><th>Novo vencimento</th></tr></thead>
                <tbody>
                <?php foreach ($pagamentos as $p): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($p['data_pagamento'])) ?></td>
                        <td>R$ <?= number_format((float) $p['valor_pago'], 2, ',', '.') ?></td>
                        <td><?= $p['vencimento_anterior'] ? date('d/m/Y', strtotime($p['vencimento_anterior'])) : '-' ?></td>
                        <td><?= $p['vencimento_novo'] ? date('d/m/Y', strtotime($p['vencimento_novo'])) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Historico de alteracoes</h3>
        <?php if (!$historico): ?>
            <p style="color:#6b7280;">Nenhum registro.</p>
        <?php endif; ?>
        <?php foreach ($historico as $h): ?>
            <div style="padding:0.3rem 0;border-bottom:1px solid #f3f4f6;font-size:0.85rem;">
                <?= e($h['acao']) ?> · <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?> · por <?= e($h['user_nome'] ?? 'Sistema') ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($acessoGerado): ?>
    <script type="application/json" id="acessoGeradoData"><?= json_encode([
        'nome' => $empresa['nome'],
        'url' => rtrim(TENANTS_BASE_URL, '/').'/'.$empresa['slug'].'/login.html',
        'login' => $acessoGerado['login'],
        'senha' => $acessoGerado['senha'],
        'email' => $empresa['email'] ?? '',
    ], JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<?php require __DIR__.'/partials/footer.php'; ?>
