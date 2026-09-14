# Sprint unico — ponto de continuacao

## Estado em 14/09/2026 — checkpoint atual

Blocos 1 e 2 implementados no escopo documentado em NOTES.md. Proximo bloco: **3 — Flash branco/legado**. Nao refazer login/sidebar/layouts. Blocos 3–9 pendentes. **NAO PRONTO PARA PRODUCAO.** O historico abaixo conserva o diagnostico anterior; o checkpoint atual e NOTES.md prevalecem sobre os itens antigos marcados pendentes do bloco2.

- UTC aplicado em origem e teste2; TIMESTAMP, conexao UTC, fuso empresarial America/Belem, APIs ISO Z, Dashboard/Auditoria/relatorios ajustados. Backups SQL protegidos fora de htdocs; caminho em NOTES.md e tmp_time_backup_path.txt (ignorado).
- Testes time-contract/report-time-http/company-time/kitchen-timezone/kitchen-access-error e KDS identidade passaram. Fixture de identidade comanda70 na origem identificada QA, itens cancelados/produtos desativados.
- Browser atual: id1, tab6 Auditoria teste2; filtros11/09/2026 e busca65 mostram criacao13:01:56/envio13:02:18. Mesa4 foi cancelada pelo usuario em14/09 08:23:36, nao pelo agente. Nao alterar registros reais.
- Ainda nao houve publicacao. QA financeiro completo, backup/rollback, auth/isolamento e offline dependem dos blocos restantes.

Pedido completo: `C:/Users/malvezdossan/.codex/attachments/1dc40593-0324-43ea-bd1b-b10cee45bc5d/pasted-text.txt`.

## Causa raiz comprovada da Mesa 4

- Instancia `localhost/clientes/teste2`: comanda 65, item 32, produto 27, funcionario 8. Criacao 2026-09-11 13:01:56; item 13:02:18; quantidade 1; valor 10; recebido; setor cozinha.
- A gravacao existia. GET cozinha.php respondia 403: modulo cozinha ausente no plano. O frontend so escrevia o erro no console e mantinha o HTML inicial “0 aguardando / Nenhum pedido pendente”.
- Usuario autorizou explicitamente habilitar Cozinha **somente para teste2**. Foi criado plano exclusivo `teste2-cozinha-exclusivo`, mesmos valores/limites anteriores, com cozinha; empresa e licenca vinculadas a ele. Modulos sincronizados no banco da instancia. Outros planos/clientes preservados.
- Depois: API retorna Mesa 4/item 32; navegador mostra “1 item pendente em 1 mesa”, teste2, mayron mycael e x-cachorrao. Console sem erros na verificacao. Nao marcar esse pedido real pronto nem cancela-lo em QA.

## Correcoes do bloco 1

- cozinha-shared.js exibe erro HTTP real e estado de consulta indisponivel; nao simula fila vazia. Adicionais exibidos nos dois agrupamentos.
- comandas.php preserva ID e created_at dos itens ao salvar, mantem status da cozinha, cancela o mesmo registro e mapeia IDs antigos/temporarios para IDs definitivos. Mantem transacao e lock; versao antiga recebe 409 em vez de duplicar. Alteracao de quantidade de linha enviada exige cancelamento com motivo e nova linha.
- comanda.js bloqueia envios concorrentes, usa UUID para novas linhas, mostra erro e impede fechamento apos falha no salvamento. storage.js recebe IDs definitivos e preserva adicionais.
- Roteamento no servidor pelo produto: requer_preparo e setor_producao. Entrega imediata nao entra no KDS. Envio possui enviado_cozinha_em e enviado_producao_at. Pendentes de comandas fechadas continuam consultaveis ate conclusao/cancelamento.
- Arquitetura PHP/MariaDB existente: salvar a comanda confirma e envia suas novas linhas de producao na mesma transacao. Nao existe tabela separada de pedidos neste fluxo; comanda_itens e a unidade de producao.
- Produtos aceita setor_producao/requer_preparo pela API; dropdown desktop inclui entrega imediata. Itens manuais sem produto continuam exigindo producao por padrao.

## Migracoes

- Registro `2026.09.11.production_routing.v2` em applySchemaMigrations(config.php): produtos.requer_preparo; produtos.setor_producao; comanda_itens.enviado_cozinha_em (TIMESTAMP), adicionais (TEXT JSON). Backfill do envio a partir de enviado_producao_at/created_at para registros que ja apareciam na producao.
- Executada na origem local e em teste2. A origem tambem tem o registro intermediario production_routing da primeira rodada de QA; v2 e idempotente e final.
- Nao alterar timestamps historicos manualmente. UTC/timezone por empresa pertence ao bloco 2; o backfill acima usa a base temporal existente, ainda sem declarar normalizacao UTC concluida.

## Testes executados

