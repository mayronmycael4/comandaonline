<?php
require_once 'config.php';

exigirModulo($pdo, 'caixa');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Metodo nao permitido'], 405);
}

$data = getJsonInput();
$action = strtolower(trim((string)($data['action'] ?? '')));
$actor = extractAuditActor($data);
$operadorId = (int)($data['operador_id'] ?? ($actor['actor_id'] ?? 0));

if ($action === '') {
    jsonResponse(['error' => 'Acao obrigatoria'], 400);
}

if (!actorHasPermission($pdo, $actor, 'caixa')) {
    denyAndAudit($pdo, $actor, 'caixa', 'caixa_sessoes', null, [
        'acao' => $action
    ]);
}

function getSessaoCaixaAberta(PDO $pdo): ?array {
    $stmt = $pdo->query("SELECT * FROM caixa_sessoes WHERE status = 'aberto' ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function getSessaoById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM caixa_sessoes WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($action === 'abrir_caixa') {
    $valorInicial = round((float)($data['valor_inicial'] ?? 0), 2);
    $obsAbertura = trim((string)($data['observacao'] ?? ''));

    if ($operadorId <= 0) {
        jsonResponse(['error' => 'operador_id obrigatorio'], 400);
    }
    if ($valorInicial < 0) {
        jsonResponse(['error' => 'valor_inicial invalido'], 400);
    }

    $aberto = getSessaoCaixaAberta($pdo);
    if ($aberto) {
        jsonResponse(['error' => 'Ja existe caixa aberto', 'caixa_sessao_id' => (int)$aberto['id']], 409);
    }

    $stmt = $pdo->prepare('INSERT INTO caixa_sessoes (operador_id, status, valor_inicial, observacao_abertura) VALUES (?, ?, ?, ?)');
    $stmt->execute([$operadorId, 'aberto', $valorInicial, $obsAbertura !== '' ? $obsAbertura : null]);
    $sessaoId = (int)$pdo->lastInsertId();

    auditLog($pdo, 'caixa_aberto', 'caixa_sessoes', $sessaoId, [
        'valor_inicial' => $valorInicial,
        'observacao' => $obsAbertura
    ], $actor);

    jsonResponse(['success' => true, 'caixa_sessao_id' => $sessaoId]);
}

if ($action === 'movimentacao') {
    $sessaoId = (int)($data['caixa_sessao_id'] ?? 0);
    $tipo = strtolower(trim((string)($data['tipo'] ?? '')));
    $valor = round((float)($data['valor'] ?? 0), 2);
    $motivo = trim((string)($data['motivo'] ?? ''));

    if ($sessaoId <= 0) {
        $aberta = getSessaoCaixaAberta($pdo);
        if (!$aberta) jsonResponse(['error' => 'Nenhum caixa aberto'], 400);
        $sessaoId = (int)$aberta['id'];
    }

    if (!in_array($tipo, ['sangria', 'suprimento'], true)) {
        jsonResponse(['error' => 'tipo invalido. Use sangria ou suprimento'], 400);
    }
    if ($valor <= 0) {
        jsonResponse(['error' => 'valor deve ser maior que zero'], 400);
    }
    if ($motivo === '') {
        jsonResponse(['error' => 'Motivo obrigatorio'], 400);
    }

    if (!actorHasPermission($pdo, $actor, 'PDV_SANGRIA_SUPRIMENTO')) {
        denyAndAudit($pdo, $actor, 'PDV_SANGRIA_SUPRIMENTO', 'caixa_movimentacoes', $sessaoId, [
            'tipo' => $tipo
        ]);
    }

    $sessao = getSessaoById($pdo, $sessaoId);
    if (!$sessao || ($sessao['status'] ?? '') !== 'aberto') {
        jsonResponse(['error' => 'Caixa nao esta aberto'], 400);
    }

    $stmt = $pdo->prepare('INSERT INTO caixa_movimentacoes (caixa_sessao_id, tipo, valor, motivo, actor_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$sessaoId, $tipo, $valor, $motivo, $actor['actor_id'] ?? null]);

    auditLog($pdo, 'caixa_movimentacao', 'caixa_movimentacoes', (int)$pdo->lastInsertId(), [
        'caixa_sessao_id' => $sessaoId,
        'tipo' => $tipo,
        'valor' => $valor,
        'motivo' => $motivo
    ], $actor);

    jsonResponse(['success' => true]);
}

if ($action === 'fechar_caixa') {
    $sessaoId = (int)($data['caixa_sessao_id'] ?? 0);
    $valorContado = isset($data['valor_contado']) ? round((float)$data['valor_contado'], 2) : null;
    $obsFechamento = trim((string)($data['observacao'] ?? ''));
    $forceClose = !empty($data['force_close']);

    if ($sessaoId <= 0) {
        $aberta = getSessaoCaixaAberta($pdo);
        if (!$aberta) jsonResponse(['error' => 'Nenhum caixa aberto'], 400);
        $sessaoId = (int)$aberta['id'];
    }

    $sessao = getSessaoById($pdo, $sessaoId);
    if (!$sessao || ($sessao['status'] ?? '') !== 'aberto') {
        jsonResponse(['error' => 'Caixa nao esta aberto'], 400);
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM comandas WHERE status = 'aberta'");
    $comandasAbertas = (int)$stmt->fetchColumn();
    if ($comandasAbertas > 0 && !$forceClose) {
        jsonResponse([
            'error' => 'Existem comandas abertas. Fechamento bloqueado.',
            'comandas_abertas' => $comandasAbertas
        ], 409);
    }

    $abertoEm = (string)$sessao['aberto_em'];
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor),0) AS total, COALESCE(SUM(CASE WHEN tipo='dinheiro' THEN valor ELSE 0 END),0) AS total_dinheiro FROM pagamentos_comanda WHERE status='confirmado' AND created_at >= ?");
    $stmt->execute([$abertoEm]);
    $totaisPag = $stmt->fetch() ?: ['total' => 0, 'total_dinheiro' => 0];

    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN tipo='suprimento' THEN valor ELSE 0 END),0) AS suprimentos,
        COALESCE(SUM(CASE WHEN tipo='sangria' THEN valor ELSE 0 END),0) AS sangrias
        FROM caixa_movimentacoes WHERE caixa_sessao_id = ?");
    $stmt->execute([$sessaoId]);
    $mov = $stmt->fetch() ?: ['suprimentos' => 0, 'sangrias' => 0];

    $valorInicial = (float)($sessao['valor_inicial'] ?? 0);
    $totalVendas = round((float)($totaisPag['total'] ?? 0), 2);
    $totalDinheiro = round((float)($totaisPag['total_dinheiro'] ?? 0), 2);
    $suprimentos = round((float)($mov['suprimentos'] ?? 0), 2);
    $sangrias = round((float)($mov['sangrias'] ?? 0), 2);

    $saldoTeoricoCaixaFisico = round($valorInicial + $totalDinheiro + $suprimentos - $sangrias, 2);
    $divergencia = null;
    if ($valorContado !== null) {
        $divergencia = round($valorContado - $saldoTeoricoCaixaFisico, 2);
    }

    $stmt = $pdo->prepare("UPDATE caixa_sessoes SET status='fechado', fechado_em=NOW(), valor_contado=?, divergencia=?, observacao_fechamento=? WHERE id=?");
    $stmt->execute([$valorContado, $divergencia, $obsFechamento !== '' ? $obsFechamento : null, $sessaoId]);

    auditLog($pdo, 'caixa_fechado', 'caixa_sessoes', $sessaoId, [
        'total_vendas' => $totalVendas,
        'total_dinheiro' => $totalDinheiro,
        'suprimentos' => $suprimentos,
        'sangrias' => $sangrias,
        'saldo_teorico_caixa_fisico' => $saldoTeoricoCaixaFisico,
        'valor_contado' => $valorContado,
        'divergencia' => $divergencia,
        'comandas_abertas' => $comandasAbertas
    ], $actor);

    jsonResponse([
        'success' => true,
        'caixa_sessao_id' => $sessaoId,
        'resumo' => [
            'total_vendas' => $totalVendas,
            'total_dinheiro' => $totalDinheiro,
            'suprimentos' => $suprimentos,
            'sangrias' => $sangrias,
            'saldo_teorico_caixa_fisico' => $saldoTeoricoCaixaFisico,
            'valor_contado' => $valorContado,
            'divergencia' => $divergencia
        ]
    ]);
}

if ($action === 'status_caixa') {
    $aberta = getSessaoCaixaAberta($pdo);
    if (!$aberta) {
        jsonResponse(['success' => true, 'caixa_aberto' => false]);
    }

    $sessaoId = (int)$aberta['id'];
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN tipo='suprimento' THEN valor ELSE 0 END),0) AS suprimentos,
        COALESCE(SUM(CASE WHEN tipo='sangria' THEN valor ELSE 0 END),0) AS sangrias
        FROM caixa_movimentacoes WHERE caixa_sessao_id = ?");
    $stmt->execute([$sessaoId]);
    $mov = $stmt->fetch() ?: ['suprimentos' => 0, 'sangrias' => 0];

    jsonResponse([
        'success' => true,
        'caixa_aberto' => true,
        'sessao' => [
            'id' => $sessaoId,
            'operador_id' => (int)$aberta['operador_id'],
            'valor_inicial' => (float)$aberta['valor_inicial'],
            'aberto_em' => $aberta['aberto_em'],
            'suprimentos' => (float)$mov['suprimentos'],
            'sangrias' => (float)$mov['sangrias']
        ]
    ]);
}

jsonResponse(['error' => 'Acao nao suportada'], 400);
