# Backlog de Implementacao Completa

## Visao geral
Este backlog organiza a implementacao do escopo completo em fases incrementais, priorizando risco operacional e financeiro.

## Fase 1 - Fundacao de Seguranca e Governanca (implementada)
- [x] Schema version basico (`schema_version`) com versao inicial.
- [x] Endpoint de saude `GET /health.php`.
- [x] Hardening de login com bloqueio temporario por tentativas invalidas.
- [x] Campos de usuario para seguranca e governanca:
  - `role`
  - `nome_exibicao`
  - `ultimo_login`
  - `failed_login_attempts`
  - `blocked_until`
- [x] Matriz de permissoes padrao por role no backend.
- [x] Modulo de funcionarios com role/nome de exibicao e filtros por role/status.

## Fase 2 - Usuarios, Permissoes Granulares e Auditoria Forte
- [x] Tabela de permissoes por chave (catalogo central).
- [x] Vinculo role -> permissoes com override por usuario (backend basico).
- [x] API para gerenciar roles e permissoes por acao.
- [~] Motivo obrigatorio em acoes criticas (parcial):
  - [x] cancelar comanda
  - [x] estornar pagamento
  - [x] remover item
  - [x] desconto acima do limite
  - [x] reabrir comanda
- [x] Tela de auditoria pesquisavel por periodo/usuario/acao.

## Fase 3 - Ciclo de Vida Completo da Comanda
- [x] Transferencia de mesa com regra de ocupacao.
- [x] Transferencia de responsabilidade (garcom).
- [~] Divisao por itens e por valor (N partes).
  - [x] divisao por itens
  - [x] divisao por valor
- [x] Juncao de comandas com rastreio de origem.
- [x] Regras de itens enviados para producao (estorno controlado).

## Fase 4 - PDV Real e Caixa Diario
- [x] Split payment (multi-meios no mesmo fechamento).
- [x] Troco automatico em dinheiro.
- [x] Estorno com permissao, motivo e auditoria.
- [x] Sangria/suprimento com comprovante interno.
- [x] Abertura/fechamento de caixa com divergencia justificada.

## Fase 5 - KDS Completo por Setor
- [x] Workflow de status:
  - Recebido -> Em preparo -> Pronto -> Entregue
- [x] Setorizacao por produto (cozinha/churrasqueira/bar).
- [x] SLA por pedido e destaque de atraso por setor.
- [x] Impressao por setor com anti-duplicidade.

## Fase 6 - Produtos, Ficha Tecnica e Estoque Real
- [x] Ficha tecnica por produto (insumos e quantidades).
- [x] Baixa automatica no estoque ao vender.
- [x] Inventario com ajuste auditado.
- [x] Compras/recebimento/custo medio.
- [x] Perdas e ajustes com motivo obrigatorio.

## Fase 7 - Relatorios Gerenciais
- [x] Ticket medio por dia/turno/mesa.
- [x] Picos por hora (heatmap).
- [x] Producao KDS (tempo medio, atrasos, gargalos).
- [x] Cancelamentos/descontos/estornos por usuario.
- [x] Lucro estimado (vendas - CMV - taxas).

## Fase 8 - Marketing e Fidelizacao
- [x] Consentimento LGPD em base de clientes.
- [x] Cupons e campanhas com regras.
- [x] Fidelidade com regras anti-fraude.
- [x] Automacoes de relacionamento (aniversario/retencao).

## Criterios de pronto por fase
- Todas as regras validadas na API (nao apenas front).
- Auditoria em toda acao critica da fase.
- Teste funcional desktop e mobile.
- Zero regressao nas rotas existentes.

## Status operacional (2026-05-27)
- Pendencias de execucao concluidas para Fase 5 (KDS), Fase 6 (produtos avancados), Fase 7 (relatorios/exportacao) e Fase 8 (marketing/fidelizacao).
- Observabilidade padronizada com tratamento global de excecoes/erros no backend e logs consistentes.
- Validacao E2E final registrada em `tmp_pendencias_e2e_report.json` com resumo: PASS 9, FAIL 0.
