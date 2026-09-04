<?php
require_once 'config.php';

exigirModulo($pdo, 'clientes');

$method = $_SERVER['REQUEST_METHOD'];

function ensureClientesMarketingColumns(PDO $pdo): void {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM clientes')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (!in_array('data_nascimento', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN data_nascimento DATE NULL');
        } catch (Throwable $e) {
            error_log('[clientes.php] Falha ao adicionar clientes.data_nascimento: ' . $e->getMessage());
        }
    }

    if (!in_array('observacoes', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN observacoes TEXT NULL');
        } catch (Throwable $e) {
            error_log('[clientes.php] Falha ao adicionar clientes.observacoes: ' . $e->getMessage());
        }
    }
}

function normalizeCpf(?string $cpf): ?string {
    if ($cpf === null) return null;
    $clean = preg_replace('/[^0-9]/', '', $cpf);
    return $clean !== '' ? $clean : null;
}

ensureClientesMarketingColumns($pdo);

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT c.*, (
                    SELECT cc.aceito
                    FROM cliente_consentimento cc
                    WHERE cc.cliente_id = c.id AND cc.tipo_consentimento = 'marketing'
                    ORDER BY cc.created_at DESC
                    LIMIT 1
                ) AS consentimento_marketing
                FROM clientes c
                WHERE c.id = ?");
            $stmt->execute([$_GET['id']]);
            $cliente = $stmt->fetch();
            
            if ($cliente) {
                // Busca histórico
                $stmt = $pdo->prepare("
                    SELECT ch.*, c.numero_mesa, c.created_at as data_comanda 
                    FROM cliente_historico ch 
                    JOIN comandas c ON ch.comanda_id = c.id 
                    WHERE ch.cliente_id = ? 
                    ORDER BY ch.created_at DESC
                ");
                $stmt->execute([$_GET['id']]);
                $cliente['historico'] = $stmt->fetchAll();
            }
            
            jsonResponse($cliente);
        } elseif (isset($_GET['cpf'])) {
            $cpf = preg_replace('/[^0-9]/', '', $_GET['cpf']);
            $stmt = $pdo->prepare("SELECT c.*, (
                    SELECT cc.aceito
                    FROM cliente_consentimento cc
                    WHERE cc.cliente_id = c.id AND cc.tipo_consentimento = 'marketing'
                    ORDER BY cc.created_at DESC
                    LIMIT 1
                ) AS consentimento_marketing
                FROM clientes c
                WHERE REPLACE(REPLACE(REPLACE(c.cpf, '.', ''), '-', ''), ' ', '') = ?");
            $stmt->execute([$cpf]);
            jsonResponse($stmt->fetch());
        } else {
            $stmt = $pdo->query("SELECT c.*, (
                    SELECT cc.aceito
                    FROM cliente_consentimento cc
                    WHERE cc.cliente_id = c.id AND cc.tipo_consentimento = 'marketing'
                    ORDER BY cc.created_at DESC
                    LIMIT 1
                ) AS consentimento_marketing
                FROM clientes c
                ORDER BY c.nome");
            jsonResponse($stmt->fetchAll());
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        
        // Limpa CPF
        $cpf = normalizeCpf($data['cpf'] ?? null);
        
        // Verifica se CPF já existe (se informado)
        if ($cpf) {
            $stmt = $pdo->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?");
            $stmt->execute([$cpf]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'CPF já cadastrado'], 400);
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO clientes (nome, cpf, contato, email, data_nascimento, observacoes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['nome'] ?? '',
            $cpf,
            $data['contato'] ?? null,
            $data['email'] ?? null,
            !empty($data['data_nascimento']) ? $data['data_nascimento'] : null,
            !empty($data['observacoes']) ? $data['observacoes'] : null
        ]);
        
        jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;
        
    case 'PUT':
        $data = getJsonInput();
        $id = $data['id'] ?? 0;
        $cpf = normalizeCpf($data['cpf'] ?? null);

        if ($id <= 0) {
            jsonResponse(['error' => 'id obrigatorio'], 400);
        }

        if ($cpf) {
            $stmt = $pdo->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ? AND id <> ?");
            $stmt->execute([$cpf, $id]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'CPF já cadastrado para outro cliente'], 400);
            }
        }
        
        $stmt = $pdo->prepare("UPDATE clientes SET nome = ?, cpf = ?, contato = ?, email = ?, data_nascimento = ?, observacoes = ? WHERE id = ?");
        $stmt->execute([
            $data['nome'] ?? '',
            $cpf,
            $data['contato'] ?? null,
            $data['email'] ?? null,
            !empty($data['data_nascimento']) ? $data['data_nascimento'] : null,
            !empty($data['observacoes']) ? $data['observacoes'] : null,
            $id
        ]);
        
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
