<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['HTTP_HOST']='localhost';
require_once __DIR__.'/../admin/provisioning.php';
$prefix='qa_if_'.bin2hex(random_bytes(5)).'_';
$dbName='comanda_online';
$raw=tenant_conectar($dbName);
$tables=[];
try {
    tenant_importar_estrutura($dbName,$prefix);
    $stmt=$raw->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND LEFT(TABLE_NAME,CHAR_LENGTH(?))=?');
    $stmt->execute([$dbName,$prefix,$prefix]);
    $tables=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if(count($tables)<30) throw new RuntimeException('Schema incompleto');
    $tenant=tenant_conectar($dbName,$prefix);
    $tenant->exec("INSERT INTO empresa(nome) VALUES ('QA InfinityFree isolamento')");
    $blocked=false;
    try { tenant_importar_estrutura($dbName,$prefix); }
    catch (TenantProvisioningException $e) { $blocked=true; }
    if(!$blocked || $tenant->query('SELECT COUNT(*) FROM empresa')->fetchColumn()!=1) throw new RuntimeException('Reprovisionamento nao preservou dados');
    $stmt=$raw->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND LEFT(TABLE_NAME,CHAR_LENGTH(?))=? AND DATA_TYPE='datetime'");
    $stmt->execute([$dbName,$prefix,$prefix]);
    if($stmt->fetchColumn()!=0) throw new RuntimeException('Novo schema nao usa TIMESTAMP');
    echo "PASS shared database schema, prefix guard, data preservation and UTC column types\n";
} finally {
    // Only the random, test-owned prefix may be removed; never customer tables.
    $stmt=$raw->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND LEFT(TABLE_NAME,CHAR_LENGTH(?))=?');
    $stmt->execute([$dbName,$prefix,$prefix]);
    $raw->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if(!preg_match('/^qa_if_[a-f0-9]{10}_$/D',$prefix) || !str_starts_with($table,$prefix)) throw new RuntimeException('Cleanup fora do escopo');
        $raw->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
    }
    $raw->exec('SET FOREIGN_KEY_CHECKS=1');
}
