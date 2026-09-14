<?php
// Leitura apenas, via CLI, sem executar bootstrap/migracoes da aplicacao.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$central = new PDO('mysql:host=127.0.0.1;dbname=comanda_saas;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tenant = $central->query("SELECT db_name FROM empresas WHERE slug='teste2'")->fetchColumn();
$report = ['php_timezone'=>date_default_timezone_get(),'php_now'=>date('c'),'databases'=>[]];
foreach (array_unique(['comanda_saas','comanda_online',$tenant]) as $name) {
    if (!$name || !preg_match('/^[a-z0-9_]+$/i',$name)) continue;
    $db = new PDO('mysql:host=127.0.0.1;dbname='.$name.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $entry = $db->query('SELECT NOW() AS db_now, UTC_TIMESTAMP() AS db_utc, @@session.time_zone AS session_zone, @@global.time_zone AS global_zone, @@system_time_zone AS system_zone')->fetch();
    $stmt=$db->prepare("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND DATA_TYPE IN ('timestamp','datetime','date') ORDER BY TABLE_NAME,ORDINAL_POSITION");
    $stmt->execute([$name]); $entry['columns']=$stmt->fetchAll();
    $entry['named_timezone_available'] = $db->query("SELECT CONVERT_TZ('2026-09-11 13:01:56','America/Belem','UTC')")->fetchColumn();
    $entry['datetime_history'] = [];
    foreach ($entry['columns'] as $column) {
        if ($column['DATA_TYPE'] !== 'datetime') continue;
        $table = '`' . str_replace('`', '``', $column['TABLE_NAME']) . '`';
        $field = '`' . str_replace('`', '``', $column['COLUMN_NAME']) . '`';
        $entry['datetime_history'][$column['TABLE_NAME'] . '.' . $column['COLUMN_NAME']] =
            $db->query("SELECT COUNT($field) AS populated, MIN($field) AS earliest, MAX($field) AS latest FROM $table")->fetch();
    }
    if ($name === $tenant) {
        $entry['mesa4']=$db->query("SELECT id,created_at,updated_at FROM comandas WHERE id=65 AND numero_mesa='4'")->fetch();
        $entry['item32']=$db->query('SELECT id,created_at,enviado_producao_at,enviado_cozinha_em FROM comanda_itens WHERE id=32')->fetch();
    }
    $report['databases'][$name]=$entry;
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
