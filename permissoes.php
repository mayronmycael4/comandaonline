<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

if (($actor['actor_id'] ?? null) && !actorHasPermission($pdo, $actor, 'funcionarios')) {
    denyAndAudit($pdo, $actor, 'funcionarios', 'permissoes', null, ['acao' => 'gerenciar_permissoes']);
}

if ($method === 'GET') {
    $tipo = strtolower(trim((string)($_GET['tipo'] ?? 'catalogo')));

    if ($tipo === 'roles') {
        $stmt = $pdo->query("SELECT role, permissao_chave, allowed FROM role_permissoes ORDER BY role, permissao_chave");
        jsonResponse($stmt->fetchAll());
    }

    if ($tipo === 'efetivas') {
        $role = normalizeRole((string)($_GET['role'] ?? 'garcom'));
        $base = getRoleDefaultPermissions($role);
        $ov = getRolePermissionOverrides($pdo, $role);
        $effective = array_values(array_unique(array_merge($base, $ov['allow'])));
        if (!empty($ov['deny'])) {
            $effective = array_values(array_filter($effective, static fn($p) => !in_array($p, $ov['deny'], true)));
        }
        jsonResponse(['role' => $role, 'permissoes' => $effective]);
    }

    $stmt = $pdo->query("SELECT id, chave, descricao, categoria, is_critica, is_active, created_at, updated_at FROM permissoes_catalog ORDER BY categoria, chave");
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $chave = trim((string)($data['chave'] ?? ''));
    $descricao = trim((string)($data['descricao'] ?? ''));
    $categoria = trim((string)($data['categoria'] ?? 'geral'));
    $isCritica = !empty($data['is_critica']) ? 1 : 0;

    if ($chave === '' || $descricao === '') {
        jsonResponse(['error' => 'chave e descricao sao obrigatorias'], 400);
    }

    $stmt = $pdo->prepare("INSERT INTO permissoes_catalog (chave, descricao, categoria, is_critica, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$chave, $descricao, $categoria, $isCritica]);

    auditLog($pdo, 'permissao_catalogo_criada', 'permissoes_catalog', (int)$pdo->lastInsertId(), [
        'chave' => $chave,
        'categoria' => $categoria,
        'is_critica' => $isCritica
    ], $actor);

    jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($method === 'PUT') {
    $action = strtolower(trim((string)($data['action'] ?? 'catalogo')));

    if ($action === 'role') {
        $role = normalizeRole((string)($data['role'] ?? 'garcom'));
        $permissao = trim((string)($data['permissao_chave'] ?? ''));
        $allowed = !empty($data['allowed']) ? 1 : 0;

        if ($permissao === '') {
            jsonResponse(['error' => 'permissao_chave obrigatoria'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO role_permissoes (role, permissao_chave, allowed) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$role, $permissao, $allowed]);

        auditLog($pdo, 'role_permissao_atualizada', 'role_permissoes', null, [
            'role' => $role,
            'permissao_chave' => $permissao,
            'allowed' => $allowed
        ], $actor);

        jsonResponse(['success' => true]);
    }

    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    $stmt = $pdo->prepare("UPDATE permissoes_catalog SET descricao = ?, categoria = ?, is_critica = ?, is_active = ? WHERE id = ?");
    $stmt->execute([
        trim((string)($data['descricao'] ?? '')),
        trim((string)($data['categoria'] ?? 'geral')),
        !empty($data['is_critica']) ? 1 : 0,
        !empty($data['is_active']) ? 1 : 0,
        $id
    ]);

    auditLog($pdo, 'permissao_catalogo_atualizada', 'permissoes_catalog', $id, [], $actor);
    jsonResponse(['success' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'id obrigatorio'], 400);

    $stmt = $pdo->prepare("UPDATE permissoes_catalog SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    auditLog($pdo, 'permissao_catalogo_desativada', 'permissoes_catalog', $id, [], extractAuditActor([]));
    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Metodo nao permitido'], 405);
