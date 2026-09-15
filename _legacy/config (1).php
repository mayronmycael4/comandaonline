<?php
function getAllowedOrigin() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . getAllowedOrigin());
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config_helper.php';

// Conexão com o banco
try {
    $conn = comanda_connect_db(['with_db' => true, 'timeout' => 6]);
    $pdo = $conn['pdo'];
    $dbConfig = $conn['config'];
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// Funções auxiliares
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

function ensureAuditTables(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS action_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            actor_id INT NULL,
            actor_nome VARCHAR(120) NULL,
            actor_login VARCHAR(120) NULL,
            acao VARCHAR(80) NOT NULL,
            entidade VARCHAR(80) NOT NULL,
            entidade_id VARCHAR(80) NULL,
            detalhes JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action_log_created_at (created_at),
            INDEX idx_action_log_actor_id (actor_id),
            INDEX idx_action_log_entidade (entidade)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar action_log: ' . $e->getMessage());
    }

    $ready = true;
}

function ensureCoreOperationalTables(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_status_historico (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            comanda_id INT NOT NULL,
            status_anterior VARCHAR(40) NULL,
            status_novo VARCHAR(40) NOT NULL,
            observacao VARCHAR(255) NULL,
            actor_id INT NULL,
            actor_nome VARCHAR(120) NULL,
            actor_login VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hist_status_comanda (comanda_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar comanda_status_historico: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_fila (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            funcionario_id INT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            titulo VARCHAR(140) NOT NULL,
            mensagem TEXT NOT NULL,
            payload JSON NULL,
            status ENUM('pendente','lida') NOT NULL DEFAULT 'pendente',
            lida_em DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_func_status (funcionario_id, status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar notificacoes_fila: ' . $e->getMessage());
    }

    try {
        $cmdCols = $pdo->query('SHOW COLUMNS FROM comandas')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('versao', $cmdCols, true)) {
            $pdo->exec("ALTER TABLE comandas ADD COLUMN versao INT NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar coluna comandas.versao: ' . $e->getMessage());
    }

    try {
        $funcCols = $pdo->query('SHOW COLUMNS FROM funcionarios')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('sessao_versao', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN sessao_versao INT NOT NULL DEFAULT 1");
        }
        if (!in_array('sessao_revogada_em', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN sessao_revogada_em DATETIME NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar colunas de sessao em funcionarios: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE comanda_itens ADD INDEX idx_comanda_itens_comanda_status (comanda_id, kitchen_status)");
    } catch (Throwable $e) {
        // Ignora quando indice ja existe ou coluna ainda nao foi criada.
    }

    $ready = true;
}

function extractAuditActor(array $data = []): array {
    $audit = isset($data['_audit']) && is_array($data['_audit']) ? $data['_audit'] : [];

    $actorId = $audit['actor_id'] ?? ($_GET['audit_actor_id'] ?? null);
    $actorNome = $audit['actor_nome'] ?? ($_GET['audit_actor_nome'] ?? null);
    $actorLogin = $audit['actor_login'] ?? ($_GET['audit_actor_login'] ?? null);

    return [
        'actor_id' => is_numeric($actorId) ? (int)$actorId : null,
        'actor_nome' => $actorNome ? (string)$actorNome : null,
        'actor_login' => $actorLogin ? (string)$actorLogin : null,
    ];
}

function auditLog(PDO $pdo, string $acao, string $entidade, $entidadeId = null, array $detalhes = [], array $actor = []): void {
    ensureAuditTables($pdo);

    try {
        $stmt = $pdo->prepare("INSERT INTO action_log (
            actor_id, actor_nome, actor_login, acao, entidade, entidade_id, detalhes, ip_address, user_agent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $actor['actor_id'] ?? null,
            $actor['actor_nome'] ?? null,
            $actor['actor_login'] ?? null,
            $acao,
            $entidade,
            $entidadeId !== null ? (string)$entidadeId : null,
            !empty($detalhes) ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao gravar action_log: ' . $e->getMessage());
    }
}

function registrarHistoricoStatusComanda(PDO $pdo, int $comandaId, ?string $statusAnterior, string $statusNovo, array $actor = [], ?string $observacao = null): void {
    ensureCoreOperationalTables($pdo);

    try {
        $stmt = $pdo->prepare("INSERT INTO comanda_status_historico (
            comanda_id, status_anterior, status_novo, observacao, actor_id, actor_nome, actor_login
        ) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $comandaId,
            $statusAnterior,
            $statusNovo,
            $observacao,
            $actor['actor_id'] ?? null,
            $actor['actor_nome'] ?? null,
            $actor['actor_login'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao registrar historico de status: ' . $e->getMessage());
    }
}

function criarNotificacaoFila(PDO $pdo, int $funcionarioId, string $tipo, string $titulo, string $mensagem, array $payload = []): void {
    ensureCoreOperationalTables($pdo);

    try {
        $stmt = $pdo->prepare("INSERT INTO notificacoes_fila (funcionario_id, tipo, titulo, mensagem, payload) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $funcionarioId,
            $tipo,
            $titulo,
            $mensagem,
            !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null
        ]);
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao enfileirar notificacao: ' . $e->getMessage());
    }
}

function getActorFuncionario(PDO $pdo, array $actor = []): ?array {
    $actorId = $actor['actor_id'] ?? null;
    if (!$actorId || !is_numeric($actorId)) return null;

    try {
        $stmt = $pdo->prepare("SELECT id, nome, login, is_admin, permissoes FROM funcionarios WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$actorId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['is_admin'] = (int)($row['is_admin'] ?? 0) === 1;
        $permissoes = json_decode((string)($row['permissoes'] ?? '[]'), true);
        $row['permissoes'] = is_array($permissoes) ? $permissoes : [];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function actorHasPermission(PDO $pdo, array $actor, ?string $permission): bool {
    if (!$permission) return true;
    $funcionario = getActorFuncionario($pdo, $actor);
    if (!$funcionario) return false;
    if (!empty($funcionario['is_admin'])) return true;

    $permissoes = $funcionario['permissoes'] ?? [];
    if (!is_array($permissoes) || count($permissoes) === 0) return false;
    return in_array($permission, $permissoes, true);
}

function denyAndAudit(PDO $pdo, array $actor, string $permission, string $entidade, $entidadeId = null, array $detalhes = []): void {
    $detalhes['permission_required'] = $permission;
    auditLog($pdo, 'acesso_negado', $entidade, $entidadeId, $detalhes, $actor);
    jsonResponse(['error' => 'Acesso negado para esta ação.'], 403);
}

// Prepara estrutura de auditoria fora de transacoes de negocio.
ensureAuditTables($pdo);
ensureCoreOperationalTables($pdo);
