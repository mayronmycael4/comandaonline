# Migração Comanda Online — PHP/MySQL para Next.js/Supabase

## Estado e escopo
Migração em andamento. Este documento não certifica paridade nem aprovação para produção.
Fonte: checkout local de mayronmycael4/comandaonline, base 7da6d9a, incluindo alterações locais preexistentes. O PHP permanece como referência até a homologação. Não importar backups, credenciais ou logs para Git.

## Arquitetura definida
Next.js App Router, TypeScript, Server Actions, Tailwind e Supabase Auth/Postgres/Storage. Menu lateral responsivo. Dados operacionais possuem empresa_id obrigatório e chaves estrangeiras compostas para impedir vínculos entre empresas. Catálogos globais (planos) são exceções documentadas. Usuários vinculam auth.users à empresa e papel; privilégios nunca vêm de campos enviados pelo navegador. Administração global exige concessão explícita no banco.

## Regras identificadas no código
- Comandas: abertura, itens, cancelamento, versões, histórico; transferência de mesa e garçom; impedir ocupação indevida; junção; divisão por itens e por valor.
- Operações críticas exigem permissão e motivo: cancelar, remover item em produção, transferir, reabrir, estornar e ajustar estoque.
- Fechamento: valores monetários decimais, pagamentos múltiplos, impedir saldo pendente, troco apenas em dinheiro; desconto acima de 10% exige motivo/permissão específica. Cupom valida vigência, limite de uso e mínimo do pedido. Fidelidade não pode duplicar ao reabrir/fechar.
- Caixa: sessão aberta, abertura/fechamento, sangria/suprimento, divergência justificada e comprovante. Estorno auditado e não repetível.
- KDS: recebido, em preparo, pronto e entregue; cozinha/churrasqueira/bar; SLA e impressão sem duplicidade.
- Produtos: categorias, disponibilidade, imagens, variações, adicionais, combos, promoções e ficha técnica. Estoque com baixa por insumo, inventário, perdas e custo médio no recebimento.
- Clientes: CPF normalizado, consentimento LGPD, fidelidade, cupons e automações de aniversário/retenção.
- QR: token de mesa, catálogo público, pedido vinculado à comanda e idempotência.
- Relatórios: vendas, ticket médio, horários, produção/SLA, cancelamentos/descontos/estornos por usuário, lucro estimado e CSV.
- Administração SaaS: planos/módulos/limites, empresas, identidade visual, licença/carência/bloqueio, pagamentos, redefinição de senha, provisionamento, auditoria, acesso direto e compartilhamento.
- Operação: permissões por papel e usuário, PIN, notificações, perfil, ajuda/feedback, backup/restauração, monitoramento, PWA e apresentação mobile.

## Decisões e ambiguidades
- Não copiar o fallback legado que permite fechar sem pagamento explícito: registrar pagamento real e validar total no banco.
- Substituir sessões PHP e senhas locais por Supabase Auth; não exportar senhas nem armazenar senha recuperável para compartilhamento.
- Cadastro público cria empresa e administrador atomicamente via trigger do Auth. Acesso imediato depende de configurar confirmação de e-mail conforme README; jamais elevar role a partir de user_metadata.
- Isolamento físico/prefixos MySQL será substituído por empresa_id + RLS. IDs legados precisam de mapa por empresa na importação.
- Arquivos com sufixo (1) são variantes legadas a comparar; não assumir que representam rotas adicionais do produto.
- Não executar instalação/reset/DDL durante requisições: migrations versionadas substituem bootstrap dinâmico do config.php.
- Processos financeiros devem usar transações e bloqueios no Postgres; valores e identidade do ator são derivados no servidor.
- Não considerar migração finalizada enquanto ações legadas, políticas RLS, importação e desktop/mobile não tiverem evidência de teste.

