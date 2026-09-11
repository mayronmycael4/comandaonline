# Continuação — Comanda Online

## Etapa 1 — Superadmin (11/09/2026)

- Mantida a aplicação PHP/HTML/JS e o padrão documentado em UI_GUIDE.md. Arquivos da migração Next.js e alterações anteriores não foram refeitos.
- Login superadmin real no Apache local: aprovado. Dashboard, empresas, planos, cadastro/edição de empresa e plano: respostas HTTP 200, sem erros PHP; telas de dashboard, lista, edição e licença inspecionadas no navegador.
- Entrada como cliente ESPETARIA OLIVEIRA e retorno ao formulário da empresa: aprovados no navegador. As instâncias antigas em `C:/xampp/htdocs/clientes` possuem cópias antigas da interface. Atualizar a origem não atualiza essas cópias automaticamente.
- `tests/admin-http-qa.ps1`: provisionamento novo, renovação, regras da licença e bloqueio/desbloqueio aprovados. Empresa de QA preservada: ID 9, `QA Comanda 20260911094051`. Pagamento fictício de R$ 79,90 exclusivo do localhost; considerar esse registro ao consultar totais administrativos locais.
- Corrigido provisionamento que apagava banco/pasta existentes. Conflitos agora preservam os dados e reportam erro. Recuperação de provisionamento parcial requer inspeção dos recursos existentes; não apagar automaticamente para repetir.
- Cópia exclui arquivos ocultos privados, Git, arquivos temporários, código da migração e subpasta de clientes; preserva `.htaccess`. Falha de cópia agora interrompe o provisionamento.
- Teste `php tests/admin-provisioning.php`: aprovado; sintaxe PHP aprovada.
- Validação mobile do superadmin ainda pendente: a ferramenta de navegador manteve viewport 1280 px ao solicitar 390 px. Não considerar isso evidência mobile.

## Próximas etapas

2. Menu único de usuário no rodapé.
3. Grupos recolhíveis da sidebar.
4. Produtos, nova comanda, KDS, caixa, QR e perfil.
5. PDV e relatório compartilhado.
6. Estados de interface e checklist final de QA, incluindo mobile pendente.

## Etapa 2 — Menu de usuario

- Um unico acionador com avatar e nome no rodape, com Perfil, Tema (claro/escuro/sistema) e Sair. Dropdown fecha por Escape e clique externo; funciona com sidebar recolhida.
- Testado no navegador no painel e na aplicacao cliente: menu unico, temas, perfil administrativo, Escape, modo recolhido e logout do cliente aprovados. Sessao superadmin permaneceu disponivel apos sair do cliente.
- Perfil administrativo passa a ter rota propria de consulta; edicao e senha pertencem a etapa 4.
- Referencias de assets e cache PWA versionados para evitar carregar o menu antigo. O commit inclui as integracoes de sidebar ja presentes nos HTMLs alterados, sem refazer telas nem mudar seus formularios.

## Etapa 3 — Sidebar agrupada

- Operacao, Cadastros, Gestao e Sistema organizados na ordem solicitada, mantendo a filtragem existente de permissoes e os itens anteriores. PDV reservado/desabilitado ate a etapa 5.
- Grupos recolhiveis com preferencia persistida; grupo da pagina ativa abre automaticamente. Modo somente icones mostra os itens mesmo quando o grupo estava fechado. Testado no navegador.
- Validacao mobile efetivamente concluida em 390x844: dashboard admin, empresas, planos, cadastro/edicao de empresa e plano, perfil administrativo, sem overflow horizontal. Menu cliente, conta e Escape aprovados no mobile. A pendencia mobile da etapa 1 foi resolvida.
- Retificacao da etapa 2: a permanencia da sessao superadmin havia sido inferida de uma aba ja carregada; em navegacao posterior foi necessario autenticar novamente. O fluxo entrar/voltar foi validado, mas isolamento apos logout deve ser rechecado na etapa 6.
- `git diff --check` geral encontra marcadores de conflito preexistentes em README.md (linhas 1, 185, 187). Nao resolvidos nesta etapa de sidebar.
- Proxima etapa: 4 (Produtos, Nova Comanda, Cozinha, Caixa, QR e Perfil), depois 5 (PDV) e 6 (QA final).

## Ajuste — Botao VOLTAR
- Retorno ao painel movido para o cabecalho compartilhado, condicionado ao contexto SSO e URL administrativa da mesma origem. Corrige telas como Mesas que nao carregam branding.js.
- Aplicado tambem na instancia QA ID 9. Validado no navegador: Mesas -> VOLTAR -> cadastro da empresa ID 9, mantendo autenticacao superadmin.

