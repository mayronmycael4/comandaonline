<?php
// Explicit local maintenance command. A readable, non-empty SQL backup is required.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['database:', 'backup:']);
$database = $options['database'] ?? '';
$backup = $options['backup'] ?? '';
if (!in_array($database, ['comanda_online','comanda_teste2'], true) || !is_file($backup) || filesize($backup) < 1000) {
    throw new RuntimeException('Informe --database local e --backup SQL verificado.');
}
$db = new PDO('mysql:host=127.0.0.1;dbname='.$database.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$stmt = $db->prepare("SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_DEFAULT,DATETIME_PRECISION,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND DATA_TYPE='datetime'");
$stmt->execute([$database]);
$columns = $stmt->fetchAll();
$quote = static fn(string $name): string => '`'.str_replace('`','``',$name).'`';
// Validate every range before any DDL. TIMESTAMP storage is UTC; conversion uses the existing session zone.
foreach ($columns as $column) {
    $table=$quote($column['TABLE_NAME']); $field=$quote($column['COLUMN_NAME']);
    $invalid=$db->query("SELECT COUNT(*) FROM $table WHERE $field IS NOT NULL AND ($field<'1970-01-02' OR $field>'2038-01-18 00:00:00')")->fetchColumn();
    if ($invalid) throw new RuntimeException('Valor fora do intervalo TIMESTAMP: '.$column['TABLE_NAME'].'.'.$column['COLUMN_NAME']);
}
foreach ($columns as $column) {
    $table=$quote($column['TABLE_NAME']); $field=$quote($column['COLUMN_NAME']);
    $before=$db->query("SELECT UNIX_TIMESTAMP($field) AS epoch FROM $table ORDER BY epoch")->fetchAll(PDO::FETCH_COLUMN);
    $precision=(int)$column['DATETIME_PRECISION'];
    $type='TIMESTAMP'.($precision ? '('.$precision.')' : '');
    $definition=$type.($column['IS_NULLABLE']==='YES' ? ' NULL' : ' NOT NULL');
    $default=$column['COLUMN_DEFAULT'];
    if ($default===null || strtoupper((string)$default)==='NULL') {
        if ($column['IS_NULLABLE']==='YES') $definition.=' DEFAULT NULL';
    } elseif (preg_match('/^current_timestamp(?:\(\d*\))?$/i',(string)$default)) {
        $definition.=' DEFAULT '.$default;
    } else {
        throw new RuntimeException('Default exige revisao: '.$column['TABLE_NAME'].'.'.$column['COLUMN_NAME']);
    }
    if ($column['EXTRA'] && !preg_match('/^on update current_timestamp(?:\(\d*\))?$/i',$column['EXTRA'])) throw new RuntimeException('EXTRA nao suportado');
    if ($column['EXTRA']) $definition.=' '.$column['EXTRA'];
    $db->exec("ALTER TABLE $table MODIFY $field $definition");
    $after=$db->query("SELECT UNIX_TIMESTAMP($field) AS epoch FROM $table ORDER BY epoch")->fetchAll(PDO::FETCH_COLUMN);
    if ($before!==$after) throw new RuntimeException('Instantes alterados; restaurar backup antes de continuar.');
    echo $column['TABLE_NAME'].'.'.$column['COLUMN_NAME']." UTC storage verified\n";
}
$db->exec("INSERT IGNORE INTO schema_version(version,description) VALUES ('2026.09.14.utc_storage.v1','DATETIME converted to TIMESTAMP after external SQL backup; UTC connection contract')");
echo "PASS UTC storage migration; original instants preserved\n";
