<?php
require_once __DIR__.'/config.php';
$usuario = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('planos.php');
}

csrf_verify();

$pdo = pdo_saas();
$acao = $_POST['acao'] ?? '';

$nome = trim((string) ($_POST['nome'] ?? ''));
$valorMensal = (float) ($_POST['valor_mensal'] ?? 0);
$limiteUsuarios = ($_POST['limite_usuarios'] ?? '') !== '' ? (int) $_POST['limite_usuarios'] : null;
$ativo = isset($_POST['ativo']) ? 1 : 0;
$modulos = array_values(array_intersect((array) ($_POST['modulos'] ?? []), array_keys(MODULOS_DISPONIVEIS)));
$observacoes = trim((string) ($_POST['observacoes'] ?? '')) ?: null;

if ($nome === '') {
    flash_set('error', 'Informe o nome do plano.');
    redirect($acao === 'atualizar' ? 'plano_form.php?id='.(int) ($_POST['plano_id'] ?? 0) : 'plano_form.php');
}

if ($acao === 'criar') {
    $slug = gerar_slug($nome);
    $stmt = $pdo->prepare("INSERT INTO planos (nome, slug, valor_mensal, limite_usuarios, modulos, ativo, observacoes, created_at, updated_at) VALUES (?,?,?,?,?,?,?, NOW(), NOW())");
    $stmt->execute([$nome, $slug, $valorMensal, $limiteUsuarios, json_encode($modulos), $ativo, $observacoes]);
    flash_set('status', 'Plano criado com sucesso.');
    redirect('planos.php');
}

if ($acao === 'atualizar') {
    $planoId = (int) ($_POST['plano_id'] ?? 0);
    if (!$planoId) {
        redirect('planos.php');
    }

    $stmt = $pdo->prepare("UPDATE planos SET nome=?, valor_mensal=?, limite_usuarios=?, modulos=?, ativo=?, observacoes=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$nome, $valorMensal, $limiteUsuarios, json_encode($modulos), $ativo, $observacoes, $planoId]);
    flash_set('status', 'Plano atualizado. Clientes com este plano so recebem os novos modulos ao salvar a tela de edicao do cliente.');
    redirect('planos.php');
}

redirect('planos.php');
