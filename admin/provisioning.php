<?php
// Motor de provisionamento de tenants (clientes) em PHP puro.
// Local (XAMPP): cria um banco proprio por cliente via PDO (CREATE DATABASE).
// Producao (hospedagem sem privilegio de CREATE DATABASE): usa um unico banco
// compartilhado (TENANTS_SHARED_DB_NAME) com tabelas prefixadas por cliente
// (ver db_tenant_prefix.php), sem nenhuma etapa manual por cliente novo.

declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/../db_tenant_prefix.php';

class TenantProvisioningException extends RuntimeException {}

/**
 * Sanitiza o slug para um prefixo de tabela MySQL valido e curto o bastante
 * para caber junto com o nome de tabela mais longo (limite de 64 chars).
 */
function tenant_gerar_prefixo_tabela(string $slug): string
{
    $prefixo = strtolower(str_replace('-', '_', $slug));
    $prefixo = preg_replace('/[^a-z0-9_]/', '', $prefixo) ?? '';
    $prefixo = substr($prefixo, 0, 28);

    return rtrim($prefixo, '_').'_';
}

/**
 * Conecta ao banco de um tenant. Quando $prefix e informado, usa ComandaPrefixedPDO
 * (banco compartilhado); caso contrario conecta normalmente (banco proprio, uso local).
 */
function tenant_conectar(string $dbName, string $prefix = ''): PDO
{
    $dsn = 'mysql:host='.SAAS_DB_HOST.';port='.SAAS_DB_PORT.';dbname='.$dbName.';charset=utf8mb4';
    $opcoes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    if ($prefix !== '') {
        return new ComandaPrefixedPDO($dsn, TENANTS_DB_ROOT_USER, TENANTS_DB_ROOT_PASS, $opcoes, $prefix);
    }

    return new PDO($dsn, TENANTS_DB_ROOT_USER, TENANTS_DB_ROOT_PASS, $opcoes);
}

function tenant_pdo_root(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host='.SAAS_DB_HOST.';port='.SAAS_DB_PORT.';charset=utf8mb4';
        $pdo = new PDO($dsn, TENANTS_DB_ROOT_USER, TENANTS_DB_ROOT_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    return $pdo;
}

function tenant_gerar_slug_unico(string $nome): string
{
    $base = gerar_slug($nome);
    $slug = $base;
    $i = 1;

    $stmt = pdo_saas()->prepare('SELECT COUNT(*) FROM empresas WHERE slug = ?');

    do {
        $stmt->execute([$slug]);
        $existe = (int) $stmt->fetchColumn() > 0;
        if ($existe) {
            $slug = $base.'-'.(++$i);
        }
    } while ($existe);

    return $slug;
}

function tenant_criar_banco(string $dbName): void
{
    if (!preg_match('/^[a-z0-9_]+$/', $dbName)) {
        throw new TenantProvisioningException("Nome de banco invalido: {$dbName}");
    }

    $pdo = tenant_pdo_root();
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/**
 * Executa o dump estrutural estatico (schema_legacy.sql), statement por statement,
 * via PDO. Quando $prefix e informado, cada CREATE/DROP/ALTER TABLE e reescrito
 * para operar em tabelas prefixadas dentro do banco compartilhado.
 */
function tenant_importar_estrutura(string $dbName, string $prefix = ''): void
{
    $arquivo = __DIR__.'/schema_legacy.sql';
    if (!is_file($arquivo)) {
        throw new TenantProvisioningException('Arquivo schema_legacy.sql nao encontrado.');
    }

    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new TenantProvisioningException('Nao foi possivel ler schema_legacy.sql.');
    }

    $pdo = tenant_conectar($dbName, $prefix);

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach (tenant_dividir_statements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}


/**
 * Divide um dump SQL em statements individuais, respeitando ponto-e-virgula
 * dentro de strings (necessario porque o dump nao usa DELIMITER customizado).
 */
function tenant_dividir_statements(string $sql): array
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

    // Remove comentarios de linha simples (-- ...) e linhas vazias, statement a statement.
    return array_map(static function (string $stmt): string {
        $linhas = array_filter(
            explode("\n", $stmt),
            static fn (string $linha) => !str_starts_with(trim($linha), '--')
        );
        return trim(implode("\n", $linhas));
    }, $statements);
}

function tenant_inserir_dados_iniciais(string $dbName, array $empresa, array $plano, string $adminNome, string $adminLogin, string $adminSenha, string $prefix = ''): void
{
    $pdo = tenant_conectar($dbName, $prefix);

    $modulos = is_array($plano['modulos'] ?? null) ? array_values($plano['modulos']) : [];

    $stmt = $pdo->prepare('INSERT INTO empresa (nome, telefone, email, endereco, cor_primaria, cor_secundaria, logo_path, modulos_habilitados, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        $empresa['nome'],
        $empresa['telefone'] ?? null,
        $empresa['email'] ?? null,
        $empresa['endereco'] ?? null,
        $empresa['cor_primaria'] ?? null,
        $empresa['cor_secundaria'] ?? null,
        $empresa['logo_path'] ? basename((string) $empresa['logo_path']) : null,
        json_encode($modulos),
    ]);

    $stmt = $pdo->prepare('INSERT INTO funcionarios (nome, login, senha, is_admin, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, 1, NOW(), NOW())');
    $stmt->execute([
        $adminNome,
        $adminLogin,
        password_hash($adminSenha, PASSWORD_DEFAULT),
        'admin',
    ]);
}

