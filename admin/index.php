<?php
require_once __DIR__.'/config.php';
$usuario = require_login();
$pdo = pdo_saas();

$totalClientes = (int) $pdo->query("SELECT COUNT(*) FROM empresas")->fetchColumn();
$ativos = (int) $pdo->query("SELECT COUNT(*) FROM empresas WHERE status = 'ativo'")->fetchColumn();
$bloqueados = (int) $pdo->query("SELECT COUNT(*) FROM empresas WHERE status = 'bloqueado'")->fetchColumn();
$vencendoEm7 = (int) $pdo->query("SELECT COUNT(*) FROM licencas WHERE status='ativa' AND data_vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$vencidos = (int) $pdo->query("SELECT COUNT(*) FROM licencas WHERE data_vencimento < CURDATE() AND status='ativa'")->fetchColumn();
$previstoMes = (float) $pdo->query("SELECT COALESCE(SUM(valor_mensalidade),0) FROM licencas WHERE status='ativa'")->fetchColumn();
$recebidoMes = (float) $pdo->query("SELECT COALESCE(SUM(valor_pago),0) FROM licenca_pagamentos WHERE MONTH(data_pagamento)=MONTH(CURDATE()) AND YEAR(data_pagamento)=YEAR(CURDATE())")->fetchColumn();

$proximosVencimentos = $pdo->query("
    SELECT e.id, e.nome, l.data_vencimento, e.status
    FROM licencas l
    JOIN empresas e ON e.id = l.empresa_id
    WHERE l.status = 'ativa'
    ORDER BY l.data_vencimento ASC
    LIMIT 8
")->fetchAll();

$ultimasAtividades = $pdo->query("
    SELECT a.acao, a.created_at, e.nome AS empresa_nome, u.name AS user_nome
    FROM audit_logs a
    LEFT JOIN empresas e ON e.id = a.empresa_id
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC
    LIMIT 8
")->fetchAll();

$planosMaisUsados = $pdo->query("
    SELECT p.nome, COUNT(e.id) AS total
    FROM planos p
    LEFT JOIN empresas e ON e.plano_id = p.id
    GROUP BY p.id, p.nome
    ORDER BY total DESC
")->fetchAll();
$maxPlano = max(1, ...array_map(static fn ($p) => (int) $p['total'], $planosMaisUsados ?: [['total' => 1]]));

$tituloPagina = 'Dashboard';
require __DIR__.'/partials/header.php';
?>
<h1>Dashboard</h1>

<div class="grid grid-4">
    <div class="card kpi"><div class="valor"><?= $totalClientes ?></div><div class="rotulo">Total de clientes</div></div>
    <div class="card kpi"><div class="valor"><?= $ativos ?></div><div class="rotulo">Ativos</div></div>
    <div class="card kpi"><div class="valor"><?= $vencendoEm7 ?></div><div class="rotulo">Vencendo em 7 dias</div></div>
    <div class="card kpi"><div class="valor"><?= $bloqueados ?></div><div class="rotulo">Bloqueados</div></div>
    <div class="card kpi"><div class="valor"><?= $vencidos ?></div><div class="rotulo">Vencidos</div></div>
    <div class="card kpi"><div class="valor">R$ <?= number_format($previstoMes, 2, ',', '.') ?></div><div class="rotulo">Previsto no mes</div></div>
    <div class="card kpi"><div class="valor">R$ <?= number_format($recebidoMes, 2, ',', '.') ?></div><div class="rotulo">Recebido no mes</div></div>
    <div class="card kpi" style="display:flex;align-items:center;justify-content:center;">
        <a href="empresa_form.php" class="btn">+ Cadastrar cliente</a>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>Proximos vencimentos</h3>
        <?php if (!$proximosVencimentos): ?>
            <p style="color:#6b7280;font-size:0.9rem;">Nenhuma licenca ativa.</p>
        <?php endif; ?>
        <?php foreach ($proximosVencimentos as $l): ?>
            <div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid #f3f4f6;">
                <div>
                    <a href="empresa_form.php?id=<?= (int) $l['id'] ?>"><?= e($l['nome']) ?></a>
                    <div style="font-size:0.8rem;color:#6b7280;">Vencimento: <?= $l['data_vencimento'] ? date('d/m/Y', strtotime($l['data_vencimento'])) : '-' ?></div>
                </div>
                <span class="badge badge-verde"><?= e($l['status']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h3>Ultimas atividades</h3>
        <?php if (!$ultimasAtividades): ?>
            <p style="color:#6b7280;font-size:0.9rem;">Nenhuma atividade registrada.</p>
        <?php endif; ?>
        <?php foreach ($ultimasAtividades as $a): ?>
            <div style="padding:0.4rem 0;border-bottom:1px solid #f3f4f6;">
                <div><?= e($a['acao']) ?> · <?= e($a['empresa_nome'] ?? '-') ?></div>
                <div style="font-size:0.8rem;color:#6b7280;"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?> · por <?= e($a['user_nome'] ?? 'Sistema') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3>Distribuicao por plano</h3>
    <?php foreach ($planosMaisUsados as $p): ?>
        <div style="margin-bottom:0.6rem;">
            <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
                <span><?= e($p['nome']) ?></span>
                <span><?= (int) $p['total'] ?> cliente(s)</span>
            </div>
            <div style="background:#e5e7eb;border-radius:999px;height:8px;overflow:hidden;">
                <div style="background:var(--cor-primaria);height:8px;width:<?= (int) round(((int) $p['total'] / $maxPlano) * 100) ?>%;"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__.'/partials/footer.php'; ?>
