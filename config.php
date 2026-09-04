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

const SYSTEM_VERSION = '2026.05.18-foundation';
// Segredo compartilhado com o painel administrativo para validar o token de acesso direto (SSO).
// Deve ser identico ao SSO_SHARED_SECRET de admin/config.php.
const SSO_SHARED_SECRET = 'CoOnline-SSO-6f2a9c1d4e8b7053-troque-em-producao';

// Conexão com o banco
try {
    $conn = comanda_connect_db(['with_db' => true, 'timeout' => 6]);
    $pdo = $conn['pdo'];
    $dbConfig = $conn['config'];
} catch (Throwable $e) {
    $erroConexao = (string)$e->getMessage();
    $unknownDb = stripos($erroConexao, 'unknown database') !== false || stripos($erroConexao, '1049') !== false;

    if ($unknownDb) {
        try {
            $connHost = comanda_connect_db(['with_db' => false, 'timeout' => 6]);
            $pdoHost = $connHost['pdo'];
            $cfg = $connHost['config'];
            $dbName = (string)($cfg['dbname'] ?? '');

            if ($dbName === '') {
                throw new RuntimeException('Nome do banco nao configurado para criacao automatica.');
            }

            $safeDb = '`' . str_replace('`', '``', $dbName) . '`';
            $pdoHost->exec("CREATE DATABASE IF NOT EXISTS {$safeDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $conn = comanda_connect_db(['with_db' => true, 'timeout' => 6]);
            $pdo = $conn['pdo'];
            $dbConfig = $conn['config'];

            error_log('[config.php] Banco criado automaticamente: ' . $dbName);
        } catch (Throwable $eCreate) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar banco automaticamente: ' . $eCreate->getMessage()]);
            exit;
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Erro de conexão: ' . $erroConexao]);
        exit;
    }
}

// Funções auxiliares
function jsonResponse($data, $status = 200) {
    if ($status >= 400) {
        $message = null;
        if (is_array($data)) {
            if (!empty($data['message'])) $message = (string)$data['message'];
            elseif (!empty($data['error'])) $message = (string)$data['error'];
        }

        $errorCode = 'HTTP_' . (int)$status;
        if (is_array($data) && !empty($data['error_code'])) {
            $errorCode = (string)$data['error_code'];
        }

        $payload = [
            'status' => 'error',
            'error_code' => $errorCode,
            'message' => $message ?? 'Erro na requisicao'
        ];

        if (is_array($data)) {
            if (isset($data['details'])) {
                $payload['details'] = $data['details'];
            }
            foreach ($data as $k => $v) {
                if (!array_key_exists($k, $payload)) {
                    $payload[$k] = $v;
                }
            }
        }
        $data = $payload;

        if ($status >= 500) {
            try {
                global $pdo;
                if ($pdo instanceof PDO) {
                    $stmtErr = $pdo->prepare("INSERT INTO error_events (rota, metodo, status_code, error_code, mensagem, detalhes, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtErr->execute([
                        $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? 'unknown'),
                        $_SERVER['REQUEST_METHOD'] ?? 'GET',
                        (int)$status,
                        $payload['error_code'] ?? null,
                        $payload['message'] ?? null,
                        isset($payload['details']) ? json_encode($payload['details'], JSON_UNESCAPED_UNICODE) : null,
                        $_SERVER['REMOTE_ADDR'] ?? null
                    ]);
                }
            } catch (Throwable $e) {
                error_log('[config.php] Falha ao registrar error_events: ' . $e->getMessage());
            }
        }
    }

    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Bloqueia o acesso a um modulo nao incluido no plano contratado (coluna
 * empresa.modulos_habilitados, populada pela plataforma SaaS na criacao/
 * alteracao do plano do cliente). Se a coluna nao existir ou estiver vazia
 * (instalacao antiga/local sem gestao de planos), libera tudo por padrao.
 */
function exigirModulo(PDO $pdo, string $modulo): void {
    static $modulosCache = null;

    if ($modulosCache === null) {
        $modulosCache = [];
        try {
            $stmt = $pdo->query("SELECT modulos_habilitados FROM empresa ORDER BY id DESC LIMIT 1");
            $row = $stmt ? $stmt->fetch() : null;
            $raw = $row['modulos_habilitados'] ?? null;
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $modulosCache = $decoded;
                }
            } else {
                $modulosCache = null; // coluna ausente/vazia: nao restringe
            }
        } catch (Throwable $e) {
            $modulosCache = null; // coluna nao existe (instalacao antiga): nao restringe
        }
    }

    if ($modulosCache !== null && !in_array($modulo, $modulosCache, true)) {
        jsonResponse(['error' => "O modulo \"{$modulo}\" nao esta disponivel no plano contratado."], 403);
    }
}

function getJsonInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

