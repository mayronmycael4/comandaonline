<?php
require_once 'config.php';

exigirModulo($pdo, 'backup');

function listDatabaseTables(PDO $pdo): array {
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);
    $tables = [];
    foreach ($rows as $row) {
        if (!empty($row[0])) {
            $tables[] = $row[0];
        }
    }
    sort($tables);
    return $tables;
}

function quoteIdentifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function verifyAdminCredentials(PDO $pdo, string $login, string $senha): bool {
    $stmt = $pdo->prepare("SELECT senha, is_admin FROM funcionarios WHERE LOWER(login) = LOWER(?) AND is_active = 1 LIMIT 1");
    $stmt->execute([$login]);
    $row = $stmt->fetch();

    if (!$row) return false;
    if ((int)($row['is_admin'] ?? 0) !== 1) return false;

    $hash = (string)($row['senha'] ?? '');
    if ($hash === '') return false;

    return password_verify($senha, $hash);
}

function exportFullBackup(PDO $pdo): array {
    $tables = listDatabaseTables($pdo);
    $backupTables = [];
    $totalRows = 0;

    foreach ($tables as $table) {
        $tableQuoted = quoteIdentifier($table);

        $createStmt = $pdo->query("SHOW CREATE TABLE {$tableQuoted}");
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? '';

        $rowsStmt = $pdo->query("SELECT * FROM {$tableQuoted}");
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $backupTables[$table] = [
            'create_sql' => $createSql,
            'row_count' => count($rows),
            'rows' => $rows
        ];

        $totalRows += count($rows);
    }

    return [
        'version' => 'full-db-backup-v1',
        'generated_at' => gmdate('c'),
        'database' => $GLOBALS['dbConfig']['dbname'] ?? null,
        'table_count' => count($tables),
        'row_count' => $totalRows,
        'tables' => $backupTables
    ];
}

function restoreFromBackup(PDO $pdo, array $backup): array {
    $tables = $backup['tables'] ?? null;
    if (!is_array($tables) || empty($tables)) {
        jsonResponse(['error' => 'Backup inválido: tabela(s) ausentes'], 400);
    }

    $restored = [];

    try {
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table => $payload) {
            $tableQuoted = quoteIdentifier((string)$table);
            $createSql = isset($payload['create_sql']) ? trim((string)$payload['create_sql']) : '';

            if ($createSql !== '') {
                $pdo->exec($createSql);
            }

            $pdo->exec("TRUNCATE TABLE {$tableQuoted}");

            $rows = $payload['rows'] ?? [];
            if (!is_array($rows) || count($rows) === 0) {
                $restored[$table] = 0;
                continue;
            }

            $first = $rows[0];
            if (!is_array($first) || empty($first)) {
                $restored[$table] = 0;
                continue;
            }

            $columns = array_keys($first);
            $colSql = implode(', ', array_map('quoteIdentifier', $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insertSql = "INSERT INTO {$tableQuoted} ({$colSql}) VALUES ({$placeholders})";
            $stmt = $pdo->prepare($insertSql);

            $inserted = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;

                $values = [];
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
                $stmt->execute($values);
                $inserted++;
            }

            $restored[$table] = $inserted;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->commit();

        return $restored;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $_ignore) {
        }
        throw $e;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse(exportFullBackup($pdo));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = getJsonInput();
        $action = strtolower((string)($data['action'] ?? ''));

        if ($action !== 'restore') {
            jsonResponse(['error' => 'Ação inválida'], 400);
        }

        $login = trim((string)($data['login'] ?? ''));
        $senha = (string)($data['senha'] ?? '');
        $backup = $data['backup'] ?? null;

        if ($login === '' || $senha === '') {
            jsonResponse(['error' => 'Login e senha de administrador são obrigatórios'], 400);
        }

        if (!verifyAdminCredentials($pdo, $login, $senha)) {
            jsonResponse(['error' => 'Credenciais inválidas ou sem permissão de administrador'], 403);
        }

        if (!is_array($backup)) {
            jsonResponse(['error' => 'Backup inválido'], 400);
        }

        $restored = restoreFromBackup($pdo, $backup);
        jsonResponse([
            'success' => true,
            'message' => 'Restauração concluída com sucesso',
            'tables' => $restored,
            'table_count' => count($restored)
        ]);
    }

    jsonResponse(['error' => 'Método não permitido'], 405);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Erro no backup: ' . $e->getMessage()], 500);
}