function tenant_remover_diretorio(string $caminho): void
{
    $itens = scandir($caminho);
    foreach ($itens as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $alvo = $caminho.DIRECTORY_SEPARATOR.$item;
        if (is_dir($alvo)) {
            tenant_remover_diretorio($alvo);
        } else {
            unlink($alvo);
        }
    }
    rmdir($caminho);
}

function tenant_copiar_diretorio(string $origem, string $destino, array $excluir): void
{
    if (!is_dir($destino)) {
        mkdir($destino, 0755, true);
    }

    foreach (scandir($origem) as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $excluir, true)) {
            continue;
        }
        if (str_starts_with($item, 'tmp_')) {
            continue;
        }

        $caminhoOrigem = $origem.DIRECTORY_SEPARATOR.$item;
        $caminhoDestino = $destino.DIRECTORY_SEPARATOR.$item;

        if (is_dir($caminhoOrigem)) {
            tenant_copiar_diretorio($caminhoOrigem, $caminhoDestino, $excluir);
        } else {
            copy($caminhoOrigem, $caminhoDestino);
        }
    }
}

function tenant_copiar_arquivos_da_aplicacao(string $slug): void
{
    $destino = rtrim(TENANTS_CLIENTS_BASE_PATH, '/\\').DIRECTORY_SEPARATOR.$slug;

    if (is_dir($destino)) {
        tenant_remover_diretorio($destino);
    }

    tenant_copiar_diretorio(TENANTS_LEGACY_SOURCE_PATH, $destino, TENANTS_EXCLUDE);
}

function tenant_copiar_logo(string $slug, ?string $logoPath): void
{
    if (!$logoPath) {
        return;
    }

    $origemLogo = __DIR__.'/storage/logos/'.basename($logoPath);
    if (!is_file($origemLogo)) {
        throw new TenantProvisioningException('Logo personalizado nao encontrado em '.$origemLogo);
    }

    $destino = rtrim(TENANTS_CLIENTS_BASE_PATH, '/\\').DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.basename($logoPath);

    if (!copy($origemLogo, $destino)) {
        throw new TenantProvisioningException('Nao foi possivel copiar o logo para a instancia do cliente.');
    }
}

function tenant_gerar_configuracao_runtime(string $slug, string $dbName, string $prefix = ''): void
{
    $destino = rtrim(TENANTS_CLIENTS_BASE_PATH, '/\\').DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'db_runtime_config.php';

    $conteudo = "<?php\n// Gerado automaticamente pelo painel administrativo em ".date('Y-m-d H:i:s').".\n\n"
        ."return [\n"
        ."    'host' => ".var_export(SAAS_DB_HOST, true).",\n"
        ."    'port' => ".var_export(SAAS_DB_PORT, true).",\n"
        ."    'dbname' => ".var_export($dbName, true).",\n"
        ."    'user' => ".var_export(TENANTS_DB_ROOT_USER, true).",\n"
        ."    'pass' => ".var_export(TENANTS_DB_ROOT_PASS, true).",\n"
        ."    'prefix' => ".var_export($prefix, true).",\n"
        ."];\n";

    file_put_contents($destino, $conteudo);
}

/**
 * Provisiona uma instancia completa e nova para $empresaId, usando os dados
 * ja salvos em comanda_saas.empresas + o plano vinculado.
 */
