<?php
declare(strict_types=1);

/** Temporal boundaries shared by APIs and reports. SQL DATE values are not instants. */
function comanda_timezone(string $name = 'America/Belem'): DateTimeZone
{
    if (!in_array($name, DateTimeZone::listIdentifiers(), true)) {
        throw new InvalidArgumentException('Fuso horario invalido.');
    }
    return new DateTimeZone($name);
}

function comanda_company_timezone(PDO $pdo): string
{
    $name = $pdo->query('SELECT timezone FROM empresa ORDER BY id DESC LIMIT 1')->fetchColumn();
    return comanda_timezone($name ?: 'America/Belem')->getName();
}

/** Inclusive business dates become a half-open UTC interval, including DST days. */
function comanda_utc_report_bounds(string $start, string $end, string $timezone = 'America/Belem'): array
{
    $zone = comanda_timezone($timezone);
    $parse = static function (string $value) use ($zone): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Data invalida. Use AAAA-MM-DD.');
        }
        return $date;
    };
    $first = $parse($start);
    $last = $parse($end);
    if ($last < $first) {
        throw new InvalidArgumentException('Data final anterior a inicial.');
    }
    $utc = new DateTimeZone('UTC');
    return [
        $first->setTimezone($utc)->format('Y-m-d H:i:s'),
        $last->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/** Format a database epoch without depending on PHP's configured timezone. */
function comanda_epoch_iso($epoch): ?string
{
    if ($epoch === null || $epoch === '') return null;
    if (!is_numeric($epoch)) throw new InvalidArgumentException('Instante invalido.');
    return (new DateTimeImmutable('@' . (string)(int)$epoch))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function comanda_report_epoch_bounds(string $start, string $end, string $timezone): array
{
    return array_map(static fn(string $value): int =>
        (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp(),
        comanda_utc_report_bounds($start, $end, $timezone));
}

/** Convert only explicitly projected epoch fields; never guess dates in user text. */
function comanda_serialize_epochs($data) {
    if (!is_array($data)) return $data;
    foreach ($data as $key => $value) {
        if (is_string($key) && str_starts_with($key, '__epoch_')) {
            $data[substr($key, 8)] = comanda_epoch_iso($value);
            unset($data[$key]);
        } elseif (is_array($value)) {
            $data[$key] = comanda_serialize_epochs($value);
        }
    }
    return $data;
}

function comanda_utc_payload($data) {
    if (!is_array($data)) return $data;
    // Backup rows retain SQL values and carry their connection timezone separately.
    if (isset($data['tables'])) return $data;
    $fields = ['created_at','updated_at','aberto_em','fechado_em','ultima_visita','fechamento_data',
        'kitchen_pronto_at','enviado_cozinha_em','enviado_producao_at','applied_at','expires_at',
        'validade_inicio','validade_fim','sessao_revogada_em','ultimo_login','blocked_until',
        'impresso_em','recebido_em','executado_em','lida_em'];
    foreach ($data as $key => $value) {
        if (is_array($value)) $data[$key] = comanda_utc_payload($value);
        elseif (in_array($key, $fields, true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/D', $value)) {
            $data[$key] = str_replace(' ', 'T', $value) . 'Z';
        }
    }
    return $data;
}

function comanda_input_instant(?string $value, string $timezone): ?string {
    if ($value === null || trim($value) === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
        throw new InvalidArgumentException('Data/hora invalida.');
    }
    $instant = new DateTimeImmutable($value, comanda_timezone($timezone));
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('Data/hora invalida.');
    return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
