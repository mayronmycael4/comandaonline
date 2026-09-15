# UI Guide — Comanda Online

## Referência e escopo
Referência visual: https://github.com/mayronmycael4/controlefinanceiro, commit `9fa2dd7666578c76578e27a99b5e8a75048410c8` (leitura em 09/09/2026). Nenhuma alteração no repositório de referência. Aplicação alvo: PHP/HTML/JS atual. A migração Next.js solicitada anteriormente foi interrompida pela mudança de escopo; seus arquivos iniciais não fazem parte dos commits de UI.

Fontes consultadas: `src/app/globals.css`, `src/app/layout.tsx`, `src/app/login/page.tsx`, `src/app/(app)/layout.tsx`, `src/components/app-sidebar.tsx`, `src/components/ui/sidebar.tsx`, `src/components/ui/button.tsx`.

## Tokens
| Token | Claro | Escuro |
|---|---|---|
| background | oklch(1 0 0) | oklch(.145 0 0) |
| foreground | oklch(.145 0 0) | oklch(.985 0 0) |
| card | oklch(1 0 0) | oklch(.205 0 0) |
| primary | oklch(.205 0 0) | oklch(.922 0 0) |
| primary-foreground | oklch(.985 0 0) | oklch(.205 0 0) |
| muted/accent | oklch(.97 0 0) | oklch(.269 0 0) |
| muted-foreground | oklch(.556 0 0) | oklch(.708 0 0) |
| border/input | oklch(.922 0 0) | branco 10%/15% |
| sidebar | oklch(.985 0 0) | oklch(.205 0 0) |
| destructive | oklch(.577 .245 27.325) | oklch(.704 .191 22.216) |

Fonte: Geist Sans, fallback Arial/Helvetica/sans-serif. Raio base: .65rem; espaçamento em múltiplos de 4px; ícones lineares 16–20px; corpo 14px, textos auxiliares 12px, títulos de login 24px. Bordas finas e sombras discretas. Cores semânticas de erro/sucesso/status permanecem distintas.

## Layout e componentes
- Sidebar esquerda: 16rem expandida, 3rem recolhida; mobile 18rem limitada à largura disponível. Marca no topo, grupos Menu/Geral, conta no rodapé. Item ativo com fundo accent e aria-current. Ícones e rótulos, tooltip nativo quando recolhida.
- Cabeçalho de conteúdo: 4rem, borda inferior, acionador da sidebar, contexto da página e controles existentes. Conteúdo com 16px mobile/24px desktop.
- Login: duas colunas iguais a partir de 1024px. Esquerda com marca/tema e formulário centralizado em card de até 384px; direita com fundo primary, título de 30px, texto e três cartões auxiliares. No mobile permanece apenas o formulário; abaixo de 640px remove borda/sombra externa.
- Formulários com labels associados, bordas neutras, foco visível, autocomplete e botão primário de largura total. Nunca substituir IDs/names consumidos pelos handlers atuais.
- Sidebar mobile: hambúrguer, overlay, Escape, foco contido e devolvido ao acionador. Preferência de recolhimento persistida apenas para apresentação.

## Adaptações documentadas
- Implementar o padrão shadcn/Geist em CSS e DOM nativos, sem introduzir React nem alterar autenticação PHP.
- Manter nome/logo da empresa e alternância de tema atuais. Cores de navegação seguem a referência; cores funcionais de status continuam semânticas.
- Textos e ícone de marca adaptados ao restaurante; cartões do login descrevem recursos, sem números financeiros fictícios.
- Não adicionar cadastro/recuperação demo do Controle Financeiro: Comanda mantém apenas os fluxos existentes, inclusive setup condicional.
- Menu lateral reúne links existentes, respeitando a mesma filtragem de permissões do MenuGeral/Storage. Atalhos operacionais e ações de sair/retornar ao painel continuam ligados aos mesmos handlers.
- Cardápio público recebe o tema, sem navegação administrativa.
- Variantes históricas com sufixo `(1)` não são novas rotas oficiais; o tema comum atende as que já carregam style.css.
- Arquivos novos: `ui-theme.css`, `ui-login.css`, `ui-shell.js`; integração nas páginas HTML e no partial compartilhado do painel. Não modificar SQL, APIs, cálculos, sessões ou contratos de formulário.

## Validação
Ver `UI_QA_CHECKLIST.md` para evidências executadas e itens de homologação manual. Testes de apresentação não substituem homologação operacional.