- node tests/kitchen-access-error.cjs: PASS (403 nao vira fila vazia).
- tests/kitchen-item-identity.ps1: PASS via HTTP real para criacao deduplicada, rejeicao de versao repetida, IDs e timestamps estaveis, status em_preparo preservado, observacoes/adicionais, envio com timestamp, Bar, entrega imediata fora do KDS e cancelamento com mesmo ID.
- PHP lint de config.php/comandas.php/cozinha.php/produtos.php e Node syntax checks dos JS alterados: PASS nas verificacoes feitas.
- Bancos distintos: comanda 65 da origem era uma fixture QA, enquanto comanda 65 de teste2 permaneceu Mesa 4. Isto comprova isolamento do armazenamento consultado, **nao certifica autorizacao de acesso entre tenants**. Revisar autenticacao/autorizacao efetiva no bloco 9; APIs ainda usam metadados _audit do cliente.
- Fixtures da origem: comandas 64–69, prefixo QA-KDS-UUID, removidas com seus itens apos verificacao estrita dos IDs/prefixos. Auditoria preservada. Produtos de QA criados nos testes completos foram desativados. Nao foram alteradas vendas/pagamentos reais.
- Teste inicial de identidade falhou por payload QA sem _audit; corrigido para o contrato da API e repetido com sucesso.

## Implantacao local

Aplicados em teste2: config.php, comandas.php, cozinha.php, produtos.php, storage.js, comanda.js, cozinha-shared.js e referencias de cache HTML. Arquivos raiz sao a fonte versionada; outras instancias existentes nao foram atualizadas em massa. Nenhuma publicacao em producao.

## Retomar bloco 2

### Progresso em 2026-09-14 (bloco ainda NAO concluido, sem commit do bloco)

- Diagnostico CLI somente leitura em tests/time-diagnostics.php: PHP CLI Europe/Berlin; MySQL SYSTEM/America/Sao_Paulo; NOW local e UTC_TIMESTAMP diferentes como esperado; tabelas de fusos MySQL indisponiveis (CONVERT_TZ com America/Belem retorna NULL). Nao inferir timezone HTTP a partir do CLI.
- Historico DATETIME levantado em origem/teste2; ainda NAO convertido e nenhum timestamp historico editado. Antes da migracao completa, criar backup protegido. TIMESTAMP ja representa instante UTC internamente; nao aplicar deslocamento manual.
- time_contract.php adicionado: validacao IANA, fuso da empresa, limites de relatorio [inicio, proximo dia) em UTC, serializacao de epoch em ISO Z. tests/time-contract.php PASS para virada do dia, dias de 23/25 horas e rejeicao de datas invalidas. Limites ainda nao integrados em relatorios.php.
- cozinha.php usa UNIX_TIMESTAMP para instantes TIMESTAMP e serializa ISO Z; cancelamentos comparam epoch, sem strtotime dependente de PHP. cozinha-shared.js respeita Z/offset; atualizado usa relogio calibrado do servidor e fuso empresarial.
- tests/kitchen-timezone.cjs PASS em UTC/Belem/Tokyo, Z e offset, com dispositivo uma hora adiantado. tests/kitchen-access-error.cjs continua PASS. PHP lint config/empresa/cozinha e Node syntax realizados.
- config.php possui nova migracao ADITIVA company_timezone.v1; empresa.timezone default America/Belem. empresa.php valida/preserva configuracao pela API. Migracao executada na origem via GET cozinha; API retorna timezone America/Belem. A configuracao ainda nao esta integrada a todos os dominios.
- Copia local autorizada em teste2 concluida com config.php, empresa.php, time_contract.php, cozinha.php, cozinha-shared.js e cache ref 20260914time1 em cozinha HTML. API verificada apos segunda copia: timezone America/Belem e Mesa4 ISO UTC. Root cozinha.html/cozinha-mobile.html tambem atualizados cache.
- API teste2 Mesa4: comanda65 criada 2026-09-11T16:01:56Z (=13:01:56 Belem); item32 criado/enviado16:02:18Z. Agora item esta PRONTO (mudou desde checkpoint anterior; esta execucao nao alterou status). Browser tab4 no modo Todos exibe Mesa4/item, cliente Mayron Mycael; modo Pendentes vazio pois pedidos prontos. Nenhuma venda/pedido real alterado.
- Pendente: UTC integral/backup DATETIME, contratos de entrada/saida restantes, Dashboard/Auditoria, relatorios efetivos e validacao HTTP de meia-noite, assinaturas/backup; depois commit bloco2. Nao declarar concluido nem producao pronta.

1. Diagnosticar @@session.time_zone, @@global.time_zone, NOW(), UTC_TIMESTAMP(), tipos TIMESTAMP/DATETIME e timezone PHP em origem, teste2 e admin.
2. Comparar Mesa 4 no banco/API/Dashboard/Cozinha/Auditoria sem alterar o registro real.
3. Definir UTC no armazenamento/conexoes e timezone por empresa America/Belem; MariaDB nao possui timestamptz, usar equivalente com conversao explicita nas fronteiras. Fazer backup antes de converter historico DATETIME.
4. Ajustar limites de data de relatorios em timezone empresarial e testar virada de dia. Commit do bloco 2 antes do bloco 3.

## Pendencias de aceite do sprint

Horarios; diagnostico A/B/C/D do flash; shadcn/ui e lucide-react; identidade visual e color picker; venda completa/PDV; varredura funcional; PWA/offline duravel e idempotente; notificacoes/push; autenticacao/autorizacao e producao. A migracao Next.js antiga esta incompleta e nao e o runtime atual. README.md contem marcadores de conflito preexistentes. Nao considerar os testes do bloco 1 homologacao financeira, offline, ou de seguranca.