## Telas e endpoints existentes
| Arquivo | Tipo |
|---|---|
| admin/assets/compartilhar.js | js |
| admin/config.php | php |
| admin/empresa_acessar.php | php |
| admin/empresa_acoes.php | php |
| admin/empresa_form.php | php |
| admin/empresa_salvar.php | php |
| admin/empresas.php | php |
| admin/index.php | php |
| admin/login.php | php |
| admin/logout.php | php |
| admin/partials/footer.php | php |
| admin/partials/header.php | php |
| admin/plano_form.php | php |
| admin/plano_salvar.php | php |
| admin/planos.php | php |
| admin/provisioning.php | php |
| ajuda-init.js | js |
| ajuda.html | html |
| ajuda.php | php |
| api (1).js | js |
| api_cardapio.php | php |
| api.js | js |
| auditoria-init.js | js |
| auditoria.html | html |
| auditoria.php | php |
| automacoes_marketing.php | php |
| backup_auto.php | php |
| backup.php | php |
| branding.js | js |
| caixa_operacoes.php | php |
| caixa-mobile.html | html |
| caixa-mobile.js | js |
| cli_wrapper.php | php |
| clientes (1).html | html |
| clientes (1).php | php |
| clientes-init (1).js | js |
| clientes-init.js | js |
| clientes.html | html |
| clientes.php | php |
| comanda (1).js | js |
| comanda_operacoes.php | php |
| comanda_reabrir (1).php | php |
| comanda_reabrir.php | php |
| comanda-module (1).js | js |
| comanda-module.js | js |
| comanda.html | html |
| comanda.js | js |
| comandas (1).php | php |
| comandas_fechar (1).php | php |
| comandas_fechar.php | php |
| comandas-init (1).js | js |
| comandas-init.js | js |
| comandas.html | html |
| comandas.php | php |
| compras-init.js | js |
| compras.html | html |
| config (1).php | php |
| config.php | php |
| cozinha (1).html | html |
| cozinha (1).php | php |
| cozinha-mobile (1).html | html |
| cozinha-mobile (1).js | js |
| cozinha-mobile.html | html |
| cozinha-mobile.js | js |
| cozinha-shared (1).js | js |
| cozinha-shared.js | js |
| cozinha.html | html |
| cozinha.js | js |
| cozinha.php | php |
| cupons.php | php |
| db_config_helper.php | php |
| db_tenant_prefix.php | php |
| download.html | html |
| download.js | js |
| empresa.php | php |
| estoque_movimentacao.php | php |
| estoque-init.js | js |
| estoque-mobile.html | html |
| estoque-mobile.js | js |
| estoque.html | html |
| estoque.php | php |
| funcionarios (1).html | html |
| funcionarios (1).php | php |
| funcionarios-init.js | js |
| funcionarios-mobile (1).html | html |
| funcionarios-mobile.html | html |
| funcionarios.html | html |
| funcionarios.php | php |
| health.php | php |
| html2canvas.min.js | js |
| index (1).html | html |
| index (1).js | js |
| index-mobile (1).js | js |
| index-mobile.html | html |
| index-mobile.js | js |
| index.html | html |
| index.js | js |
| install.php | php |
| kds_impressao.php | php |
| lgpd_consentimento.php | php |
| lista_compras (1).php | php |
| lista_compras.php | php |
| login (1).php | php |
| login_pin.php | php |
| login-init (1).js | js |
| login-init.js | js |
| login.html | html |
| login.php | php |
| manifest (1).php | php |
| manifest.php | php |
| menu-admin.js | js |
| menu-geral (1).js | js |
| menu-geral.js | js |
| menu-mobile.html | html |
| menu-mobile.js | js |
| menu-mobile.php | php |
| mesas-mapa.html | html |
| mesas-mapa.js | js |
| mobile-navbar.js | js |
| mobile-routing.js | js |
| monitoramento-init.js | js |
| monitoramento.html | html |
| monitoramento.php | php |
| notificacoes.php | php |
| nova-comanda-mobile (1).js | js |
| nova-comanda-mobile.html | html |
| nova-comanda-mobile.js | js |
| pagamento_operacoes.php | php |
| pedidos-mobile (1).js | js |
| pedidos-mobile.html | html |
| pedidos-mobile.js | js |
| perfil (1).html | html |
| perfil (1).js | js |
| perfil-mobile (1).html | html |
| perfil-mobile (1).js | js |
| perfil-mobile.html | html |
| perfil-mobile.js | js |
| perfil.html | html |
| perfil.js | js |
| permissoes.php | php |
| produto_fichas_tecnicas.php | php |
| produtos (1).html | html |
| produtos (1).js | js |
| produtos (1).php | php |
| produtos_regras.php | php |
| produtos-mobile.html | html |
| produtos.html | html |
| produtos.js | js |
| produtos.php | php |
| pwa-register.js | js |
| qr_menu_token.php | php |
| qr_menu.php | php |
| qr-mesas.html | html |
| qr-mesas.js | js |
| relatorios (1).html | html |
| relatorios (1).js | js |
| relatorios (1).php | php |
| relatorios-mobile (1).html | html |
| relatorios-mobile.html | html |
| relatorios.html | html |
| relatorios.js | js |
| relatorios.php | php |
| reset.php | php |
| service-worker (1).js | js |
| service-worker.js | js |
| sessao_status.php | php |
| setup_status (1).php | php |
| setup_status.php | php |
| setup-actions.js | js |
| setup-init.js | js |
| setup.html | html |
| sso_login.js | js |
| sso_login.php | php |
| storage (1).js | js |
| storage.js | js |
| toast.js | js |

