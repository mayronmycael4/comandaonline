<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

function ensurePermissoesColumn($pdo) {
    static $checked = false;
    if ($checked) return;

    $stmt = $pdo->query("SHOW COLUMNS FROM funcionarios LIKE 'permissoes'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE funcionarios ADD COLUMN permissoes TEXT NULL AFTER is_admin");
    }

    $checked = true;
}

function normalizePermissoes($raw) {
    if (is_array($raw)) return array_values(array_unique($raw));
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_unique($decoded));
    }
    return [];
}

function resolveIsAdminFlag(array $data): bool {
    if (array_key_exists('is_admin', $data)) {
        return !empty($data['is_admin']);
    }
    if (array_key_exists('isAdmin', $data)) {
        return !empty($data['isAdmin']);
    }
    return false;
}

try {
    ensurePermissoesColumn($pdo);

    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("SELECT id, nome, login, is_admin, permissoes, is_active, created_at FROM funcionarios WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $funcionario = $stmt->fetch();
                if ($funcionario) {
                    $funcionario['permissoes'] = normalizePermissoes($funcionario['permissoes'] ?? '[]');
                }
                jsonResponse($funcionario);
            } elseif (isset($_GET['login'])) {
                $stmt = $pdo->prepare("SELECT * FROM funcionarios WHERE login = ? AND is_active = 1");
                $stmt->execute([$_GET['login']]);
                $funcionario = $stmt->fetch();
                if ($funcionario) {
                    $funcionario['permissoes'] = normalizePermissoes($funcionario['permissoes'] ?? '[]');
                }
                jsonResponse($funcionario);
            } else {
                $stmt = $pdo->query("SELECT id, nome, login, is_admin, permissoes, is_active, created_at FROM funcionarios WHERE is_active = 1 ORDER BY nome");
                $funcionarios = $stmt->fetchAll();
                foreach ($funcionarios as &$funcionario) {
                    $funcionario['permissoes'] = normalizePermissoes($funcionario['permissoes'] ?? '[]');
                }
                jsonResponse($funcionarios);
            }
            break;
            
        case 'POST':
            $data = getJsonInput();
            $isAdmin = resolveIsAdminFlag($data);
            
            $stmt = $pdo->prepare("SELECT id, is_active FROM funcionarios WHERE LOWER(login) = LOWER(?) LIMIT 1");
            $stmt->execute([$data['login']]);
            $existente = $stmt->fetch();

            if ($existente && (int) $existente['is_active'] === 1) {
                jsonResponse(['error' => 'Login já existe'], 400);
            }
            
            $senhaHash = password_hash($data['senha'], PASSWORD_DEFAULT);
            $permissoesJson = json_encode(normalizePermissoes($data['permissoes'] ?? []), JSON_UNESCAPED_UNICODE);

            if ($existente) {
                $stmt = $pdo->prepare("UPDATE funcionarios SET nome = ?, login = ?, senha = ?, is_admin = ?, permissoes = ?, is_active = 1 WHERE id = ?");
                $stmt->execute([
                    $data['nome'],
                    $data['login'],
                    $senhaHash,
                    $isAdmin ? 1 : 0,
                    $permissoesJson,
                    $existente['id']
                ]);
                $novoId = $existente['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO funcionarios (nome, login, senha, is_admin, permissoes) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['nome'],
                    $data['login'],
                    $senhaHash,
                    $isAdmin ? 1 : 0,
                    $permissoesJson
                ]);
                $novoId = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("SELECT id, nome, login, is_admin, permissoes, is_active, created_at FROM funcionarios WHERE id = ?");
            $stmt->execute([$novoId]);
            $funcionario = $stmt->fetch();
            if ($funcionario) {
                $funcionario['permissoes'] = normalizePermissoes($funcionario['permissoes'] ?? '[]');
            }
        
            jsonResponse(['success' => true, 'id' => $novoId, 'funcionario' => $funcionario]);
            break;
            
        case 'PUT':
            $data = getJsonInput();
            $id = $data['id'] ?? 0;
            $actor = extractAuditActor($data);
            $isAdmin = resolveIsAdminFlag($data);
            $permissoesJson = json_encode(normalizePermissoes($data['permissoes'] ?? []), JSON_UNESCAPED_UNICODE);

            if (!empty($data['logout_global'])) {
                $isSelf = isset($actor['actor_id']) && (int)$actor['actor_id'] === (int)$id;
                if (!$isSelf && !actorHasPermission($pdo, $actor, 'funcionarios')) {
                    denyAndAudit($pdo, $actor, 'funcionarios', 'funcionarios', $id, [
                        'acao' => 'logout_global_usuario'
                    ]);
                }

                $stmt = $pdo->prepare("UPDATE funcionarios SET sessao_versao = COALESCE(sessao_versao, 1) + 1, sessao_revogada_em = NOW() WHERE id = ?");
                $stmt->execute([$id]);

                auditLog($pdo, 'logout_global_usuario', 'funcionarios', $id, [
                    'executado_por' => $actor['actor_id'] ?? null
                ], $actor);

                jsonResponse(['success' => true]);
            }
            
            if (!empty($data['senha'])) {
                $senhaHash = password_hash($data['senha'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE funcionarios SET nome = ?, login = ?, senha = ?, is_admin = ?, permissoes = ?, sessao_versao = COALESCE(sessao_versao, 1) + 1, sessao_revogada_em = NOW() WHERE id = ?");
                $stmt->execute([$data['nome'], $data['login'], $senhaHash, $isAdmin ? 1 : 0, $permissoesJson, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE funcionarios SET nome = ?, login = ?, is_admin = ?, permissoes = ? WHERE id = ?");
                $stmt->execute([$data['nome'], $data['login'], $isAdmin ? 1 : 0, $permissoesJson, $id]);
            }
            
            jsonResponse(['success' => true]);
            break;
            
        case 'DELETE':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("UPDATE funcionarios SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(['success' => true]);
            break;
            
        default:
            jsonResponse(['error' => 'Método não permitido'], 405);
    }
} catch (PDOException $e) {
    if ($method === 'GET') {
        jsonResponse(isset($_GET['id']) || isset($_GET['login']) ? null : []);
    }
    jsonResponse(['error' => 'Banco de dados ainda não instalado. Execute o setup inicial.'], 500);
}
