<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/provisioning.php';
$usuario = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('empresas.php');
}

csrf_verify();

$pdo = pdo_saas();
$acao = $_POST['acao'] ?? '';

function empresa_ler_campos_comuns(): array
{
    return [
        'nome' => trim((string) ($_POST['nome'] ?? '')),
        'nome_exibicao' => trim((string) ($_POST['nome_exibicao'] ?? '')) ?: null,
        'telefone' => trim((string) ($_POST['telefone'] ?? '')) ?: null,
        'whatsapp' => trim((string) ($_POST['whatsapp'] ?? '')) ?: null,
        'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
        'endereco' => trim((string) ($_POST['endereco'] ?? '')) ?: null,
        'cidade' => trim((string) ($_POST['cidade'] ?? '')) ?: null,
        'estado' => trim((string) ($_POST['estado'] ?? '')) ?: null,
        'segmento' => trim((string) ($_POST['segmento'] ?? '')) ?: null,
        'plano_id' => (int) ($_POST['plano_id'] ?? 0),
        'cor_primaria' => trim((string) ($_POST['cor_primaria'] ?? '')) ?: null,
        'cor_secundaria' => trim((string) ($_POST['cor_secundaria'] ?? '')) ?: null,
        'mensagem_boas_vindas' => trim((string) ($_POST['mensagem_boas_vindas'] ?? '')) ?: null,
    ];
}

function empresa_processar_logo(): ?string
{
    if (empty($_FILES['logo']['name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $extensoesPermitidas = ['jpg', 'jpeg', 'png', 'webp'];
    $extensao = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    if (!in_array($extensao, $extensoesPermitidas, true)) {
        throw new RuntimeException('Formato de logo invalido. Use JPG, PNG ou WEBP.');
    }

    $nomeArquivo = 'logo_'.bin2hex(random_bytes(8)).'.'.$extensao;
    $destino = __DIR__.'/storage/logos/'.$nomeArquivo;

    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $destino)) {
        throw new RuntimeException('Nao foi possivel salvar o logo enviado.');
    }

    return $nomeArquivo;
}

if ($acao === 'criar') {
    $dados = empresa_ler_campos_comuns();
    $adminNome = trim((string) ($_POST['admin_nome'] ?? ''));
    $adminLogin = trim((string) ($_POST['admin_login'] ?? ''));
    $adminSenha = (string) ($_POST['admin_senha'] ?? '');

    if ($dados['nome'] === '' || $dados['plano_id'] === 0 || $adminNome === '' || $adminLogin === '' || strlen($adminSenha) < 8) {
        flash_set('error', 'Preencha todos os campos obrigatorios (nome, plano e dados do administrador).');
        redirect('empresa_form.php');
    }

    try {
        $logoPath = empresa_processar_logo();
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('empresa_form.php');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO empresas (nome, nome_exibicao, telefone, whatsapp, email, endereco, cidade, estado, segmento, plano_id, cor_primaria, cor_secundaria, logo_path, mensagem_boas_vindas, status, moeda, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ativo','BRL', NOW(), NOW())");
        $stmt->execute([
            $dados['nome'], $dados['nome_exibicao'], $dados['telefone'], $dados['whatsapp'], $dados['email'],
            $dados['endereco'], $dados['cidade'], $dados['estado'], $dados['segmento'], $dados['plano_id'],
            $dados['cor_primaria'], $dados['cor_secundaria'], $logoPath, $dados['mensagem_boas_vindas'],
        ]);
        $empresaId = (int) $pdo->lastInsertId();

        $stmtPlano = $pdo->prepare('SELECT valor_mensal FROM planos WHERE id = ?');
        $stmtPlano->execute([$dados['plano_id']]);
        $valorMensal = (float) $stmtPlano->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO licencas (empresa_id, plano_id, valor_mensalidade, data_inicio, data_vencimento, dias_carencia, dias_aviso_antecedencia, aviso_fechavel, aviso_recorrente, status, created_at, updated_at) VALUES (?,?,?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 3, 3, 1, 1, 'ativa', NOW(), NOW())");
        $stmt->execute([$empresaId, $dados['plano_id'], $valorMensal]);

        $stmt = $pdo->prepare("INSERT INTO audit_logs (empresa_id, user_id, acao, dados_novos, ip_address, created_at, updated_at) VALUES (?,?,?,?,?, NOW(), NOW())");
        $stmt->execute([$empresaId, $usuario['id'], 'cliente_cadastrado', json_encode($dados), $_SERVER['REMOTE_ADDR'] ?? null]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Erro ao salvar cliente: '.$e->getMessage());
        redirect('empresa_form.php');
    }

    try {
        tenant_provisionar($empresaId, $adminNome, $adminLogin, $adminSenha);
        flash_set('status', 'Cliente cadastrado e instancia provisionada com sucesso.');
        $_SESSION['acesso_gerado'] = ['empresa_id' => $empresaId, 'login' => $adminLogin, 'senha' => $adminSenha];
    } catch (Throwable $e) {
        flash_set('warning', 'Cliente cadastrado, mas o provisionamento falhou: '.$e->getMessage().' Tente novamente na tela do cliente.');
    }

    redirect('empresa_form.php?id='.$empresaId);
}

if ($acao === 'atualizar') {
    $empresaId = (int) ($_POST['empresa_id'] ?? 0);
    $dados = empresa_ler_campos_comuns();

    if (!$empresaId || $dados['nome'] === '' || $dados['plano_id'] === 0) {
        flash_set('error', 'Dados invalidos.');
        redirect('empresa_form.php?id='.$empresaId);
    }

    $stmt = $pdo->prepare('SELECT * FROM empresas WHERE id = ?');
    $stmt->execute([$empresaId]);
    $anterior = $stmt->fetch();
    if (!$anterior) {
        flash_set('error', 'Cliente nao encontrado.');
        redirect('empresas.php');
    }

    try {
        $logoPath = empresa_processar_logo() ?? $anterior['logo_path'];
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
        redirect('empresa_form.php?id='.$empresaId);
    }

    $stmt = $pdo->prepare("UPDATE empresas SET nome=?, nome_exibicao=?, telefone=?, whatsapp=?, email=?, endereco=?, cidade=?, estado=?, segmento=?, plano_id=?, cor_primaria=?, cor_secundaria=?, logo_path=?, mensagem_boas_vindas=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([
        $dados['nome'], $dados['nome_exibicao'], $dados['telefone'], $dados['whatsapp'], $dados['email'],
        $dados['endereco'], $dados['cidade'], $dados['estado'], $dados['segmento'], $dados['plano_id'],
        $dados['cor_primaria'], $dados['cor_secundaria'], $logoPath, $dados['mensagem_boas_vindas'], $empresaId,
    ]);

    $stmt = $pdo->prepare("INSERT INTO audit_logs (empresa_id, user_id, acao, dados_anteriores, dados_novos, ip_address, created_at, updated_at) VALUES (?,?,?,?,?,?, NOW(), NOW())");
    $stmt->execute([$empresaId, $usuario['id'], 'empresa_atualizada', json_encode($anterior), json_encode($dados), $_SERVER['REMOTE_ADDR'] ?? null]);

    $mensagem = 'Dados da empresa atualizados.';
    try {
        tenant_sincronizar_personalizacao($empresaId);
        $mensagem .= ' Sincronizado com a instancia do cliente.';
        flash_set('status', $mensagem);
    } catch (Throwable $e) {
        flash_set('warning', $mensagem.' Porem a sincronizacao com a instancia falhou: '.$e->getMessage());
    }

    redirect('empresa_form.php?id='.$empresaId);
}

redirect('empresas.php');
