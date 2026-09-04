<?php
require_once __DIR__ . '/config.php';

$requiredTables = ['empresa', 'funcionarios', 'clientes', 'produtos', 'estoque', 'comandas', 'comanda_itens'];

try {
    // SHOW TABLES bare nao reflete tabelas com prefixo (banco compartilhado
    // multi-tenant), entao usamos hasTable() (ja ciente do prefixo do tenant atual).
    $missing = array_values(array_filter($requiredTables, static fn (string $t) => !hasTable($pdo, $t)));
    $tablesReady = empty($missing);

    if (!$tablesReady) {
        jsonResponse([
            'success' => true,
            'allow_setup' => true,
            'reason' => 'schema_incomplete',
            'db_exists' => true,
            'tables_ready' => false,
            'missing_tables' => $missing,
            'company_exists' => false,
            'admin_exists' => false
        ]);
    }

    $companyExists = false;
    $adminExists = false;

    try {
        $companyExists = (int) $pdo->query('SELECT COUNT(*) FROM empresa')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $companyExists = false;
    }

    try {
        $adminExists = (int) $pdo->query('SELECT COUNT(*) FROM funcionarios WHERE is_admin = 1 AND is_active = 1')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $adminExists = false;
    }

    $allowSetup = !$companyExists || !$adminExists;
    $reason = $allowSetup ? 'bootstrap_ready_but_missing_initial_data' : 'initialized';

    jsonResponse([
        'success' => true,
        'allow_setup' => $allowSetup,
        'reason' => $reason,
        'db_exists' => true,
        'tables_ready' => true,
        'missing_tables' => [],
        'company_exists' => $companyExists,
        'admin_exists' => $adminExists
    ]);

} catch (Throwable $e) {
    // Falha em status nunca deve abrir setup por seguranca.
    jsonResponse([
        'success' => false,
        'allow_setup' => false,
        'reason' => 'status_error',
        'error' => $e->getMessage()
    ], 500);
}
