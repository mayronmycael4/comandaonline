<?php
// Suporte a "banco compartilhado com prefixo de tabela por cliente" — usado
// quando a hospedagem nao permite CREATE DATABASE por cliente (ex.: InfinityFree).
// Todas as instancias de cliente passam a viver nas MESMAS tabelas fisicas do
// banco (ex.: `if0_40322863_comanda_online`), cada uma com um prefixo unico
// (ex.: `pizzaria_do_bairro_funcionarios`). Local (XAMPP) continua usando um
// banco proprio por cliente (sem prefixo) e nao passa por este arquivo.

declare(strict_types=1);

const COMANDA_TENANT_TABELAS = [
    'action_log', 'api_request_log', 'caixa_movimentacoes', 'caixa_sessoes',
    'cliente_consentimento', 'cliente_historico', 'clientes', 'comanda_itens',
    'comanda_operacoes_historico', 'comanda_request_dedupe', 'comanda_status_historico',
    'comandas', 'cupons', 'db_bootstrap_log', 'empresa', 'error_events', 'estoque',
    'estoque_movimentacoes', 'feedbacks', 'funcionarios', 'kds_impressao_log',
    'lista_compras', 'marketing_automacoes_log', 'notificacoes_fila',
    'pagamentos_comanda', 'permissoes_catalog', 'produto_adicionais', 'produto_combos',
    'produto_combos_itens', 'produto_fichas_tecnicas', 'produto_promocoes',
    'produto_variacoes', 'produtos', 'qr_menu_idempotencia', 'qr_menu_pedido_itens',
    'qr_menu_pedidos', 'role_permissoes', 'schema_version', 'sessoes',
];

/**
 * Reescreve um SQL trocando nomes de tabela conhecidos (COMANDA_TENANT_TABELAS)
 * pelo nome prefixado, mas SOMENTE quando o identificador aparece logo apos uma
 * palavra-chave de referencia a tabela (FROM/JOIN/INTO/UPDATE/TABLE/REFERENCES).
 * Isso evita corromper valores de string ou nomes de coluna que coincidam com
 * o nome de alguma tabela (ex.: coluna "estoque" dentro de produtos).
 */
function comanda_sql_prefixar(string $sql, string $prefix): string
{
    if ($prefix === '') {
        return $sql;
    }

    $tabelas = implode('|', array_map(static fn (string $t) => preg_quote($t, '/'), COMANDA_TENANT_TABELAS));
    // Duas alternativas explicitas (com ou sem backtick) para nao depender de
    // um `\b` ambiguo apos um backtick opcional (backtick e nao-palavra, entao
    // a fronteira de palavra ali e inconsistente e deixava backtick duplicado).
    $padrao = '/\b(FROM|JOIN|INTO|UPDATE|TABLE|REFERENCES)\b(\s+IF(?:\s+NOT)?\s+EXISTS)?\s+(?:`('.$tabelas.')`|\b('.$tabelas.')\b)/i';

    $sql = preg_replace_callback($padrao, static function (array $m) use ($prefix): string {
        $tabela = (!empty($m[3])) ? $m[3] : ($m[4] ?? '');
        return $m[1].($m[2] ?? '').' `'.$prefix.$tabela.'`';
    }, $sql) ?? $sql;

    // Nomes de CONSTRAINT (FK) sao unicos por banco inteiro (nao por tabela),
    // entao tambem precisam de prefixo para nao colidir entre tenants.
    $sql = preg_replace_callback('/\bCONSTRAINT\s+`([a-zA-Z0-9_]+)`/i', static function (array $m) use ($prefix): string {
        return 'CONSTRAINT `'.$prefix.$m[1].'`';
    }, $sql) ?? $sql;

    return $sql;
}

/**
 * PDO que reescreve automaticamente toda instrucao SQL para usar tabelas
 * prefixadas por tenant, permitindo que dezenas de arquivos legados continuem
 * usando "SELECT * FROM funcionarios" sem nenhuma alteracao de codigo.
 */
class ComandaPrefixedPDO extends PDO
{
    private string $prefixoTenant;

    public function __construct(string $dsn, ?string $usuario, ?string $senha, array $opcoes, string $prefixoTenant)
    {
        parent::__construct($dsn, $usuario, $senha, $opcoes);
        $this->prefixoTenant = $prefixoTenant;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        return parent::prepare(comanda_sql_prefixar($query, $this->prefixoTenant), $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        $query = comanda_sql_prefixar($query, $this->prefixoTenant);
        if ($fetchMode === null) {
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement)
    {
        return parent::exec(comanda_sql_prefixar($statement, $this->prefixoTenant));
    }

    public function getPrefixoTenant(): string
    {
        return $this->prefixoTenant;
    }
}

/**
 * Le o prefixo de tabela do tenant atual (vazio quando o app roda num banco
 * proprio, sem prefixo). Usado por checagens de schema que consultam
 * information_schema/SHOW TABLES com nome de tabela literal (nao passam pelo
 * reescritor de SQL, que so atua no TEXTO do SQL, nao em valores de bind).
 */
function comanda_prefixo_tenant_atual(?PDO $pdo): string
{
    return $pdo instanceof ComandaPrefixedPDO ? $pdo->getPrefixoTenant() : '';
}

