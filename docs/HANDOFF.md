# Comanda Online — transferência em 14/09/2026

## Última demanda: concluída

Versão PHP atualizada publicada na InfinityFree e provisionamento real validado em https://saascomanda.gt.tc/admin/ . Isso não significa que o sprint completo esteja aprovado para produção.

- Repositório: https://github.com/mayronmycael4/comandaonline
- Branch publicada: `codex/cloud-migration-20260914`.
- Commit executado na hospedagem: `5360a3599f3d7bd2ba63b1d07100f21176783ce2`.
- Deploy aprovado: https://github.com/mayronmycael4/comandaonline/actions/runs/34871483896
- Correção anterior de empacotamento/provisionamento: `aac3274`.
- Não houve publicação em Supabase ou Vercel. O aplicativo publicado continua PHP/HTML/JS, não Next.js. A integração Vercel não está validada.

## O que foi corrigido e testado nesta entrega

1. O pacote FTP passou a incluir os schemas necessários. `scripts/build_deploy.py` exclui dumps, testes, arquivos locais e material que não pertence à aplicação publicada; a configuração de banco é gerada com secret do GitHub.
2. Deploy preserva pastas de clientes, backups, logs e armazenamento existentes. A atualização do modelo não atualiza automaticamente clientes já provisionados.
3. Provisionamento em banco compartilhado usa prefixo por cliente e recusa prefixos existentes antes de importar, evitando sobrescrever tabelas de outro cliente.
4. Schema para novos clientes usa TIMESTAMP, com nulabilidade corrigida. Detecção de schema UTC considera apenas tabelas do prefixo atual.
5. Corrigido redirecionamento do `.htaccess` copiado para clientes: login do cliente não é mais enviado ao login central.
6. Testes locais `tests/admin-provisioning.php` e `tests/shared-provisioning.php` passaram; este último valida importação, proteção de prefixo, preservação de dados e ausência de DATETIME no schema novo.
7. Na hospedagem, empresa ID 11 criada e provisionada; Dashboard abriu via Acessar Página, VOLTAR retornou ao cadastro central, sair e entrar com a conta provisionada funcionaram.
8. Empresas de QA IDs 10 e 11 ficaram **bloqueadas**, confirmado na listagem central. Nenhum pagamento foi registrado. Registros e pastas foram preservados para rastreabilidade. A ID 10 foi criada antes da correção do redirecionamento e mantém a cópia antiga; não usar como modelo funcional.

## Estado anterior que deve ser preservado

- Login, sidebar responsiva, menu único de usuário/tema, seções agrupadas e botão VOLTAR implementados.
- Correções de envio Comanda → Cozinha implementadas, com identidade dos itens e tratamento de erro. A causa observada na teste2 foi bloqueio do módulo Cozinha pelo plano (HTTP 403 tratado como lista vazia); habilitação autorizada apenas para teste2.
- Normalização UTC e limites diários por empresa implementados no código operacional local/teste2, commit local `5bdebbf`; isso não certifica todos os registros legados da hospedagem.
- Testes visuais anteriores de 15 telas desktop e 10 mobile não substituem validação funcional de todas as telas.

## Pendências da lista original

