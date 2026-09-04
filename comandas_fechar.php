<?php
require_once 'config.php';

// Endpoint específico para fechar comanda e registrar fidelidade

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['error' => 'Método não permitido'], 405);
}

$data = getJsonInput();
$comandaId = $data['comanda_id'] ?? 0;
$actor = extractAuditActor($data);
$pagamentos = is_array($data['pagamentos'] ?? null) ? $data['pagamentos'] : [];
$descontoValor = round((float)($data['desconto_valor'] ?? 0), 2);
$descontoMotivo = trim((string)($data['desconto_motivo'] ?? ''));
$cupomCodigo = strtoupper(trim((string)($data['cupom_codigo'] ?? '')));

if (!$comandaId) {
    jsonResponse(['error' => 'ID da comanda é obrigatório'], 400);
}

$canCloseByCashier = actorHasPermission($pdo, $actor, 'caixa');
$canCloseByComanda = actorHasPermission($pdo, $actor, 'comandas');
if (!$canCloseByCashier && !$canCloseByComanda) {
    denyAndAudit($pdo, $actor, 'comandas', 'comandas', $comandaId, [
        'acao' => 'fechar_comanda'
    ]);
}

$pdo->beginTransaction();

try {
    // Busca comanda
    $stmt = $pdo->prepare("
        SELECT c.*, cl.id as cliente_id, cl.pontos_fidelidade, cl.total_gasto, cl.total_visitas
        FROM comandas c
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        WHERE c.id = ?
    ");
    $stmt->execute([$comandaId]);
    $comanda = $stmt->fetch();
    
    if (!$comanda) {
        jsonResponse(['error' => 'Comanda não encontrada'], 404);
    }
    
    if ($comanda['status'] === 'fechada') {
        jsonResponse(['error' => 'Comanda já está fechada'], 400);
    }

    $statusAnterior = (string)($comanda['status'] ?? 'aberta');
    $totalComanda = round((float)($comanda['total'] ?? 0), 2);

    if ($descontoValor < 0 || $descontoValor > $totalComanda) {
        jsonResponse(['error' => 'Desconto invalido para o total da comanda'], 400);
    }

    if ($descontoValor > 0) {
        if (!actorHasPermission($pdo, $actor, 'PDV_DESCONTO_APLICAR')) {
            denyAndAudit($pdo, $actor, 'PDV_DESCONTO_APLICAR', 'comandas', $comandaId, [
                'acao' => 'aplicar_desconto',
                'desconto_valor' => $descontoValor
            ]);
        }

        $limiteSemEscalada = round($totalComanda * 0.10, 2);
        if ($descontoValor > $limiteSemEscalada) {
            if ($descontoMotivo === '') {
                jsonResponse(['error' => 'Motivo obrigatorio para desconto acima de 10%'], 400);
            }
            if (!actorHasPermission($pdo, $actor, 'PDV_DESCONTO_ACIMA_LIMITE')) {
                denyAndAudit($pdo, $actor, 'PDV_DESCONTO_ACIMA_LIMITE', 'comandas', $comandaId, [
                    'acao' => 'desconto_acima_limite',
                    'desconto_valor' => $descontoValor,
                    'limite_sem_escalada' => $limiteSemEscalada,
                    'motivo' => $descontoMotivo
                ]);
            }
        }
    }

    $descontoCupom = 0.0;
    $cupomAplicado = null;
    if ($cupomCodigo !== '') {
        $stmt = $pdo->prepare("SELECT * FROM cupons WHERE codigo = ? AND ativo = 1 LIMIT 1");
        $stmt->execute([$cupomCodigo]);
        $cupom = $stmt->fetch();
        if (!$cupom) {
            jsonResponse(['error' => 'Cupom invalido ou inativo'], 400);
        }

        $agora = date('Y-m-d H:i:s');
        if (!empty($cupom['validade_inicio']) && $agora < (string)$cupom['validade_inicio']) {
            jsonResponse(['error' => 'Cupom ainda nao vigente'], 400);
        }
        if (!empty($cupom['validade_fim']) && $agora > (string)$cupom['validade_fim']) {
            jsonResponse(['error' => 'Cupom expirado'], 400);
        }
        if ((int)($cupom['limite_uso'] ?? 0) > 0 && (int)($cupom['usos_atuais'] ?? 0) >= (int)$cupom['limite_uso']) {
            jsonResponse(['error' => 'Cupom atingiu o limite de usos'], 400);
        }
        if ($totalComanda < (float)($cupom['valor_minimo_pedido'] ?? 0)) {
            jsonResponse(['error' => 'Pedido nao atende valor minimo do cupom'], 400);
        }

        if (($cupom['tipo_desconto'] ?? 'percentual') === 'valor') {
            $descontoCupom = round((float)$cupom['valor_desconto'], 2);
        } else {
            $descontoCupom = round($totalComanda * ((float)$cupom['valor_desconto'] / 100), 2);
        }
        $descontoCupom = min($descontoCupom, $totalComanda);
        $cupomAplicado = $cupom;
    }

    $totalLiquido = max(0.0, round($totalComanda - $descontoValor - $descontoCupom, 2));

    $pagamentosNormalizados = [];
    $somaPagamentos = 0.0;
    foreach ($pagamentos as $pg) {
        if (!is_array($pg)) continue;
        $tipo = strtolower(trim((string)($pg['tipo'] ?? $pg['forma'] ?? '')));
        $valor = round((float)($pg['valor'] ?? 0), 2);
        $transacaoId = trim((string)($pg['transacao_id'] ?? ''));
        $metadata = is_array($pg['metadata'] ?? null) ? $pg['metadata'] : [];

        if ($tipo === '' || $valor <= 0) {
            continue;
        }

        $pagamentosNormalizados[] = [
            'tipo' => $tipo,
            'valor' => $valor,
            'transacao_id' => $transacaoId !== '' ? $transacaoId : null,
            'metadata' => $metadata
        ];
        $somaPagamentos += $valor;
    }

    if (count($pagamentosNormalizados) === 0) {
        $formaFallback = trim((string)($comanda['forma_pagamento'] ?? ''));
        if ($formaFallback === '') {
            $formaFallback = 'nao_informado';
        }
        $pagamentosNormalizados[] = [
            'tipo' => strtolower($formaFallback),
            'valor' => $totalLiquido,
            'transacao_id' => null,
            'metadata' => ['fallback' => true]
        ];
        $somaPagamentos = $totalLiquido;
    }

    $somaPagamentos = round($somaPagamentos, 2);
    if ($somaPagamentos + 0.009 < $totalLiquido) {
        jsonResponse([
            'error' => 'Saldo pendente. Nao e possivel fechar comanda com pagamento incompleto.',
            'saldo_pendente' => round($totalLiquido - $somaPagamentos, 2)
        ], 400);
    }

    $troco = 0.0;
    $totalNaoDinheiro = 0.0;
    $totalDinheiro = 0.0;
    foreach ($pagamentosNormalizados as $pg) {
        if (($pg['tipo'] ?? '') === 'dinheiro') $totalDinheiro += (float)$pg['valor'];
        else $totalNaoDinheiro += (float)$pg['valor'];
    }
    $devidoEmDinheiro = max(0.0, round($totalLiquido - $totalNaoDinheiro, 2));
    if ($totalDinheiro > $devidoEmDinheiro) {
        $troco = round($totalDinheiro - $devidoEmDinheiro, 2);
    }
    
    // Calcula duração
    $abertura = new DateTime($comanda['created_at']);
    $fechamento = new DateTime();
    $duracao = $abertura->diff($fechamento);
    $duracaoStr = $duracao->h . 'h ' . $duracao->i . 'min';
    
    // Fecha comanda
    $stmt = $pdo->prepare("
        UPDATE comandas 
        SET status = 'fechada', 
            fechamento_data = NOW(), 
            duracao = ? 
        WHERE id = ?
    ");
    $stmt->execute([$duracaoStr, $comandaId]);

    $stmt = $pdo->prepare('DELETE FROM pagamentos_comanda WHERE comanda_id = ?');
    $stmt->execute([$comandaId]);
    $stmtPagamento = $pdo->prepare('INSERT INTO pagamentos_comanda (comanda_id, tipo, valor, status, transacao_id, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($pagamentosNormalizados as $pg) {
        $stmtPagamento->execute([
            $comandaId,
            $pg['tipo'],
            $pg['valor'],
            'confirmado',
            $pg['transacao_id'],
            json_encode($pg['metadata'], JSON_UNESCAPED_UNICODE)
        ]);
    }

    if ($cupomAplicado) {
        $pdo->prepare('UPDATE cupons SET usos_atuais = usos_atuais + 1 WHERE id = ?')->execute([(int)$cupomAplicado['id']]);
    }

    auditLog($pdo, 'comanda_fechada', 'comandas', $comandaId, [
        'duracao' => $duracaoStr,
        'total' => $totalComanda,
        'total_liquido' => $totalLiquido,
        'desconto_manual' => $descontoValor,
        'desconto_motivo' => $descontoMotivo,
        'desconto_cupom' => $descontoCupom,
        'cupom_codigo' => $cupomAplicado['codigo'] ?? null,
        'pagamentos' => $pagamentosNormalizados,
        'troco' => $troco
    ], $actor);
    if ($descontoValor > 0 || $descontoCupom > 0) {
        auditLog($pdo, 'comanda_desconto_aplicado', 'comandas', $comandaId, [
            'total_original' => $totalComanda,
            'desconto_manual' => $descontoValor,
            'desconto_cupom' => $descontoCupom,
            'total_final' => $totalLiquido,
            'motivo' => $descontoMotivo,
            'cupom_codigo' => $cupomAplicado['codigo'] ?? null
        ], $actor);
    }
    registrarHistoricoStatusComanda($pdo, (int)$comandaId, $statusAnterior, 'fechada', $actor, 'fechamento_comanda');
    
    // Se tem cliente, atualiza fidelidade
    if ($comanda['cliente_id']) {
        $pontosBase = floor($totalLiquido / 10); // 1 ponto a cada R$10

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cliente_historico WHERE cliente_id = ? AND comanda_id = ? AND pontos_ganhos > 0");
        $stmt->execute([$comanda['cliente_id'], $comandaId]);
        $jaCreditada = (int)$stmt->fetchColumn() > 0;

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(pontos_ganhos), 0) FROM cliente_historico WHERE cliente_id = ? AND pontos_ganhos > 0 AND created_at >= CURDATE()");
        $stmt->execute([$comanda['cliente_id']]);
        $pontosHoje = (int)$stmt->fetchColumn();

        $limiteDiario = 100;
        $pontosGanhos = $jaCreditada ? 0 : max(0, min($pontosBase, $limiteDiario - $pontosHoje));
        
        // Atualiza cliente
        $novosPontos = $comanda['pontos_fidelidade'] + $pontosGanhos;
        $novoTotal = $comanda['total_gasto'] + $totalLiquido;
        $novasVisitas = $comanda['total_visitas'] + 1;
        
        $stmt = $pdo->prepare("
            UPDATE clientes 
            SET pontos_fidelidade = ?, 
                total_gasto = ?, 
                total_visitas = ?,
                ultima_visita = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$novosPontos, $novoTotal, $novasVisitas, $comanda['cliente_id']]);
        
        // Registra no histórico
        $stmt = $pdo->prepare("
            INSERT INTO cliente_historico (cliente_id, comanda_id, valor_total, pontos_ganhos)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$comanda['cliente_id'], $comandaId, $totalLiquido, $pontosGanhos]);

        auditLog($pdo, 'fidelidade_creditada', 'cliente_historico', $comandaId, [
            'cliente_id' => (int)$comanda['cliente_id'],
            'pontos_base' => (int)$pontosBase,
            'pontos_ganhos' => (int)$pontosGanhos,
            'pontos_ja_creditados_hoje' => (int)$pontosHoje,
            'valor_total' => (float)$totalLiquido
        ], $actor);
    }
    
    // Baixa automatica por ficha tecnica (fase estoque real).
    $stmt = $pdo->prepare("
        SELECT ci.*, p.categoria 
        FROM comanda_itens ci
        LEFT JOIN produtos p ON ci.produto_id = p.id
        WHERE ci.comanda_id = ?
    ");
    $stmt->execute([$comandaId]);
    $itens = $stmt->fetchAll();
    
    foreach ($itens as $item) {
        $produtoId = (int)($item['produto_id'] ?? 0);
        if ($produtoId <= 0) continue;

        $stmt = $pdo->prepare("SELECT pft.estoque_id, pft.quantidade AS consumo_base, e.quantidade, e.quantidade_minima, e.unidade, e.custo_medio, e.nome
                               FROM produto_fichas_tecnicas pft
                               JOIN estoque e ON e.id = pft.estoque_id
                               WHERE pft.produto_id = ? AND pft.is_active = 1");
        $stmt->execute([$produtoId]);
        $insumos = $stmt->fetchAll();

        foreach ($insumos as $insumo) {
            $consumo = round((float)$insumo['consumo_base'] * (float)$item['quantidade'], 4);
            if ($consumo <= 0) continue;

            $novaQtd = max(0, round((float)$insumo['quantidade'] - $consumo, 4));
            $pdo->prepare("UPDATE estoque SET quantidade = ? WHERE id = ?")->execute([$novaQtd, (int)$insumo['estoque_id']]);
            $pdo->prepare("INSERT INTO estoque_movimentacoes (estoque_id, tipo, quantidade, custo_unitario, comanda_id, referencia_tipo, referencia_id, motivo, actor_id)
                           VALUES (?, 'saida_venda', ?, ?, ?, 'comanda_item', ?, 'consumo_por_venda', ?)")
                ->execute([
                    (int)$insumo['estoque_id'],
                    $consumo,
                    round((float)$insumo['custo_medio'], 4),
                    (int)$comandaId,
                    (string)($item['id'] ?? ''),
                    $actor['actor_id'] ?? null
                ]);

            if ($novaQtd <= (float)$insumo['quantidade_minima']) {
                $stmt = $pdo->prepare("SELECT id FROM lista_compras WHERE estoque_id = ? AND status = 'pendente'");
                $stmt->execute([(int)$insumo['estoque_id']]);
                if (!$stmt->fetch()) {
                    $qtdNecessaria = max(0, ((float)$insumo['quantidade_minima'] * 2) - $novaQtd);
                    $stmt = $pdo->prepare(" 
                        INSERT INTO lista_compras (estoque_id, nome_item, quantidade_necessaria, quantidade_minima, unidade, prioridade)
                        VALUES (?, ?, ?, ?, ?, 'alta')
                    ");
                    $stmt->execute([
                        (int)$insumo['estoque_id'],
                        (string)$insumo['nome'],
                        $qtdNecessaria,
                        (float)$insumo['quantidade_minima'],
                        (string)$insumo['unidade']
                    ]);
                }
            }
        }
    }
    
    $pdo->commit();
    jsonResponse([
        'success' => true, 
        'duracao' => $duracaoStr,
        'pontos_ganhos' => $pontosGanhos ?? 0,
        'cliente_id' => $comanda['cliente_id'],
        'total_liquido' => $totalLiquido,
        'desconto_total' => round($descontoValor + $descontoCupom, 2),
        'pagamentos_confirmados' => $pagamentosNormalizados,
        'troco' => $troco
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['error' => $e->getMessage()], 500);
}
