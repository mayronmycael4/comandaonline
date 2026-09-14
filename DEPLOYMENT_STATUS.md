# Publicacao: Comanda Online

Esta branch contem a versao PHP/MariaDB validada localmente ate o contrato UTC,
consolidada sem publicar credenciais dos testes locais. A main nao foi alterada.

## GitHub

- Repositorio: mayronmycael4/comandaonline.
- Branch: codex/cloud-migration-20260914.
- Origem funcional: commit local 5bdebbf, incluindo correcoes de Superadmin,
  sidebar, retorno administrativo, Cozinha e horarios.
- Testes administrativos leem COMANDA_QA_EMAIL e COMANDA_QA_PASSWORD do ambiente.
- Primeiro cadastro administrativo exige configurar COMANDA_BOOTSTRAP_PASSWORD;
  sem ela, o bootstrap gera uma senha aleatoria em vez de uma senha publica fixa.

## Vercel e Supabase: ainda nao publicados

O runtime funcional usa PHP e MariaDB. A copia local de migracao Next.js ainda
tem somente pagina inicial, rotas login/cadastro ausentes e package.json sem
dependencias. Ela nao substitui o sistema operacional e nao foi incluida como
deploy funcional. Nao basta importar o dump MariaDB no PostgreSQL do Supabase.

Faltam os projetos de destino, autenticacao nas contas, migracoes PostgreSQL,
RLS por empresa, autenticacao, APIs e telas compativeis, variaveis de ambiente,
build e testes dos fluxos de venda. Aguardar os links dos projetos fornecidos
pelo proprietario. Nao transportar dados reais de clientes para a nuvem sem
definir o conjunto de dados da migracao.

O workflow existente na main publica para InfinityFree ao receber push na main.
Esta branch evita esse gatilho. Substituir esse fluxo antes de mesclar para o
novo destino. Nao executar o workflow manual de FTP para esta publicacao.

Nao pronto para producao: PDV, financeiro completo, isolamento/auth, offline,
notificacoes e QA final continuam pendentes conforme CONTINUATION.md.