function registerGlobalErrorHandlers(PDO $pdo): void {
    set_exception_handler(function (Throwable $e) {
        error_log('[global-exception] ' . $e->getMessage());
        jsonResponse([
            'error' => 'Erro interno inesperado',
            'error_code' => 'UNHANDLED_EXCEPTION',
            'details' => [
                'exception' => get_class($e),
                'line' => $e->getLine(),
                'file' => basename((string)$e->getFile())
            ]
        ], 500);
    });

    set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

function getSystemVersion(): string {
    return SYSTEM_VERSION;
}

function dbBootstrapLog(string $level, string $message, array $context = []): void {
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('c'),
        strtoupper($level),
        $message,
        !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );

    error_log('[db-bootstrap] ' . $line);

    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'db_bootstrap.log', $line . PHP_EOL, FILE_APPEND);
}

function ensureBootstrapLogTable(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS db_bootstrap_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            nivel VARCHAR(20) NOT NULL,
            mensagem VARCHAR(255) NOT NULL,
            contexto JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bootstrap_log_data (created_at, nivel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        dbBootstrapLog('warn', 'Falha ao preparar db_bootstrap_log', ['error' => $e->getMessage()]);
    }
}

function persistBootstrapLog(PDO $pdo, string $level, string $message, array $context = []): void {
    ensureBootstrapLogTable($pdo);
    try {
        $stmt = $pdo->prepare('INSERT INTO db_bootstrap_log (nivel, mensagem, contexto) VALUES (?, ?, ?)');
        $stmt->execute([
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null
        ]);
    } catch (Throwable $e) {
        dbBootstrapLog('warn', 'Falha ao persistir log de bootstrap no banco', ['error' => $e->getMessage()]);
    }
}

