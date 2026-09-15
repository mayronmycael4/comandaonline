<?php

require_once __DIR__ . '/db_tenant_prefix.php';

function comanda_normalize_host_name(string $rawHost): string {
    $hostName = strtolower(trim($rawHost));

    if (strpos($hostName, ':') !== false && strpos($hostName, ']') === false) {
        $hostName = explode(':', $hostName, 2)[0];
    }

    if (str_starts_with($hostName, '[')) {
        $closing = strpos($hostName, ']');
        if ($closing !== false) {
            $hostName = substr($hostName, 1, $closing - 1);
        }
    }

    return $hostName;
}

function comanda_is_local_request(): bool {
    $rawHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $hostName = comanda_normalize_host_name((string)$rawHost);

    $isLoopbackHost = in_array($hostName, ['localhost', '127.0.0.1', '::1'], true);

    $isIpAddress = filter_var($hostName, FILTER_VALIDATE_IP) !== false;
    $isPrivateOrReservedIp = false;
    if ($isIpAddress) {
        $isPrivateOrReservedIp = filter_var(
            $hostName,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    $isLocalDomain = strpos($hostName, '.local') !== false
        || strpos($hostName, '.lan') !== false
        || strpos($hostName, '.home') !== false;

    // Em hospedagem compartilhada, SERVER_ADDR costuma ser privado (10.x/172.x),
    // portanto NAO deve ser usado para decidir ambiente local.
    return $isLoopbackHost || $isPrivateOrReservedIp || $isLocalDomain;
}

function comanda_get_db_candidates(): array {
    $isLocal = comanda_is_local_request();

    $candidates = [];

    $envHost = getenv('COMANDA_DB_HOST') ?: '';
    $envName = getenv('COMANDA_DB_NAME') ?: '';
    $envUser = getenv('COMANDA_DB_USER') ?: '';
    $envPass = getenv('COMANDA_DB_PASS');
    $envPortRaw = getenv('COMANDA_DB_PORT');
    $envPort = (is_string($envPortRaw) && ctype_digit($envPortRaw)) ? (int)$envPortRaw : null;

    if ($envHost !== '' && $envName !== '' && $envUser !== '') {
        $candidates[] = [
            'host' => $envHost,
            'port' => $envPort,
            'dbname' => $envName,
            'user' => $envUser,
            'pass' => $envPass !== false ? $envPass : '',
            'source' => 'env'
        ];
    }

    $runtimeConfigFile = __DIR__ . '/db_runtime_config.php';
    if (file_exists($runtimeConfigFile)) {
        $runtimeConfig = require $runtimeConfigFile;
        if (is_array($runtimeConfig) && isset($runtimeConfig['host'], $runtimeConfig['dbname'], $runtimeConfig['user'])) {
            $candidates[] = [
                'host' => (string)$runtimeConfig['host'],
                'port' => (isset($runtimeConfig['port']) && is_numeric($runtimeConfig['port'])) ? (int)$runtimeConfig['port'] : null,
                'dbname' => (string)$runtimeConfig['dbname'],
                'user' => (string)$runtimeConfig['user'],
                'pass' => isset($runtimeConfig['pass']) ? (string)$runtimeConfig['pass'] : '',
                'prefix' => isset($runtimeConfig['prefix']) ? (string)$runtimeConfig['prefix'] : '',
                'source' => 'runtime_file'
            ];
        }
    }

    if ($isLocal) {
        $candidates[] = [
            'host' => '127.0.0.1',
            'port' => null,
            'dbname' => 'comanda_online',
            'user' => 'root',
            'pass' => '',
            'source' => 'local_default'
        ];
        $candidates[] = [
            'host' => 'localhost',
            'port' => null,
            'dbname' => 'comanda_online',
            'user' => 'root',
            'pass' => '',
            'source' => 'local_fallback'
        ];
    }
    // Em producao (hospedagem), cada instancia de cliente PRECISA do seu proprio
    // db_runtime_config.php (gerado pelo provisionamento). Nao ha mais fallback
    // hardcoded para um banco de cliente real aqui: a copia "mestre" na raiz do
    // dominio (usada so como template/painel) nao deve conseguir conectar a
    // nenhum banco de cliente especifico por engano.

    $dedup = [];
    $final = [];
    foreach ($candidates as $candidate) {
        $key = implode('|', [
            $candidate['host'],
            (string)($candidate['port'] ?? ''),
            $candidate['dbname'],
            $candidate['user'],
            $candidate['pass']
        ]);
        if (isset($dedup[$key])) continue;
        $dedup[$key] = true;
        $final[] = $candidate;
    }

    return $final;
}

function comanda_connect_db(array $options = []): array {
    $withDb = $options['with_db'] ?? true;
    $timeout = $options['timeout'] ?? 6;
    $candidates = comanda_get_db_candidates();

    $errors = [];

    foreach ($candidates as $candidate) {
        try {
            $dsn = "mysql:host={$candidate['host']};charset=utf8mb4";
            $port = $candidate['port'] ?? null;
            $portPart = (is_int($port) && $port > 0) ? ";port={$port}" : '';
            if ($withDb) {
                $dsn = "mysql:host={$candidate['host']}{$portPart};dbname={$candidate['dbname']};charset=utf8mb4";
            } else {
                $dsn = "mysql:host={$candidate['host']}{$portPart};charset=utf8mb4";
            }

            $opcoesPdo = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => $timeout
            ];
            $prefixoTenant = (string)($candidate['prefix'] ?? '');

            if ($prefixoTenant !== '') {
                require_once __DIR__ . '/db_tenant_prefix.php';
                $pdo = new ComandaPrefixedPDO($dsn, $candidate['user'], $candidate['pass'], $opcoesPdo, $prefixoTenant);
            } else {
                $pdo = new PDO($dsn, $candidate['user'], $candidate['pass'], $opcoesPdo);
            }

            return [
                'pdo' => $pdo,
                'config' => $candidate
            ];
        } catch (Throwable $e) {
            $errors[] = [
                'source' => $candidate['source'] ?? 'unknown',
                'host' => $candidate['host'] ?? 'unknown',
                'port' => $candidate['port'] ?? null,
                'message' => $e->getMessage()
            ];
            error_log('[db_config_helper] Falha de conexao em ' . ($candidate['source'] ?? 'unknown') . ' (' . $candidate['host'] . '): ' . $e->getMessage());
        }
    }

    if (!empty($errors)) {
        $first = $errors[0];
        $baseMessage = $first['message'];

        if (!comanda_is_local_request()) {
            $baseMessage .= ' | Hospedagem: configure db_runtime_config.php com host SQL real do painel (ex.: sqlXXX.infinityfree.com), porta, db, usuario e senha.';
        }

        throw new RuntimeException($baseMessage);
    }

    throw new RuntimeException('Nenhuma configuracao de banco disponivel.');
}
