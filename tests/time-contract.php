<?php
require_once __DIR__ . '/../includes/time_contract.php';

function same($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual));
}

date_default_timezone_set('Europe/Berlin');
same(comanda_utc_report_bounds('2026-09-11', '2026-09-11'),
    ['2026-09-11 03:00:00', '2026-09-12 03:00:00'], 'Belem midnight');
same(comanda_utc_report_bounds('2026-03-08', '2026-03-08', 'America/New_York'),
    ['2026-03-08 05:00:00', '2026-03-09 04:00:00'], '23-hour day');
same(comanda_utc_report_bounds('2026-11-01', '2026-11-01', 'America/New_York'),
    ['2026-11-01 04:00:00', '2026-11-02 05:00:00'], '25-hour day');
same(comanda_epoch_iso(0), '1970-01-01T00:00:00Z', 'UTC independent of PHP');
same(comanda_epoch_iso(null), null, 'No fabricated timestamp');

[$start, $end] = comanda_utc_report_bounds('2026-09-11', '2026-09-11');
$sales = ['2026-09-11 02:59:59', $start, '2026-09-12 02:59:59.999999', $end];
same(array_values(array_filter($sales, fn($at) => $at >= $start && $at < $end)),
    [$start, '2026-09-12 02:59:59.999999'], 'No missing/duplicated midnight sales');
foreach ([['2026-02-30', '2026-03-01'], ['2026-09-12', '2026-09-11']] as $dates) {
    try { comanda_utc_report_bounds(...$dates); }
    catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Accepted invalid range');
}
echo "PASS temporal boundaries, DST, midnight, invalid dates and UTC serialization\n";
same(comanda_input_instant('2026-09-11T13:01:56', 'America/Belem'), '2026-09-11 16:01:56', 'Local input to UTC');
same(comanda_input_instant('2026-09-11T16:01:56Z', 'America/Belem'), '2026-09-11 16:01:56', 'UTC input unchanged');
same(comanda_utc_payload(['created_at'=>'2026-09-11 16:01:56','nome'=>'2026-09-11 16:01:56']),
    ['created_at'=>'2026-09-11T16:01:56Z','nome'=>'2026-09-11 16:01:56'], 'Only temporal fields serialized');
$backup=['tables'=>['rows'=>[['created_at'=>'2026-09-11 16:01:56']]]];
same(comanda_utc_payload($backup), $backup, 'SQL backup values preserved');
echo "PASS input instants and typed API/backup serialization\n";