function hasTable(PDO $pdo, string $table): bool {
    try {
        $tabelaReal = comanda_prefixo_tenant_atual($pdo).$table;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$tabelaReal]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $tabelaReal = comanda_prefixo_tenant_atual($pdo).$table;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tabelaReal, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ensureBaseBusinessTables(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    $ddl = [
        "CREATE TABLE IF NOT EXISTS empresa (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            cnpj VARCHAR(20) NULL,
            endereco TEXT NULL,
            telefone VARCHAR(20) NULL,
            email VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS funcionarios (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            login VARCHAR(100) NOT NULL,
            senha VARCHAR(255) NOT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            permissoes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_func_login (login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS clientes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            cpf VARCHAR(14) NULL,
            contato VARCHAR(20) NULL,
            email VARCHAR(100) NULL,
            pontos_fidelidade INT NOT NULL DEFAULT 0,
            total_gasto DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_visitas INT NOT NULL DEFAULT 0,
            ultima_visita DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cliente_cpf (cpf)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS produtos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            categoria VARCHAR(80) NOT NULL DEFAULT 'outros',
            preco DECIMAL(12,2) NOT NULL,
            descricao TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS estoque (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(255) NOT NULL,
            categoria VARCHAR(80) NOT NULL,
            quantidade DECIMAL(12,4) NOT NULL DEFAULT 0,
            unidade VARCHAR(20) NOT NULL,
            quantidade_minima DECIMAL(12,4) NOT NULL DEFAULT 5,
            valor_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS comandas (
            id INT PRIMARY KEY AUTO_INCREMENT,
            numero_mesa VARCHAR(50) NOT NULL,
            funcionario_id INT NOT NULL,
            cliente_id INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'aberta',
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            forma_pagamento VARCHAR(50) NULL,
            observacoes TEXT NULL,
            versao INT NOT NULL DEFAULT 1,
            fechamento_data DATETIME NULL,
            duracao VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_comandas_status (status),
            INDEX idx_comandas_funcionario (funcionario_id),
            INDEX idx_comandas_cliente (cliente_id),
            CONSTRAINT fk_comandas_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
            CONSTRAINT fk_comandas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS comanda_itens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            comanda_id INT NOT NULL,
            produto_id INT NULL,
            nome_item VARCHAR(255) NOT NULL,
            categoria VARCHAR(80) NULL,
            quantidade DECIMAL(12,2) NOT NULL,
            valor_unitario DECIMAL(12,2) NOT NULL,
            total DECIMAL(12,2) NOT NULL,
            observacoes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_comanda_itens_comanda (comanda_id),
            CONSTRAINT fk_comanda_itens_comanda FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE,
            CONSTRAINT fk_comanda_itens_produto FOREIGN KEY (produto_id) REFERENCES produtos(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS cliente_historico (
            id INT PRIMARY KEY AUTO_INCREMENT,
            cliente_id INT NOT NULL,
            comanda_id INT NOT NULL,
            valor_total DECIMAL(12,2) NOT NULL,
            pontos_ganhos INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente_hist_cliente_data (cliente_id, created_at),
            CONSTRAINT fk_cliente_hist_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
            CONSTRAINT fk_cliente_hist_comanda FOREIGN KEY (comanda_id) REFERENCES comandas(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS lista_compras (
            id INT PRIMARY KEY AUTO_INCREMENT,
            estoque_id INT NULL,
            nome_item VARCHAR(255) NOT NULL,
            quantidade_necessaria DECIMAL(12,4) NOT NULL,
            quantidade_minima DECIMAL(12,4) NULL,
            unidade VARCHAR(20) NULL,
            prioridade VARCHAR(20) NOT NULL DEFAULT 'media',
            status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_lista_compras_status (status, prioridade),
            CONSTRAINT fk_lista_compras_estoque FOREIGN KEY (estoque_id) REFERENCES estoque(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS sessoes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            funcionario_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_sessoes_token (token(120)),
            CONSTRAINT fk_sessoes_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            dbBootstrapLog('error', 'Falha ao criar tabela base', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM comandas')->fetchAll(PDO::FETCH_ASSOC);
        $statusType = '';
        foreach ($cols as $c) {
            if (($c['Field'] ?? '') === 'status') {
                $statusType = (string)($c['Type'] ?? '');
                break;
            }
        }
        if (strpos($statusType, 'enum(') === 0 && strpos($statusType, "'cancelada'") === false) {
            $pdo->exec("ALTER TABLE comandas MODIFY COLUMN status ENUM('aberta','fechada','cancelada') NOT NULL DEFAULT 'aberta'");
            dbBootstrapLog('info', 'Migracao aplicada: comandas.status incluiu cancelada');
        }
    } catch (Throwable $e) {
        dbBootstrapLog('warn', 'Falha ao ajustar comandas.status', ['error' => $e->getMessage()]);
    }

    if (!hasColumn($pdo, 'comandas', 'forma_pagamento')) {
        try {
            $pdo->exec('ALTER TABLE comandas ADD COLUMN forma_pagamento VARCHAR(50) NULL DEFAULT NULL');
        } catch (Throwable $e) {
            dbBootstrapLog('warn', 'Falha ao criar comandas.forma_pagamento', ['error' => $e->getMessage()]);
        }
    }
    if (!hasColumn($pdo, 'comandas', 'observacoes')) {
        try {
            $pdo->exec('ALTER TABLE comandas ADD COLUMN observacoes TEXT NULL DEFAULT NULL');
        } catch (Throwable $e) {
            dbBootstrapLog('warn', 'Falha ao criar comandas.observacoes', ['error' => $e->getMessage()]);
        }
    }
    if (!hasColumn($pdo, 'comandas', 'versao')) {
        try {
            $pdo->exec('ALTER TABLE comandas ADD COLUMN versao INT NOT NULL DEFAULT 1');
        } catch (Throwable $e) {
            dbBootstrapLog('warn', 'Falha ao criar comandas.versao', ['error' => $e->getMessage()]);
        }
    }

    if (!hasColumn($pdo, 'comanda_itens', 'observacoes')) {
        try {
            $pdo->exec('ALTER TABLE comanda_itens ADD COLUMN observacoes TEXT NULL DEFAULT NULL');
        } catch (Throwable $e) {
            dbBootstrapLog('warn', 'Falha ao criar comanda_itens.observacoes', ['error' => $e->getMessage()]);
        }
    }

    $ready = true;
}

function normalizeRole(?string $role): string {
    $role = strtolower(trim((string)$role));
    $aliases = [
        'administrador' => 'admin',
        'gerente' => 'gerencia',
        'cozinha_bar' => 'cozinha'
    ];
    if (isset($aliases[$role])) {
        $role = $aliases[$role];
    }

    $valid = ['admin', 'gerencia', 'caixa', 'garcom', 'cozinha'];
    if (!in_array($role, $valid, true)) {
        return 'garcom';
    }
    return $role;
}

function getRoleDefaultPermissions(string $role): array {
    $role = normalizeRole($role);
    $map = [
        'admin' => [
            'dashboard', 'mesas', 'compras',
            'funcionarios', 'clientes', 'comandas', 'cozinha', 'produtos', 'estoque',
            'caixa', 'relatorios', 'backup', 'configuracoes',
            'COMANDA_CANCELAR', 'COMANDA_REABRIR', 'COMANDA_TRANSFERIR', 'COMANDA_DIVIDIR', 'COMANDA_JUNTAR',
            'COMANDA_ESTORNO_PRODUCAO',
            'PDV_DESCONTO_APLICAR', 'PDV_DESCONTO_ACIMA_LIMITE', 'PDV_ESTORNO', 'PDV_SANGRIA_SUPRIMENTO',
            'ESTOQUE_AJUSTAR', 'SISTEMA_EXPORTAR_DADOS', 'SISTEMA_VER_LOGS'
        ],
        'gerencia' => [
            'dashboard', 'mesas', 'compras',
            'clientes', 'comandas', 'cozinha', 'produtos', 'estoque', 'caixa', 'relatorios',
            'COMANDA_CANCELAR', 'COMANDA_REABRIR', 'COMANDA_TRANSFERIR', 'COMANDA_DIVIDIR', 'COMANDA_JUNTAR',
            'COMANDA_ESTORNO_PRODUCAO',
            'PDV_DESCONTO_APLICAR', 'PDV_DESCONTO_ACIMA_LIMITE', 'PDV_ESTORNO', 'PDV_SANGRIA_SUPRIMENTO',
            'ESTOQUE_AJUSTAR', 'SISTEMA_VER_LOGS'
        ],
        'caixa' => [
            'dashboard', 'comandas', 'caixa', 'relatorios',
            'PDV_DESCONTO_APLICAR', 'PDV_ESTORNO', 'PDV_SANGRIA_SUPRIMENTO'
        ],
        'garcom' => [
            'dashboard', 'mesas', 'clientes', 'comandas',
            'COMANDA_TRANSFERIR'
        ],
        'cozinha' => [
            'dashboard', 'cozinha'
        ]
    ];
    return $map[$role] ?? [];
}

function mergeRoleAndCustomPermissions(string $role, array $customPermissions): array {
    $rolePerms = getRoleDefaultPermissions($role);
    $all = array_merge($rolePerms, $customPermissions);
    $all = array_values(array_unique(array_filter($all, static fn($v) => is_string($v) && trim($v) !== '')));
    return $all;
}

function getPermissionsCatalogDefaults(): array {
    return [
        ['dashboard', 'Acessar dashboard', 'menu', 0],
        ['mesas', 'Acessar mapa de mesas', 'menu', 0],
        ['compras', 'Acessar compras', 'menu', 0],
        ['funcionarios', 'Acessar e gerenciar funcionarios', 'menu', 0],
        ['clientes', 'Acessar clientes', 'menu', 0],
        ['comandas', 'Acessar comandas', 'menu', 0],
        ['cozinha', 'Acessar KDS/cozinha', 'menu', 0],
        ['produtos', 'Acessar produtos', 'menu', 0],
        ['estoque', 'Acessar estoque', 'menu', 0],
        ['caixa', 'Acessar caixa', 'menu', 0],
        ['relatorios', 'Acessar relatorios', 'menu', 0],
        ['backup', 'Acessar backup', 'menu', 0],
        ['configuracoes', 'Acessar configuracoes', 'menu', 0],
        ['COMANDA_CANCELAR', 'Cancelar comanda', 'comanda', 1],
        ['COMANDA_REABRIR', 'Reabrir comanda', 'comanda', 1],
        ['COMANDA_TRANSFERIR', 'Transferir mesa/garcom', 'comanda', 0],
        ['COMANDA_DIVIDIR', 'Dividir comanda', 'comanda', 0],
        ['COMANDA_JUNTAR', 'Juntar comandas', 'comanda', 0],
        ['COMANDA_ESTORNO_PRODUCAO', 'Estornar item ja enviado/producao', 'comanda', 1],
        ['PDV_DESCONTO_APLICAR', 'Aplicar desconto no PDV', 'financeiro', 1],
        ['PDV_DESCONTO_ACIMA_LIMITE', 'Aplicar desconto acima do limite', 'financeiro', 1],
        ['PDV_ESTORNO', 'Estornar pagamento', 'financeiro', 1],
        ['PDV_SANGRIA_SUPRIMENTO', 'Executar sangria/suprimento', 'financeiro', 1],
        ['ESTOQUE_AJUSTAR', 'Ajustar estoque manualmente', 'estoque', 1],
        ['SISTEMA_EXPORTAR_DADOS', 'Exportar dados', 'sistema', 1],
        ['SISTEMA_VER_LOGS', 'Ver auditoria/logs', 'sistema', 1]
    ];
}

function ensurePermissionsGovernanceTables(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissoes_catalog (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            chave VARCHAR(120) NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            categoria VARCHAR(80) NOT NULL DEFAULT 'geral',
            is_critica TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_permissao_chave (chave),
            INDEX idx_permissao_categoria (categoria, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar permissoes_catalog: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissoes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            role VARCHAR(30) NOT NULL,
            permissao_chave VARCHAR(120) NOT NULL,
            allowed TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_role_perm (role, permissao_chave),
            INDEX idx_role_permissoes_role (role, allowed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar role_permissoes: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO permissoes_catalog (chave, descricao, categoria, is_critica, is_active) VALUES (?, ?, ?, ?, 1)');
        foreach (getPermissionsCatalogDefaults() as $perm) {
            $stmt->execute([$perm[0], $perm[1], $perm[2], $perm[3]]);
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao semear permissoes_catalog: ' . $e->getMessage());
    }

    $ready = true;
}

function getRolePermissionOverrides(PDO $pdo, string $role): array {
    ensurePermissionsGovernanceTables($pdo);
    try {
        $stmt = $pdo->prepare('SELECT permissao_chave, allowed FROM role_permissoes WHERE role = ?');
        $stmt->execute([normalizeRole($role)]);
        $rows = $stmt->fetchAll();
        $allow = [];
        $deny = [];
        foreach ($rows as $row) {
            $chave = trim((string)($row['permissao_chave'] ?? ''));
            if ($chave === '') continue;
            if (!empty($row['allowed'])) $allow[] = $chave; else $deny[] = $chave;
        }
        return ['allow' => array_values(array_unique($allow)), 'deny' => array_values(array_unique($deny))];
    } catch (Throwable $e) {
        return ['allow' => [], 'deny' => []];
    }
}

function ensureSchemaVersionTable(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            version VARCHAR(64) NOT NULL,
            description VARCHAR(255) NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_schema_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $pdo->prepare('INSERT IGNORE INTO schema_version (version, description) VALUES (?, ?)');
        $stmt->execute(['2026.05.18.foundation', 'Fundacao: roles, seguranca de login e governanca']);
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar schema_version: ' . $e->getMessage());
    }

    $ready = true;
}

function getCurrentSchemaVersion(PDO $pdo): ?string {
    try {
        $stmt = $pdo->query('SELECT version FROM schema_version ORDER BY id DESC LIMIT 1');
        $row = $stmt ? $stmt->fetch() : null;
        return $row['version'] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

function hasSchemaVersion(PDO $pdo, string $version): bool {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM schema_version WHERE version = ? LIMIT 1');
        $stmt->execute([$version]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function markSchemaVersion(PDO $pdo, string $version, string $description): void {
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_version (version, description) VALUES (?, ?)');
    $stmt->execute([$version, $description]);
}

function applySchemaMigrations(PDO $pdo): void {
    ensureSchemaVersionTable($pdo);

    $migrations = [
        [
            'version' => '2026.06.03.base_bootstrap',
            'description' => 'Criacao/verificacao de tabelas base do sistema',
            'apply' => function (PDO $pdoInner): void {
                ensureBaseBusinessTables($pdoInner);
            }
        ],
        [
            'version' => '2026.06.03.core_operational',
            'description' => 'Estruturas operacionais, auditoria, caixa, KDS e integracoes',
            'apply' => function (PDO $pdoInner): void {
                ensureCoreOperationalTables($pdoInner);
                ensureAuditTables($pdoInner);
                ensurePermissionsGovernanceTables($pdoInner);
            }
        ]
    ];

    foreach ($migrations as $migration) {
        $version = (string)$migration['version'];
        $description = (string)$migration['description'];

        if (hasSchemaVersion($pdo, $version)) {
            continue;
        }

        try {
            $migration['apply']($pdo);
            markSchemaVersion($pdo, $version, $description);
            dbBootstrapLog('info', 'Migracao aplicada', ['version' => $version, 'description' => $description]);
            persistBootstrapLog($pdo, 'info', 'Migracao aplicada', ['version' => $version, 'description' => $description]);
        } catch (Throwable $e) {
            dbBootstrapLog('error', 'Falha ao aplicar migracao', [
                'version' => $version,
                'description' => $description,
                'error' => $e->getMessage()
            ]);
            persistBootstrapLog($pdo, 'error', 'Falha ao aplicar migracao', [
                'version' => $version,
                'description' => $description,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_operacoes_historico (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            operacao VARCHAR(60) NOT NULL,
            payload JSON NULL,
            actor_id INT NULL,
            actor_login VARCHAR(120) NULL,
            actor_nome VARCHAR(120) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_comanda_operacao_created (created_at),
            INDEX idx_comanda_operacao_tipo (operacao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar comanda_operacoes_historico: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_request_dedupe (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            request_id VARCHAR(80) NOT NULL,
            comanda_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_comanda_request (request_id),
            INDEX idx_comanda_request_created (created_at),
            FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar comanda_request_dedupe: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos_comanda (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            comanda_id INT NOT NULL,
            tipo VARCHAR(30) NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'confirmado',
            transacao_id VARCHAR(120) NULL,
            metadata JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE,
            INDEX idx_pagamentos_comanda (comanda_id, created_at),
            INDEX idx_pagamentos_tipo_status (tipo, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar pagamentos_comanda: ' . $e->getMessage());
    }

    try {
        $pagCols = $pdo->query('SHOW COLUMNS FROM pagamentos_comanda')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status', $pagCols, true)) {
            $pdo->exec("ALTER TABLE pagamentos_comanda ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'confirmado'");
        }
        if (!in_array('transacao_id', $pagCols, true)) {
            $pdo->exec("ALTER TABLE pagamentos_comanda ADD COLUMN transacao_id VARCHAR(120) NULL");
        }
        if (!in_array('metadata', $pagCols, true)) {
            $pdo->exec("ALTER TABLE pagamentos_comanda ADD COLUMN metadata LONGTEXT NULL");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar colunas de pagamentos_comanda: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS caixa_sessoes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            operador_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'aberto',
            valor_inicial DECIMAL(12,2) NOT NULL DEFAULT 0,
            valor_contado DECIMAL(12,2) NULL,
            divergencia DECIMAL(12,2) NULL,
            observacao_abertura VARCHAR(255) NULL,
            observacao_fechamento VARCHAR(255) NULL,
            aberto_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fechado_em DATETIME NULL,
            FOREIGN KEY (operador_id) REFERENCES funcionarios(id),
            INDEX idx_caixa_status_aberto (status, aberto_em),
            INDEX idx_caixa_operador (operador_id, aberto_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar caixa_sessoes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS caixa_movimentacoes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            caixa_sessao_id BIGINT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            motivo VARCHAR(255) NOT NULL,
            actor_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (caixa_sessao_id) REFERENCES caixa_sessoes(id) ON DELETE CASCADE,
            INDEX idx_caixa_mov_sessao (caixa_sessao_id, created_at),
            INDEX idx_caixa_mov_tipo (tipo, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar caixa_movimentacoes: ' . $e->getMessage());
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
        if (!in_array('role', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'garcom'");
        }
        if (!in_array('nome_exibicao', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN nome_exibicao VARCHAR(120) NULL");
        }
        if (!in_array('ultimo_login', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN ultimo_login DATETIME NULL DEFAULT NULL");
        }
        if (!in_array('failed_login_attempts', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN failed_login_attempts SMALLINT NOT NULL DEFAULT 0");
        }
        if (!in_array('blocked_until', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN blocked_until DATETIME NULL DEFAULT NULL");
        }
        if (!in_array('pin_hash', $funcCols, true)) {
            $pdo->exec("ALTER TABLE funcionarios ADD COLUMN pin_hash VARCHAR(255) NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar colunas de sessao em funcionarios: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE funcionarios ADD INDEX idx_funcionarios_role_status (role, is_active)");
    } catch (Throwable $e) {
        // ignora quando indice ja existe
    }

    try {
        $pdo->exec("ALTER TABLE comanda_itens ADD INDEX idx_comanda_itens_comanda_status (comanda_id, kitchen_status)");
    } catch (Throwable $e) {
        // Ignora quando indice ja existe ou coluna ainda nao foi criada.
    }

    try {
        $itemCols = $pdo->query('SHOW COLUMNS FROM comanda_itens')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('kitchen_status', $itemCols, true)) {
            $pdo->exec("ALTER TABLE comanda_itens ADD COLUMN kitchen_status ENUM('recebido','em_preparo','pronto','entregue','cancelado') NOT NULL DEFAULT 'recebido'");
        } else {
            try {
                $pdo->exec("UPDATE comanda_itens SET kitchen_status = 'recebido' WHERE kitchen_status = 'pendente'");
            } catch (Throwable $e) {
                // Melhor esforco para migracao de valor legado.
            }
            $pdo->exec("ALTER TABLE comanda_itens MODIFY COLUMN kitchen_status ENUM('recebido','em_preparo','pronto','entregue','cancelado') NOT NULL DEFAULT 'recebido'");
        }
        if (!in_array('kitchen_setor', $itemCols, true)) {
            $pdo->exec("ALTER TABLE comanda_itens ADD COLUMN kitchen_setor VARCHAR(40) NOT NULL DEFAULT 'cozinha'");
        }
        if (!in_array('enviado_producao_at', $itemCols, true)) {
            $pdo->exec("ALTER TABLE comanda_itens ADD COLUMN enviado_producao_at DATETIME NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar colunas KDS em comanda_itens: ' . $e->getMessage());
    }

    try {
        $prodCols = $pdo->query('SHOW COLUMNS FROM produtos')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('setor', $prodCols, true)) {
            $pdo->exec("ALTER TABLE produtos ADD COLUMN setor VARCHAR(40) NOT NULL DEFAULT 'cozinha'");
        }
        if (!in_array('imagem_url', $prodCols, true)) {
            $pdo->exec("ALTER TABLE produtos ADD COLUMN imagem_url VARCHAR(255) NULL DEFAULT NULL");
        }
        if (!in_array('tags_json', $prodCols, true)) {
            $pdo->exec("ALTER TABLE produtos ADD COLUMN tags_json JSON NULL");
        }
        if (!in_array('is_disponivel', $prodCols, true)) {
            $pdo->exec("ALTER TABLE produtos ADD COLUMN is_disponivel TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produtos.setor: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produto_variacoes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            produto_id INT NOT NULL,
            grupo VARCHAR(80) NOT NULL,
            nome VARCHAR(120) NOT NULL,
            sku VARCHAR(80) NULL,
            preco_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_var_prod_grupo (produto_id, grupo, is_active),
            FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produto_variacoes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produto_adicionais (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            produto_id INT NULL,
            categoria VARCHAR(80) NULL,
            nome VARCHAR(120) NOT NULL,
            preco DECIMAL(12,2) NOT NULL DEFAULT 0,
            obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
            limite_min INT NOT NULL DEFAULT 0,
            limite_max INT NOT NULL DEFAULT 3,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_add_prod_cat (produto_id, categoria, is_active),
            FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produto_adicionais: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produto_combos (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(120) NOT NULL,
            descricao VARCHAR(255) NULL,
            preco_combo DECIMAL(12,2) NOT NULL,
            regras JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produto_combos: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produto_combos_itens (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            combo_id BIGINT NOT NULL,
            produto_id INT NOT NULL,
            quantidade DECIMAL(12,2) NOT NULL DEFAULT 1,
            obrigatorio TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (combo_id) REFERENCES produto_combos(id) ON DELETE CASCADE,
            FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
            INDEX idx_combo_item_combo (combo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produto_combos_itens: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produto_promocoes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            nome VARCHAR(120) NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'percentual',
            valor DECIMAL(12,2) NOT NULL,
            produto_id INT NULL,
            categoria VARCHAR(80) NULL,
            dia_semana VARCHAR(20) NULL,
            hora_inicio TIME NULL,
            hora_fim TIME NULL,
            regras JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
            INDEX idx_promocoes_ativas (is_active, produto_id, categoria)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produto_promocoes: ' . $e->getMessage());
    }

    try {
        $estoqueCols = $pdo->query('SHOW COLUMNS FROM estoque')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('custo_medio', $estoqueCols, true)) {
            $pdo->exec("ALTER TABLE estoque ADD COLUMN custo_medio DECIMAL(12,4) NOT NULL DEFAULT 0");
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar estoque.custo_medio: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produto_fichas_tecnicas (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            produto_id INT NOT NULL,
            estoque_id INT NOT NULL,
            quantidade DECIMAL(12,4) NOT NULL,
            unidade VARCHAR(30) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ficha_item (produto_id, estoque_id),
            INDEX idx_ficha_produto (produto_id, is_active),
            FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
            FOREIGN KEY (estoque_id) REFERENCES estoque(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar produto_fichas_tecnicas: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS estoque_movimentacoes (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            estoque_id INT NOT NULL,
            tipo VARCHAR(30) NOT NULL,
            quantidade DECIMAL(12,4) NOT NULL,
            custo_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,
            comanda_id INT NULL,
            referencia_tipo VARCHAR(40) NULL,
            referencia_id VARCHAR(80) NULL,
            documento_origem VARCHAR(80) NULL,
            fornecedor_nome VARCHAR(160) NULL,
            motivo VARCHAR(255) NULL,
            metadados JSON NULL,
            actor_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_est_mov_item_data (estoque_id, created_at),
            INDEX idx_est_mov_tipo_data (tipo, created_at),
            FOREIGN KEY (estoque_id) REFERENCES estoque(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar estoque_movimentacoes: ' . $e->getMessage());
    }

    try {
        $movCols = $pdo->query('SHOW COLUMNS FROM estoque_movimentacoes')->fetchAll(PDO::FETCH_COLUMN);
        $movAdditions = [
            'documento_origem' => 'ALTER TABLE estoque_movimentacoes ADD COLUMN documento_origem VARCHAR(80) NULL AFTER referencia_id',
            'fornecedor_nome' => 'ALTER TABLE estoque_movimentacoes ADD COLUMN fornecedor_nome VARCHAR(160) NULL AFTER documento_origem',
            'metadados' => 'ALTER TABLE estoque_movimentacoes ADD COLUMN metadados JSON NULL AFTER motivo'
        ];
        foreach ($movAdditions as $column => $sql) {
            if (!in_array($column, $movCols, true)) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar estoque_movimentacoes.colunas: ' . $e->getMessage());
    }

    try {
        $clienteCols = $pdo->query('SHOW COLUMNS FROM clientes')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('data_nascimento', $clienteCols, true)) {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN data_nascimento DATE NULL');
        }
        if (!in_array('observacoes', $clienteCols, true)) {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN observacoes TEXT NULL');
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar colunas de marketing em clientes: ' . $e->getMessage());
    }

    try {
        $listaCols = $pdo->query('SHOW COLUMNS FROM lista_compras')->fetchAll(PDO::FETCH_COLUMN);
        $listaAdditions = [
            'fornecedor_nome' => 'ALTER TABLE lista_compras ADD COLUMN fornecedor_nome VARCHAR(160) NULL',
            'nota_fiscal' => 'ALTER TABLE lista_compras ADD COLUMN nota_fiscal VARCHAR(80) NULL',
            'custo_unitario_real' => 'ALTER TABLE lista_compras ADD COLUMN custo_unitario_real DECIMAL(12,4) NULL',
            'recebido_em' => 'ALTER TABLE lista_compras ADD COLUMN recebido_em DATETIME NULL',
            'observacoes' => 'ALTER TABLE lista_compras ADD COLUMN observacoes VARCHAR(255) NULL'
        ];
        foreach ($listaAdditions as $column => $sql) {
            if (!in_array($column, $listaCols, true)) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar lista_compras.recebimento: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cupons (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL,
            tipo_desconto ENUM('percentual','valor') NOT NULL DEFAULT 'percentual',
            valor_desconto DECIMAL(12,2) NOT NULL,
            valor_minimo_pedido DECIMAL(12,2) NOT NULL DEFAULT 0,
            validade_inicio DATETIME NULL,
            validade_fim DATETIME NULL,
            limite_uso INT NULL,
            usos_atuais INT NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            regras JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cupom_codigo (codigo),
            INDEX idx_cupom_ativo_validade (ativo, validade_fim)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar cupons: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cliente_consentimento (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            cliente_id INT NOT NULL,
            tipo_consentimento VARCHAR(40) NOT NULL,
            aceito TINYINT(1) NOT NULL DEFAULT 0,
            origem VARCHAR(40) NULL,
            observacao VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cliente_consent_tipo (cliente_id, tipo_consentimento),
            INDEX idx_consent_tipo_data (tipo_consentimento, created_at),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar cliente_consentimento: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE cliente_consentimento ADD UNIQUE KEY uniq_cliente_consent_tipo (cliente_id, tipo_consentimento)");
    } catch (Throwable $e) {
        // ignora quando indice ja existe
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_automacoes_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            tipo VARCHAR(40) NOT NULL,
            cliente_id INT NULL,
            payload JSON NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            executado_em DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_marketing_tipo_status (tipo, status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar marketing_automacoes_log: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kds_impressao_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            comanda_id INT NOT NULL,
            setor VARCHAR(40) NOT NULL,
            payload_hash VARCHAR(64) NOT NULL,
            payload JSON NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'gerado',
            actor_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            impresso_em DATETIME NULL,
            UNIQUE KEY uniq_kds_print (comanda_id, setor, payload_hash),
            INDEX idx_kds_print_status (status, created_at),
            INDEX idx_kds_print_comanda_setor (comanda_id, setor, created_at),
            FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar kds_impressao_log: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_request_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            rota VARCHAR(255) NOT NULL,
            metodo VARCHAR(10) NOT NULL,
            status_code INT NOT NULL,
            duracao_ms INT NOT NULL,
            ip_address VARCHAR(45) NULL,
            actor_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_api_log_data_status (created_at, status_code),
            INDEX idx_api_log_rota (rota(120), created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar api_request_log: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS error_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            rota VARCHAR(255) NOT NULL,
            metodo VARCHAR(10) NOT NULL,
            status_code INT NOT NULL,
            error_code VARCHAR(80) NULL,
            mensagem VARCHAR(255) NULL,
            detalhes JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_error_events_data (created_at, status_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[config.php] Falha ao preparar error_events: ' . $e->getMessage());
    }

    ensurePermissionsGovernanceTables($pdo);

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
        $stmt = $pdo->prepare("SELECT id, nome, nome_exibicao, login, role, is_admin, permissoes FROM funcionarios WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$actorId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['is_admin'] = (int)($row['is_admin'] ?? 0) === 1;
        $row['role'] = normalizeRole($row['role'] ?? ($row['is_admin'] ? 'admin' : 'garcom'));
        $permissoes = json_decode((string)($row['permissoes'] ?? '[]'), true);
        $customPerms = is_array($permissoes) ? $permissoes : [];
        $effective = mergeRoleAndCustomPermissions($row['role'], $customPerms);
        $roleOverrides = getRolePermissionOverrides($pdo, $row['role']);
        $effective = array_values(array_unique(array_merge($effective, $roleOverrides['allow'])));
        if (!empty($roleOverrides['deny'])) {
            $effective = array_values(array_filter($effective, static fn($perm) => !in_array($perm, $roleOverrides['deny'], true)));
        }
        $row['permissoes'] = $effective;
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

function registerApiRequestLogging(PDO $pdo): void {
    $start = microtime(true);
    $rota = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? 'unknown');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    register_shutdown_function(function () use ($pdo, $start, $rota, $metodo, $ip) {
        try {
            $status = (int)http_response_code();
            if ($status <= 0) $status = 200;
            $duracao = (int)round((microtime(true) - $start) * 1000);

            $actorId = null;
            try {
                $raw = file_get_contents('php://input');
                $json = json_decode((string)$raw, true);
                if (is_array($json) && isset($json['_audit']['actor_id']) && is_numeric($json['_audit']['actor_id'])) {
                    $actorId = (int)$json['_audit']['actor_id'];
                }
            } catch (Throwable $e) {
                $actorId = null;
            }

            $stmt = $pdo->prepare("INSERT INTO api_request_log (rota, metodo, status_code, duracao_ms, ip_address, actor_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$rota, $metodo, $status, $duracao, $ip, $actorId]);
        } catch (Throwable $e) {
            error_log('[config.php] Falha ao registrar api_request_log: ' . $e->getMessage());
        }
    });
}

// Prepara estrutura de auditoria fora de transacoes de negocio.
ensureSchemaVersionTable($pdo);
applySchemaMigrations($pdo);
ensureBaseBusinessTables($pdo);
ensureAuditTables($pdo);
ensureCoreOperationalTables($pdo);
ensurePermissionsGovernanceTables($pdo);
persistBootstrapLog($pdo, 'info', 'Bootstrap de schema finalizado', [
    'schema_version' => getCurrentSchemaVersion($pdo),
    'system_version' => getSystemVersion()
]);
registerGlobalErrorHandlers($pdo);
registerApiRequestLogging($pdo);