function tenant_provisionar(int $empresaId, string $adminNome, string $adminLogin, string $adminSenha): void
{
    $pdo = pdo_saas();

    $stmt = $pdo->prepare('SELECT e.*, p.modulos AS plano_modulos FROM empresas e LEFT JOIN planos p ON p.id = e.plano_id WHERE e.id = ?');
    $stmt->execute([$empresaId]);
    $empresa = $stmt->fetch();

    if (!$empresa) {
        throw new TenantProvisioningException('Empresa nao encontrada.');
    }

    $slug = $empresa['slug'] ?: tenant_gerar_slug_unico($empresa['nome']);
    $ehLocal = comanda_is_local_request();

    // Local (XAMPP): banco proprio por cliente. Producao: banco unico
    // compartilhado (TENANTS_SHARED_DB_NAME) com tabelas prefixadas por slug,
    // pois a hospedagem gratuita nao permite CREATE DATABASE via SQL.
    $dbName = $ehLocal ? ('comanda_'.str_replace('-', '_', $slug)) : TENANTS_SHARED_DB_NAME;
    $prefix = $ehLocal ? '' : tenant_gerar_prefixo_tabela($slug);

    try {
        if ($ehLocal) {
            // Local (XAMPP): o usuario root tem privilegio de CREATE/DROP DATABASE.
            tenant_criar_banco($dbName);
        }
        tenant_importar_estrutura($dbName, $prefix);
        tenant_inserir_dados_iniciais($dbName, $empresa, ['modulos' => json_decode((string) $empresa['plano_modulos'], true) ?: []], $adminNome, $adminLogin, $adminSenha, $prefix);
        tenant_copiar_arquivos_da_aplicacao($slug);
        tenant_copiar_logo($slug, $empresa['logo_path']);
        tenant_gerar_configuracao_runtime($slug, $dbName, $prefix);

        $upd = $pdo->prepare('UPDATE empresas SET slug=?, db_name=?, table_prefix=?, login_admin=?, provisionado_em=NOW(), provisionamento_erro=NULL WHERE id=?');
        $upd->execute([$slug, $dbName, $prefix, $adminLogin, $empresaId]);
    } catch (Throwable $e) {
        $upd = $pdo->prepare('UPDATE empresas SET slug=?, db_name=?, table_prefix=?, provisionamento_erro=? WHERE id=?');
        $upd->execute([$slug, $dbName, $prefix, $e->getMessage(), $empresaId]);
        throw $e;
    }
}

/**
 * Reenvia nome/cores/logo/modulos do plano atual para a instancia ja provisionada
 * (usado quando o superadmin edita esses dados depois do cadastro inicial).
 */
function tenant_sincronizar_personalizacao(int $empresaId): void
{
    $pdo = pdo_saas();
    $stmt = $pdo->prepare('SELECT e.*, p.modulos AS plano_modulos FROM empresas e LEFT JOIN planos p ON p.id = e.plano_id WHERE e.id = ?');
    $stmt->execute([$empresaId]);
    $empresa = $stmt->fetch();

    if (!$empresa || !$empresa['db_name'] || !$empresa['provisionado_em']) {
        return;
    }

    $modulos = json_decode((string) $empresa['plano_modulos'], true) ?: [];

    $tenantPdo = tenant_conectar((string) $empresa['db_name'], (string) ($empresa['table_prefix'] ?? ''));

    $upd = $tenantPdo->prepare('UPDATE empresa SET nome=?, telefone=?, email=?, endereco=?, cor_primaria=?, cor_secundaria=?, logo_path=?, modulos_habilitados=? LIMIT 1');
    $upd->execute([
        $empresa['nome'],
        $empresa['telefone'],
        $empresa['email'],
        $empresa['endereco'],
        $empresa['cor_primaria'],
        $empresa['cor_secundaria'],
        $empresa['logo_path'] ? basename((string) $empresa['logo_path']) : null,
        json_encode(array_values($modulos)),
    ]);

    tenant_copiar_logo($empresa['slug'], $empresa['logo_path']);
}

function tenant_resetar_senha(int $empresaId, string $novaSenha): bool
{
    $pdo = pdo_saas();
    $stmt = $pdo->prepare('SELECT db_name, table_prefix, login_admin FROM empresas WHERE id = ?');
    $stmt->execute([$empresaId]);
    $empresa = $stmt->fetch();

    if (!$empresa || !$empresa['db_name']) {
        return false;
    }

    $tenantPdo = tenant_conectar((string) $empresa['db_name'], (string) ($empresa['table_prefix'] ?? ''));

    $upd = $tenantPdo->prepare('UPDATE funcionarios SET senha = ? WHERE login = ?');
    $upd->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $empresa['login_admin']]);

    return $upd->rowCount() > 0;
}
