<?php
// Bootstrap do painel administrativo (SaaS) em PHP puro.
// Roda direto no Apache/hospedagem, sem Composer/Laravel/artisan.
// Detecta automaticamente localhost (XAMPP) x producao (dominio real) para
// usar as credenciais de banco e URLs corretas em cada ambiente.

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__.'/../db_config_helper.php';

$comandaAdminEhLocal = comanda_is_local_request();
$comandaAdminEsquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$comandaAdminHost = (string) ($_SERVER['HTTP_HOST'] ?? 'saascomanda.gt.tc');

// Override opcional (nao versionado) para trocar credenciais sem editar codigo.
$comandaAdminRuntimeFile = __DIR__.'/db_runtime_config.php';
$comandaAdminRuntime = is_file($comandaAdminRuntimeFile) ? require $comandaAdminRuntimeFile : null;

if (is_array($comandaAdminRuntime)) {
    // --- Ambiente com override manual (db_runtime_config.php) ---
    define('SAAS_DB_HOST', (string) ($comandaAdminRuntime['host'] ?? '127.0.0.1'));
    define('SAAS_DB_PORT', (int) ($comandaAdminRuntime['port'] ?? 3306));
    define('SAAS_DB_NAME', (string) ($comandaAdminRuntime['dbname'] ?? 'comanda_saas'));
    define('SAAS_DB_USER', (string) ($comandaAdminRuntime['user'] ?? 'root'));
    define('SAAS_DB_PASS', (string) ($comandaAdminRuntime['pass'] ?? ''));
    define('TENANTS_DB_ROOT_USER', SAAS_DB_USER);
    define('TENANTS_DB_ROOT_PASS', SAAS_DB_PASS);
    define('TENANTS_SHARED_DB_NAME', (string) ($comandaAdminRuntime['shared_tenant_db'] ?? 'comanda_online'));
} elseif ($comandaAdminEhLocal) {
    // --- Ambiente local (XAMPP) ---
    define('SAAS_DB_HOST', '127.0.0.1');
    define('SAAS_DB_PORT', 3306);
    define('SAAS_DB_NAME', 'comanda_saas');
    define('SAAS_DB_USER', 'root');
    define('SAAS_DB_PASS', '');
    define('TENANTS_DB_ROOT_USER', 'root');
    define('TENANTS_DB_ROOT_PASS', '');
    define('TENANTS_SHARED_DB_NAME', 'comanda_online');
} else {
    // --- Ambiente de producao (hospedagem InfinityFree) ---
    define('SAAS_DB_HOST', 'sql111.infinityfree.com');
    define('SAAS_DB_PORT', 3306);
    define('SAAS_DB_NAME', 'if0_40322863_saascomanda');
    define('SAAS_DB_USER', 'if0_40322863');
    define('SAAS_DB_PASS', '');
    define('TENANTS_DB_ROOT_USER', SAAS_DB_USER);
    define('TENANTS_DB_ROOT_PASS', SAAS_DB_PASS);
    // Banco unico compartilhado por TODOS os clientes (InfinityFree free nao
    // permite CREATE DATABASE por SQL); cada cliente usa um prefixo de tabela.
    define('TENANTS_SHARED_DB_NAME', 'if0_40322863_comanda_online');
}

