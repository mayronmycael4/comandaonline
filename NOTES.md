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