## Tabelas dos dumps estruturais

### admin/schema_saas.sql
- **audit_logs**: id, empresa_id, user_id, acao, dados_anteriores, dados_novos, ip_address, created_at, updated_at
- **cache**: key, value, expiration
- **cache_locks**: key, owner, expiration
- **empresas**: id, slug, db_name, table_prefix, login_admin, provisionado_em, provisionamento_erro, nome, nome_responsavel, documento, telefone, whatsapp, email, endereco, cidade, estado, segmento, observacoes_internas, plano_id, nome_exibicao, logo_path, favicon_path, cor_primaria, cor_secundaria, imagem_login_path, mensagem_boas_vindas, moeda, status, bloqueado_em, bloqueado_motivo, created_at, updated_at, deleted_at
- **failed_jobs**: id, uuid, connection, queue, payload, exception, failed_at
- **job_batches**: id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at
- **jobs**: id, queue, payload, attempts, reserved_at, available_at, created_at
- **licenca_pagamentos**: id, licenca_id, empresa_id, registrado_por_user_id, valor_pago, competencia, data_pagamento, vencimento_anterior, vencimento_novo, comprovante_path, observacoes, created_at, updated_at
- **licencas**: id, empresa_id, plano_id, valor_mensalidade, data_inicio, data_vencimento, ultimo_pagamento_em, dias_carencia, dias_aviso_antecedencia, aviso_fechavel, aviso_recorrente, forma_pagamento, renovacao_automatica, observacoes, status, ultima_validacao_online_em, created_at, updated_at
- **migrations**: id, migration, batch
- **model_has_permissions**: permission_id, model_type, model_id, empresa_id
- **model_has_roles**: role_id, model_type, model_id, empresa_id
- **password_reset_tokens**: email, token, created_at
- **permissions**: id, name, guard_name, created_at, updated_at
- **planos**: id, nome, slug, valor_mensal, limite_usuarios, modulos, ativo, observacoes, created_at, updated_at
- **role_has_permissions**: permission_id, role_id
- **roles**: id, empresa_id, name, guard_name, created_at, updated_at
- **sessions**: id, user_id, ip_address, user_agent, payload, last_activity
- **users**: id, empresa_id, name, email, cargo, is_superadmin, ativo, telefone, email_verified_at, password, remember_token, created_at, updated_at, failed_login_attempts, blocked_until

