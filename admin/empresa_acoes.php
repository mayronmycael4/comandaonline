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
$empresaId = (int) ($_POST['empresa_id'] ?? 0);

if (!$empresaId) {
    redirect('empresas.php');
}

function registrar_auditoria(PDO $pdo, int $empresaId, int $userId, string $acao, array $detalhes = []): void
{
    $stmt = $pdo->prepare("INSERT INTO audit_logs (empresa_id, user_id, acao, dados_novos, ip_address, created_at, updated_at) VALUES (?,?,?,?,?, NOW(), NOW())");
    $stmt->execute([$empresaId, $userId, $acao, json_encode($detalhes), $_SERVER['REMOTE_ADDR'] ?? null]);
}

switch ($acao) {
    case 'bloquear':
        $pdo->prepare("UPDATE empresas SET status='bloqueado', bloqueado_em=NOW() WHERE id=?")->execute([$empresaId]);
        registrar_auditoria($pdo, $empresaId, $usuario['id'], 'cliente_bloqueado');
        flash_set('status', 'Cliente bloqueado.');
        break;

    case 'desbloquear':
        $pdo->prepare("UPDATE empresas SET status='ativo', bloqueado_em=NULL, bloqueado_motivo=NULL WHERE id=?")->execute([$empresaId]);
        registrar_auditoria($pdo, $empresaId, $usuario['id'], 'cliente_desbloqueado');
        flash_set('status', 'Cliente desbloqueado.');
        break;

    case 'renovar':
        $valorPago = (float) ($_POST['valor_pago'] ?? 0);
        $dataPagamento = (string) ($_POST['data_pagamento'] ?? date('Y-m-d'));
        $diasAdicionais = max(1, (int) ($_POST['dias_adicionais'] ?? 30));

        $stmt = $pdo->prepare('SELECT * FROM licencas WHERE empresa_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$empresaId]);
        $licenca = $stmt->fetch();

        if ($licenca) {
            $vencimentoAnterior = $licenca['data_vencimento'];
            $baseData = ($vencimentoAnterior && strtotime($vencimentoAnterior) > time()) ? $vencimentoAnterior : date('Y-m-d');

            $stmt = $pdo->prepare("UPDATE licencas SET data_vencimento = DATE_ADD(?, INTERVAL ? DAY), ultimo_pagamento_em = ?, status='ativa', updated_at=NOW() WHERE id = ?");
            $stmt->execute([$baseData, $diasAdicionais, $dataPagamento, $licenca['id']]);

            $stmtNovo = $pdo->prepare('SELECT data_vencimento FROM licencas WHERE id = ?');
            $stmtNovo->execute([$licenca['id']]);
            $vencimentoNovo = $stmtNovo->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO licenca_pagamentos (licenca_id, empresa_id, registrado_por_user_id, valor_pago, data_pagamento, vencimento_anterior, vencimento_novo, created_at, updated_at) VALUES (?,?,?,?,?,?,?, NOW(), NOW())");
            $stmt->execute([$licenca['id'], $empresaId, $usuario['id'], $valorPago, $dataPagamento, $vencimentoAnterior, $vencimentoNovo]);

            registrar_auditoria($pdo, $empresaId, $usuario['id'], 'licenca_renovada', ['valor_pago' => $valorPago, 'dias_adicionais' => $diasAdicionais]);
            flash_set('status', 'Pagamento registrado e licenca renovada.');
        }
        break;

    case 'atualizar_licenca':
        $diasCarencia = max(0, (int) ($_POST['dias_carencia'] ?? 3));
        $diasAviso = max(0, (int) ($_POST['dias_aviso_antecedencia'] ?? 3));
        $avisoFechavel = isset($_POST['aviso_fechavel']) ? 1 : 0;
        $avisoRecorrente = isset($_POST['aviso_recorrente']) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE licencas SET dias_carencia=?, dias_aviso_antecedencia=?, aviso_fechavel=?, aviso_recorrente=?, updated_at=NOW() WHERE empresa_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$diasCarencia, $diasAviso, $avisoFechavel, $avisoRecorrente, $empresaId]);

        registrar_auditoria($pdo, $empresaId, $usuario['id'], 'regras_licenca_atualizadas');
        flash_set('status', 'Regras de aviso e carencia atualizadas.');
        break;

    case 'resetar_senha':
        $novaSenha = (string) ($_POST['nova_senha'] ?? '');
        if (strlen($novaSenha) < 8) {
            flash_set('error', 'A nova senha deve ter ao menos 8 caracteres.');
            break;
        }

        if (tenant_resetar_senha($empresaId, $novaSenha)) {
            registrar_auditoria($pdo, $empresaId, $usuario['id'], 'senha_redefinida');
            flash_set('status', 'Senha do administrador redefinida com sucesso.');
            $loginAdmin = (string) $pdo->query('SELECT login_admin FROM empresas WHERE id = '.(int) $empresaId)->fetchColumn();
            $_SESSION['acesso_gerado'] = ['empresa_id' => $empresaId, 'login' => $loginAdmin, 'senha' => $novaSenha];
        } else {
            flash_set('error', 'Nao foi possivel redefinir a senha (instancia nao provisionada ou administrador nao encontrado).');
        }
        break;

    case 'reprovisionar':
        $adminNome = trim((string) ($_POST['admin_nome'] ?? ''));
        $adminLogin = trim((string) ($_POST['admin_login'] ?? ''));
        $adminSenha = (string) ($_POST['admin_senha'] ?? '');

        if ($adminNome === '' || $adminLogin === '' || strlen($adminSenha) < 8) {
            flash_set('error', 'Preencha nome, login e senha (min. 8 caracteres) para tentar provisionar novamente.');
            break;
        }

        try {
            tenant_provisionar($empresaId, $adminNome, $adminLogin, $adminSenha);
            registrar_auditoria($pdo, $empresaId, $usuario['id'], 'reprovisionado');
            flash_set('status', 'Instancia provisionada com sucesso.');
            $_SESSION['acesso_gerado'] = ['empresa_id' => $empresaId, 'login' => $adminLogin, 'senha' => $adminSenha];
        } catch (Throwable $e) {
            flash_set('error', 'Falha ao provisionar: '.$e->getMessage());
        }
        break;
}

redirect('empresa_form.php?id='.$empresaId);