// --- Configuracao de provisionamento de tenants (clientes) ---
// Pasta raiz do sistema legado que sera copiada para cada novo cliente (sempre o pai de admin/, em qualquer ambiente).
const TENANTS_LEGACY_SOURCE_PATH = __DIR__.'/..';
// Pasta onde cada instancia de cliente e criada (uma subpasta por slug).
// Local: pasta irma de Comanda-Online-main (fora do projeto versionado).
// Producao: pasta "clientes" dentro do proprio htdocs do dominio (irma de admin/).
define('TENANTS_CLIENTS_BASE_PATH', $comandaAdminEhLocal ? 'C:/xampp/htdocs/clientes' : (dirname(__DIR__).'/clientes'));
// URL publica correspondente a pasta acima, usada para montar o link de acesso do cliente.
define('TENANTS_BASE_URL', $comandaAdminEhLocal ? 'http://localhost/clientes' : ($comandaAdminEsquema.'://'.$comandaAdminHost.'/clientes'));
// URL publica deste painel administrativo (usada no botao "Voltar ao Painel" dentro do sistema do cliente).
define('ADMIN_BASE_URL', $comandaAdminEhLocal ? 'http://localhost/Comanda-Online-main/admin' : ($comandaAdminEsquema.'://'.$comandaAdminHost.'/admin'));
// Segredo compartilhado para assinar o token de acesso direto (SSO) ao sistema do cliente.
// Precisa ser IDENTICO a constante SSO_SHARED_SECRET do config.php raiz (copiado para toda instancia de cliente).
const SSO_SHARED_SECRET = 'CoOnline-SSO-6f2a9c1d4e8b7053-troque-em-producao';
// Nome do banco de origem (estrutura de referencia) e credenciais para criar bancos de clientes.
const TENANTS_DB_SOURCE = 'comanda_online';
// Pastas/arquivos do sistema legado que NAO devem ser copiados para as instancias dos clientes.
const TENANTS_EXCLUDE = ['admin', 'saas-app', 'backups', 'logs', '.git', 'node_modules', 'vendor', 'db_runtime_config.php', '.env'];

/**
 * Divide um dump SQL em statements individuais, respeitando ponto-e-virgula dentro de strings.
 */