### admin/schema_legacy.sql
- **action_log**: id, actor_id, actor_nome, actor_login, acao, entidade, entidade_id, detalhes, ip_address, user_agent, created_at
- **api_request_log**: id, rota, metodo, status_code, duracao_ms, ip_address, actor_id, created_at
- **caixa_movimentacoes**: id, caixa_sessao_id, tipo, valor, motivo, actor_id, created_at
- **caixa_sessoes**: id, operador_id, status, valor_inicial, valor_contado, divergencia, observacao_abertura, observacao_fechamento, aberto_em, fechado_em
- **cliente_consentimento**: id, cliente_id, tipo_consentimento, aceito, origem, observacao, created_at
- **cliente_historico**: id, cliente_id, comanda_id, valor_total, pontos_ganhos, created_at
- **clientes**: id, nome, cpf, contato, email, pontos_fidelidade, total_gasto, total_visitas, ultima_visita, created_at, updated_at, observacoes, data_nascimento
- **comanda_itens**: id, comanda_id, produto_id, nome_item, categoria, quantidade, valor_unitario, total, created_at, kitchen_status, kitchen_pronto_at, observacoes, kitchen_setor, enviado_producao_at
- **comanda_operacoes_historico**: id, operacao, payload, actor_id, actor_login, actor_nome, created_at
- **comanda_request_dedupe**: id, request_id, comanda_id, created_at
- **comanda_status_historico**: id, comanda_id, status_anterior, status_novo, observacao, actor_id, actor_nome, actor_login, created_at
- **comandas**: id, numero_mesa, funcionario_id, cliente_id, status, total, created_at, updated_at, fechamento_data, duracao, versao, forma_pagamento, observacoes
- **cupons**: id, codigo, tipo_desconto, valor_desconto, valor_minimo_pedido, validade_inicio, validade_fim, limite_uso, usos_atuais, ativo, regras, created_at, updated_at
- **db_bootstrap_log**: id, nivel, mensagem, contexto, created_at
- **empresa**: id, nome, cnpj, endereco, telefone, email, created_at, updated_at, logo_path, cor_primaria, cor_secundaria, modulos_habilitados
- **error_events**: id, rota, metodo, status_code, error_code, mensagem, detalhes, ip_address, created_at
- **estoque**: id, nome, categoria, quantidade, unidade, quantidade_minima, valor_unitario, created_at, updated_at, custo_medio
- **estoque_movimentacoes**: id, estoque_id, tipo, quantidade, custo_unitario, comanda_id, referencia_tipo, referencia_id, documento_origem, fornecedor_nome, motivo, metadados, actor_id, created_at
- **feedbacks**: id, funcionario_id, funcionario_nome, tipo, mensagem, lido, created_at
- **funcionarios**: id, nome, login, senha, is_admin, permissoes, is_active, created_at, updated_at, sessao_versao, sessao_revogada_em, role, nome_exibicao, ultimo_login, failed_login_attempts, blocked_until, pin_hash
- **kds_impressao_log**: id, comanda_id, setor, payload_hash, payload, status, actor_id, created_at, impresso_em
- **lista_compras**: id, estoque_id, nome_item, quantidade_necessaria, quantidade_minima, unidade, prioridade, status, created_at, updated_at, fornecedor_nome, nota_fiscal, custo_unitario_real, recebido_em, observacoes
- **marketing_automacoes_log**: id, tipo, cliente_id, payload, status, executado_em, created_at
- **notificacoes_fila**: id, funcionario_id, tipo, titulo, mensagem, payload, status, lida_em, created_at
- **pagamentos_comanda**: id, comanda_id, tipo, valor, status, transacao_id, metadata, created_at
- **permissoes_catalog**: id, chave, descricao, categoria, is_critica, is_active, created_at, updated_at
- **produto_adicionais**: id, produto_id, categoria, nome, preco, obrigatorio, limite_min, limite_max, is_active, created_at, updated_at
- **produto_combos**: id, nome, descricao, preco_combo, regras, is_active, created_at, updated_at
- **produto_combos_itens**: id, combo_id, produto_id, quantidade, obrigatorio
- **produto_fichas_tecnicas**: id, produto_id, estoque_id, quantidade, unidade, is_active, created_at, updated_at
- **produto_promocoes**: id, nome, tipo, valor, produto_id, categoria, dia_semana, hora_inicio, hora_fim, regras, is_active, created_at, updated_at
- **produto_variacoes**: id, produto_id, grupo, nome, sku, preco_delta, is_default, is_active, created_at, updated_at
- **produtos**: id, nome, categoria, preco, descricao, is_active, created_at, updated_at, setor, imagem_url, tags_json, is_disponivel
- **qr_menu_idempotencia**: id, mesa_numero, payload_hash, janela_slot, qr_pedido_id, created_at
- **qr_menu_pedido_itens**: id, qr_pedido_id, comanda_item_id, produto_id, produto_nome, quantidade, valor_unitario, variacao_nome, adicionais_json, observacao_item, created_at
- **qr_menu_pedidos**: id, comanda_id, mesa_numero, cliente_nome, observacao_cliente, payload_hash, created_at
- **role_permissoes**: id, role, permissao_chave, allowed, created_at, updated_at
- **schema_version**: id, version, description, applied_at
- **sessoes**: id, funcionario_id, token, ip_address, user_agent, created_at, expires_at
