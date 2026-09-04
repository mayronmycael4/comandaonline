<?php
require_once 'config.php';

exigirModulo($pdo, 'backup');

const AUTO_BACKUP_MAX_FILES = 12;

function autoBackupDir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'backups';
}

function autoBackupStateFile(): string {
    return autoBackupDir() . DIRECTORY_SEPARATOR . 'backup_auto_state.json';
}

function autoBackupLogFile(): string {
    return autoBackupDir() . DIRECTORY_SEPARATOR . 'backup_auto.log';
}

function ensureAutoBackupDir(): void {
    $dir = autoBackupDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function appendAutoBackupLog(string $level, string $message): void {
    ensureAutoBackupDir();
    $line = sprintf("[%s] [%s] %s\n", gmdate('c'), strtoupper($level), $message);
    @file_put_contents(autoBackupLogFile(), $line, FILE_APPEND);
}

function listDbTables(PDO $pdo): array {
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

function quoteIdent(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function exportSnapshot(PDO $pdo): array {
    $tables = listDbTables($pdo);
    $result = [];
    $totalRows = 0;

    foreach ($tables as $table) {
        $quoted = quoteIdent($table);
        $createStmt = $pdo->query("SHOW CREATE TABLE {$quoted}");
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? '';

        $rowsStmt = $pdo->query("SELECT * FROM {$quoted}");
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $result[$table] = [
            'create_sql' => $createSql,
            'row_count' => count($rows),
            'rows' => $rows,
        ];

        $totalRows += count($rows);
    }

    return [
        'version' => 'auto-weekly-backup-v1',
        'generated_at' => gmdate('c'),
        'database' => $GLOBALS['dbConfig']['dbname'] ?? null,
        'table_count' => count($tables),
        'row_count' => $totalRows,
        'tables' => $result,
    ];
}

function currentIsoWeek(): string {
    return gmdate('o-\\WW');
}

function loadBackupState(): array {
    $file = autoBackupStateFile();
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveBackupState(array $state): void {
    ensureAutoBackupDir();
    @file_put_contents(autoBackupStateFile(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function cleanupOldBackups(int $maxFiles = AUTO_BACKUP_MAX_FILES): void {
    $files = glob(autoBackupDir() . DIRECTORY_SEPARATOR . 'backup_auto_*.json') ?: [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $toDelete = array_slice($files, $maxFiles);
    foreach ($toDelete as $file) {
        @unlink($file);
    }
}

function generateAutoBackup(PDO $pdo, bool $force = false): array {
    ensureAutoBackupDir();

    $state = loadBackupState();
    $week = currentIsoWeek();
    $lastWeek = (string)($state['last_week'] ?? '');

    if (!$force && $lastWeek === $week) {
        return [
            'success' => true,
            'skipped' => true,
            'reason' => 'already_generated_this_week',
            'week' => $week,
            'last_file' => $state['last_file'] ?? null,
        ];
    }

    $snapshot = exportSnapshot($pdo);
    $filename = sprintf('backup_auto_%s_%s.json', $week, gmdate('Ymd_His'));
    $fullPath = autoBackupDir() . DIRECTORY_SEPARATOR . $filename;

    $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) {
        throw new RuntimeException('Falha ao serializar backup automático');
    }

    if (@file_put_contents($fullPath, $payload) === false) {
        throw new RuntimeException('Falha ao gravar arquivo de backup automático');
    }

    cleanupOldBackups(AUTO_BACKUP_MAX_FILES);

    $state['last_week'] = $week;
    $state['last_file'] = $filename;
    $state['last_generated_at'] = gmdate('c');
    saveBackupState($state);

    appendAutoBackupLog('info', "Backup semanal gerado: {$filename}");
    auditLog($pdo, 'backup_auto_gerado', 'backup_auto', $filename, [
        'week' => $week,
        'table_count' => $snapshot['table_count'] ?? 0,
        'row_count' => $snapshot['row_count'] ?? 0,
    ], []);

    return [
        'success' => true,
        'skipped' => false,
        'week' => $week,
        'file' => $filename,
        'table_count' => $snapshot['table_count'] ?? 0,
        'row_count' => $snapshot['row_count'] ?? 0,
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $force = isset($_GET['force']) && $_GET['force'] === '1';

    if ($method === 'GET') {
        $result = generateAutoBackup($pdo, $force);
        jsonResponse($result);
    }

    if ($method === 'POST') {
        $input = getJsonInput();
        $forcePost = !empty($input['force']);
        $result = generateAutoBackup($pdo, $forcePost);
        jsonResponse($result);
    }

    jsonResponse(['error' => 'Método não permitido'], 405);
} catch (Throwable $e) {
    appendAutoBackupLog('error', $e->getMessage());
    auditLog($pdo, 'backup_auto_falha', 'backup_auto', null, [
        'erro' => $e->getMessage(),
    ], []);
    jsonResponse(['error' => 'Falha no backup automático: ' . $e->getMessage()], 500);
}