| Bloco | Situação e trabalho restante |
| --- | --- |
| 1 — Comanda → Cozinha | Correções existentes; completar aceite ponta a ponta autenticado, itens novos sem reenvio, cancelamento/status sincronizado e isolamento entre empresas. Não refazer a correção de plano da teste2. |
| 2 — Horários | Parcial. Na hospedagem o cadastro/auditoria central exibiu 09:55 enquanto o cliente exibiu 13:55, no mesmo instante. Corrigir timezone de assinaturas/provisionamento/log central e validar legado, caixa, pagamentos, backup e virada do dia. |
| 3 — Flash branco/layout antigo | Pendente. Há indícios de tema/layout aplicados em DOMContentLoaded e rotas móveis intermediárias; diagnóstico A/B/C/D ainda não fechado. Corrigir causa e testar claro/escuro/sistema, cache desligado, URL direta e histórico. |
| 4 — shadcn/ui e marca | Pendente: adoção solicitada de shadcn/ui/lucide-react, remoção de emojis, color picker HEX com contraste e preview, logo validado e identidade por cliente/PWA. Definir migração compatível com a arquitetura antes de instalar componentes React no PHP. |
| 5 — Venda completa e PDV | Bloqueador: PDV está indisponível. Implementar catálogo e relatório compartilhados (`tipo=pdv|comanda`), carrinho, dinheiro/cartão/Pix, troco e recibo opcional. Executar abertura de caixa → comanda → KDS → entrega → pagamento → estoque/relatório/auditoria → fechamento. |
| 6 — Varredura funcional | Pendente aceite de todas as abas, permissões, CRUD, busca/filtro, loading/empty/error e mobile. Produtos: foto/ativo/categoria; Nova Comanda: mesa/funcionário/cliente opcional; KDS: colunas e status; Caixa: sangria/suprimento, contado x teórico e histórico; QR: gerar/baixar/imprimir; Perfil: dados/senha/tema. Diferenciar Auditoria de Monitoramento. Ampliar superadmin para planos/assinaturas e isolamento. |
| 7 — PWA/offline | Pendente aceite e conclusão: instalação, atualização segura, cache sem dados sensíveis, fila IndexedDB durável, idempotência, retry/conflitos e reconexão sem duplicar. Não confirmar pagamentos sem servidor; impedir fechar caixa com operação crítica pendente. |
| 8 — Notificações | Pendente: eventos em tempo real, notificação persistida de pedido pronto, destinatários autorizados, não lidas, som/permissão, Web Push com fallback e isolamento. |
| 9 — Segurança/produção | Pendente autenticação/autorização em servidor, isolamento efetivo (RLS se houver Supabase), revisão de metadados de auditoria confiados ao cliente e de armazenamento local entre tenants; secrets, headers, migrations, backup/restauração/rollback e testes finais. |

Pedido separado de migração/publicação Next.js + Supabase + Vercel permanece não concluído. InfinityFree atende à versão PHP publicada nesta entrega; não presumir que hospeda um servidor Next.js.

## Cuidados para a próxima ferramenta

**Usar a branch publicada como base para continuar a hospedagem.** A pasta principal local contém mudanças anteriores ainda não commitadas, configuração com credencial real e scaffold Next.js incompleto. Não fazer `git add .`, não publicar configurações locais e não resetar o trabalho do usuário.

- Cópia exata usada para publicação: `C:\Users\malvezdossan\AppData\Local\Temp\comanda-cloud-publish-20260914` (temporária; preferir checkout persistente da branch remota).
- Projeto local original: `C:\xampp\htdocs\Comanda-Online-main`.
- A pasta não versionada `deploy/` da cópia temporária contém pacote de teste; nunca commitá-la.
- Push em `main` ou `codex/cloud-migration-20260914` aciona deploy FTP. Este documento é enviado com `[skip ci]`; o commit de produção continua o indicado acima.
- Os fixes de publicação não foram mesclados indiscriminadamente na pasta principal suja. Comparar as duas árvores antes de continuar.
- Clientes antigos precisam de estratégia própria de atualização e migrations; exclusão no FTP os preserva, mas também conserva seu código anterior.
- Não colocar senhas no relatório nem reutilizar senhas de QA. Recuperação de administrador deve usar o fluxo apropriado.

Próximo passo recomendado: resolver segurança/autenticação e timezone central pendentes, concluir PDV e validar o fluxo financeiro antes de declarar o SaaS pronto. Manter os demais blocos no backlog e registrar cada conclusão com evidência.

## Decisão de aceite

**Deploy e provisionamento InfinityFree: aprovados no escopo testado. Sprint completo: NÃO PRONTO PARA PRODUÇÃO.** Faltam os aceites financeiro, offline, isolamento, notificações e demais blocos acima.
