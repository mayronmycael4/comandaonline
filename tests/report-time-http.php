<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../time_contract.php';
$db = new PDO('mysql:host=127.0.0.1;dbname=comanda_online;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$zone = comanda_company_timezone($db);
[$start, $end] = comanda_report_epoch_bounds('2026-05-01', '2026-09-14', $zone);
$stmt = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS total FROM comandas WHERE status='fechada' AND UNIX_TIMESTAMP(fechamento_data)>=? AND UNIX_TIMESTAMP(fechamento_data)<?");
$stmt->execute([$start,$end]);
$expected = $stmt->fetch(PDO::FETCH_ASSOC);
$base = 'http://localhost/Comanda-Online-main/';
$actual = json_decode(file_get_contents($base.'relatorios.php?tipo=periodo&inicio=2026-05-01&fim=2026-09-14'), true, 512, JSON_THROW_ON_ERROR);
if ((int)$actual['comandas'] !== (int)$expected['n'] || abs((float)$actual['total'] - (float)$expected['total']) > 0.001) throw new RuntimeException('HTTP report differs from database');

// Connection-local fixtures disappear on disconnect; no business records are written.
$db->exec("SET time_zone='+00:00'");
$db->exec('CREATE TEMPORARY TABLE qa_report_midnight (at TIMESTAMP(6) NOT NULL, amount DECIMAL(10,2) NOT NULL)');
$db->exec("INSERT INTO qa_report_midnight VALUES ('2026-09-11 02:59:59.999999',1),('2026-09-11 03:00:00',2),('2026-09-12 02:59:59.999999',4),('2026-09-12 03:00:00',8)");
[$start,$end] = comanda_report_epoch_bounds('2026-09-11','2026-09-11','America/Belem');
$stmt=$db->prepare('SELECT SUM(amount) FROM qa_report_midnight WHERE UNIX_TIMESTAMP(at)>=? AND UNIX_TIMESTAMP(at)<?');
$stmt->execute([$start,$end]);
if ((float)$stmt->fetchColumn() !== 6.0) throw new RuntimeException('Midnight boundary lost/duplicated a sale');

foreach (['auditoria.php?inicio=2026-02-30&fim=2026-03-01', 'relatorios.php?tipo=periodo&inicio=2026-09-12&fim=2026-09-11'] as $route) {
    file_get_contents($base.$route, false, stream_context_create(['http'=>['ignore_errors'=>true]]));
    if (!str_contains($http_response_header[0], '400')) throw new RuntimeException('Invalid dates not rejected: '.$route);
}
echo "PASS HTTP sales vs DB, SQL midnight microseconds, invalid report/audit ranges; no persistent fixtures\n";