function sql_dividir_statements(string $sql): array
{
    $statements = [];
    $atual = '';
    $tamanho = strlen($sql);
    $dentroString = null;

    for ($i = 0; $i < $tamanho; $i++) {
        $char = $sql[$i];

        if ($dentroString !== null) {
            $atual .= $char;
            if ($char === '\\') {
                $i++;
                if ($i < $tamanho) {
                    $atual .= $sql[$i];
                }
                continue;
            }
            if ($char === $dentroString) {
                $dentroString = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $dentroString = $char;
            $atual .= $char;
            continue;
        }

        if ($char === ';') {
            $statements[] = $atual;
            $atual = '';
            continue;
        }

        $atual .= $char;
    }

    if (trim($atual) !== '') {
        $statements[] = $atual;
    }

    return array_map(static function (string $stmt): string {
        $linhas = array_filter(
            explode("\n", $stmt),
            static fn (string $linha) => !str_starts_with(trim($linha), '--')
        );
        return trim(implode("\n", $linhas));
    }, $statements);
}

/**
 * Cria as tabelas do painel (comanda_saas) e semeia plano/superadmin padrao
 * na primeira execucao. Necessario porque, em hospedagem compartilhada, o
 * banco costuma ser criado vazio manualmente pelo vPanel (sem tabelas).
 */
function saas_bootstrap_schema(PDO $pdo): void
{
    try {
        $existeTabela = (bool) $pdo->query("SHOW TABLES LIKE 'empresas'")->fetchColumn();
    } catch (Throwable $e) {
        $existeTabela = false;
    }

    if ($existeTabela) {
        saas_bootstrap_migracoes($pdo);
        return;
    }

    $arquivo = __DIR__.'/schema_saas.sql';
    if (!is_file($arquivo)) {
        return;
    }

    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        return;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (sql_dividir_statements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            error_log('[saas_bootstrap_schema] Falha ao executar statement: '.$e->getMessage());
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    saas_seed_dados_iniciais($pdo);
}

/**
 * Migracoes idempotentes para instalacoes que ja existiam antes de uma coluna
 * nova ser adicionada (ex.: banco de producao ja criado sem `table_prefix`).
 */
function saas_bootstrap_migracoes(PDO $pdo): void
{
    try {
        $existeColuna = (bool) $pdo->query("SHOW COLUMNS FROM empresas LIKE 'table_prefix'")->fetchColumn();
        if (!$existeColuna) {
            $pdo->exec('ALTER TABLE empresas ADD COLUMN table_prefix VARCHAR(64) NULL AFTER db_name');
        }
    } catch (Throwable $e) {
        error_log('[saas_bootstrap_migracoes] '.$e->getMessage());
    }
}

function saas_seed_dados_iniciais(PDO $pdo): void
{
    try {        $totalPlanos = (int) $pdo->query('SELECT COUNT(*) FROM planos')->fetchColumn();
        if ($totalPlanos === 0) {
            $modulosBasico = json_encode(['produtos', 'pedidos', 'comandas', 'mesas', 'cozinha', 'relatorios_basicos', 'impressao'], JSON_UNESCAPED_UNICODE);
            $modulosProfissional = json_encode(array_keys(MODULOS_DISPONIVEIS), JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("INSERT INTO planos (nome, slug, valor_mensal, limite_usuarios, modulos, ativo, created_at, updated_at) VALUES (?,?,?,?,?,1,NOW(),NOW())");
            $stmt->execute(['Basico', 'basico', 79.90, 3, $modulosBasico]);
            $stmt->execute(['Profissional', 'profissional', 149.90, 10, $modulosProfissional]);
        }

        $totalUsuarios = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_superadmin = 1')->fetchColumn();
        if ($totalUsuarios === 0) {
            $hash = password_hash('TesteSenha123!', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, is_superadmin, ativo, created_at, updated_at) VALUES (?,?,?,1,1,NOW(),NOW())");
            $stmt->execute(['Superadministrador', 'superadmin@comandaonline.com', $hash]);
        }
    } catch (Throwable $e) {
        error_log('[saas_seed_dados_iniciais] '.$e->getMessage());
    }
}

function pdo_saas(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host='.SAAS_DB_HOST.';port='.SAAS_DB_PORT.';dbname='.SAAS_DB_NAME.';charset=utf8mb4';
        $pdo = new PDO($dsn, SAAS_DB_USER, SAAS_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        saas_bootstrap_schema($pdo);
    }


    return $pdo;
}

function require_login(): array
{
    if (empty($_SESSION['admin_user_id'])) {
        header('Location: login.php');
        exit;
    }

    return [
        'id' => $_SESSION['admin_user_id'],
        'name' => $_SESSION['admin_user_name'],
        'email' => $_SESSION['admin_user_email'],
    ];
}

function flash_set(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function redirect(string $destino): void
{
    header('Location: '.$destino);
    exit;
}

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function gerar_slug(string $texto): string
{
    $slug = strtolower(trim($texto));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'cliente';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="'.e(csrf_token()).'">';
}

function csrf_verify(): void
{
    $token = $_POST['_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $token)) {
        http_response_code(419);
        die('Sessao expirada. Volte e tente novamente.');
    }
}

// Monta a URL de acesso direto (SSO) ao sistema do cliente, assinada com expiracao curta.
function montar_url_acesso_direto(array $empresa): ?string
{
    if (empty($empresa['slug']) || empty($empresa['login_admin']) || empty($empresa['provisionado_em'])) {
        return null;
    }

    $payload = [
        'login' => $empresa['login_admin'],
        'exp' => time() + 60,
        'admin_url' => ADMIN_BASE_URL.'/empresa_form.php?id='.(int) $empresa['id'],
    ];
    $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $assinatura = hash_hmac('sha256', $payloadB64, SSO_SHARED_SECRET);

    return rtrim(TENANTS_BASE_URL, '/').'/'.$empresa['slug'].'/sso_login.php?token='.$payloadB64.'.'.$assinatura;
}

const MODULOS_DISPONIVEIS = [
    'produtos' => 'Produtos e categorias',
    'pedidos' => 'Pedidos',
    'comandas' => 'Comandas',
    'mesas' => 'Mesas',
    'cozinha' => 'Cozinha / KDS',
    'delivery' => 'Delivery',
    'estoque' => 'Estoque',
    'caixa' => 'Caixa',
    'clientes' => 'Clientes',
    'relatorios_basicos' => 'Relatorios basicos',
    'relatorios_avancados' => 'Relatorios avancados',
    'impressao' => 'Impressao',
    'backup' => 'Backup',
];
