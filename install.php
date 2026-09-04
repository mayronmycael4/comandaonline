<?php
function getAllowedOrigin() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . getAllowedOrigin());

require_once __DIR__ . '/db_config_helper.php';

$dbConfig = null;
$isLocalRequest = comanda_is_local_request();

if (!$isLocalRequest) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Instalacao remota desativada por seguranca.'
    ]);
    exit;
}

try {
    $connHost = comanda_connect_db(['with_db' => false, 'timeout' => 6]);
    $pdo = $connHost['pdo'];
    $dbConfig = $connHost['config'];

    $pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbConfig['dbname']} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE {$dbConfig['dbname']}");

    $tabelasNecessarias = ['empresa', 'funcionarios', 'clientes', 'produtos', 'estoque', 'comandas', 'comanda_itens'];
    $tabelasAtuais = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tabelasFaltantesPre = array_diff($tabelasNecessarias, $tabelasAtuais);

    if (empty($tabelasFaltantesPre)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Sistema ja inicializado. Setup bloqueado por seguranca.'
        ]);
        exit;
    }
    
    // Lê e executa o arquivo SQL
    $sqlFile = __DIR__ . '/comanda_online.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Arquivo SQL não encontrado: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS .*?;/i', '', $sql);
    $sql = preg_replace('/USE\s+[^;]+;/i', '', $sql);
    
    // Remove comentários e divide em comandos
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Executa cada comando (ignora erros de tabela já existente)
    $commands = array_filter(array_map('trim', explode(';', $sql)));
    $tabelasCriadas = 0;
    $tabelasExistentes = 0;
    
    foreach ($commands as $command) {
        if (!empty($command)) {
            try {
                $pdo->exec($command);
                if (stripos($command, 'CREATE TABLE') !== false) {
                    $tabelasCriadas++;
                }
            } catch (PDOException $e) {
                // Ignora erro de tabela já existente
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    $tabelasExistentes++;
                } else {
                    // Outros erros, apenas loga
                    error_log('SQL Error: ' . $e->getMessage());
                }
            }
        }
    }
    
    // Verifica se as tabelas principais existem
    $stmt = $pdo->query("SHOW TABLES");
    $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $tabelasFaltantes = array_diff($tabelasNecessarias, $tabelas);
    
    if (empty($tabelasFaltantes)) {
        echo json_encode([
            'success' => true,
            'message' => 'Banco de dados já está configurado! (' . count($tabelas) . ' tabelas)',
            'tabelas' => $tabelas
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Banco configurado, mas faltam algumas tabelas: ' . implode(', ', $tabelasFaltantes),
            'tabelas' => $tabelas
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro PDO: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
