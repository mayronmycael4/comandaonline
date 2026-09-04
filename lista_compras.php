<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$actor = extractAuditActor($data);

switch ($method) {
    case 'GET':
        if (isset($_GET['pendentes'])) {
            // Retorna apenas itens pendentes
            $stmt = $pdo->query("
                SELECT lc.*, e.quantidade as estoque_atual, e.custo_medio 
                FROM lista_compras lc
                LEFT JOIN estoque e ON lc.estoque_id = e.id
                WHERE lc.status = 'pendente'
                ORDER BY lc.prioridade DESC, lc.created_at ASC
            ");
            jsonResponse($stmt->fetchAll());
        } else {
            $stmt = $pdo->query("
                SELECT lc.*, e.quantidade as estoque_atual, e.custo_medio 
                FROM lista_compras lc
                LEFT JOIN estoque e ON lc.estoque_id = e.id
                ORDER BY lc.created_at DESC
            ");
            jsonResponse($stmt->fetchAll());
        }
        break;
        
    case 'POST':
        // Adiciona item manualmente à lista de compras
        $stmt = $pdo->prepare("
            INSERT INTO lista_compras (estoque_id, nome_item, quantidade_necessaria, quantidade_minima, unidade, prioridade)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['estoque_id'] ?? null,
            $data['nome_item'],
            $data['quantidade_necessaria'],
            $data['quantidade_minima'] ?? 0,
            $data['unidade'] ?? 'un',
            $data['prioridade'] ?? 'media'
        ]);
        
        jsonResponse(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;
        
    case 'PUT':
        $id = $data['id'] ?? 0;
        $status = $data['status'] ?? '';
        $quantidadeAdicionada = round((float)($data['quantidade_adicionada'] ?? 0), 4);
        $custoUnitario = round((float)($data['custo_unitario_real'] ?? $data['custo_unitario'] ?? 0), 4);
        $fornecedorNome = trim((string)($data['fornecedor_nome'] ?? ''));
        $notaFiscal = trim((string)($data['nota_fiscal'] ?? ''));
        $observacoes = trim((string)($data['observacoes'] ?? ''));
        $recebidoEm = trim((string)($data['recebido_em'] ?? ''));
        
        if (!$id || $status === '') {
            jsonResponse(['error' => 'id e status sao obrigatorios'], 400);
        }

        // Atualiza status e metadados do recebimento
        $stmt = $pdo->prepare("UPDATE lista_compras SET status = ?, fornecedor_nome = ?, nota_fiscal = ?, custo_unitario_real = ?, recebido_em = ?, observacoes = ? WHERE id = ?");
        $stmt->execute([
            $status,
            $fornecedorNome !== '' ? $fornecedorNome : null,
            $notaFiscal !== '' ? $notaFiscal : null,
            $custoUnitario > 0 ? $custoUnitario : null,
            $recebidoEm !== '' ? $recebidoEm : null,
            $observacoes !== '' ? $observacoes : null,
            $id
        ]);
        
        // Se foi comprado/recebido, adiciona ao estoque com custo medio e log de movimento
        if (in_array($status, ['comprado', 'recebido'], true) && !empty($data['estoque_id']) && $quantidadeAdicionada > 0) {
            $estoqueId = (int)$data['estoque_id'];
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT quantidade, custo_medio FROM estoque WHERE id = ? FOR UPDATE");
                $stmt->execute([$estoqueId]);
                $estoque = $stmt->fetch();
                if ($estoque) {
                    $qAtual = (float)$estoque['quantidade'];
                    $custoAtual = (float)$estoque['custo_medio'];
                    $novaQtd = $qAtual + $quantidadeAdicionada;
                    $novoCusto = $custoUnitario > 0 && $novaQtd > 0
                        ? (($qAtual * $custoAtual) + ($quantidadeAdicionada * $custoUnitario)) / $novaQtd
                        : $custoAtual;
            
                    $stmt = $pdo->prepare("UPDATE estoque SET quantidade = ?, custo_medio = ? WHERE id = ?");
                    $stmt->execute([round($novaQtd, 4), round($novoCusto, 4), $estoqueId]);

                    $stmt = $pdo->prepare("INSERT INTO estoque_movimentacoes (estoque_id, tipo, quantidade, custo_unitario, referencia_tipo, referencia_id, documento_origem, fornecedor_nome, motivo, metadados, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $estoqueId,
                        'entrada_compra',
                        $quantidadeAdicionada,
                        $custoUnitario,
                        'lista_compras',
                        (string)$id,
                        $notaFiscal !== '' ? $notaFiscal : null,
                        $fornecedorNome !== '' ? $fornecedorNome : null,
                        $observacoes !== '' ? $observacoes : null,
                        json_encode([
                            'lista_compra_id' => (int)$id,
                            'status' => $status,
                            'quantidade_adicionada' => $quantidadeAdicionada,
                            'custo_unitario_real' => $custoUnitario,
                            'fornecedor_nome' => $fornecedorNome,
                            'nota_fiscal' => $notaFiscal,
                        ], JSON_UNESCAPED_UNICODE),
                        $actor['actor_id'] ?? null
                    ]);

                    auditLog($pdo, 'lista_compra_recebida', 'lista_compras', (int)$id, [
                        'estoque_id' => $estoqueId,
                        'quantidade_adicionada' => $quantidadeAdicionada,
                        'custo_unitario_real' => $custoUnitario,
                        'fornecedor_nome' => $fornecedorNome,
                        'nota_fiscal' => $notaFiscal
                    ], $actor);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(['error' => $e->getMessage()], 500);
            }
        }
        
        jsonResponse(['success' => true]);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM lista_compras WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['success' => true]);
        break;
        
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
