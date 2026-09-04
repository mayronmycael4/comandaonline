# Comanda Online

## Descrição

**Comanda Online** é um sistema completo para gerenciamento de comandas de restaurante de forma digital. Ele permite a criação, visualização e edição de comandas, controle de funcionários, relatórios financeiros e controle de estoque, facilitando a comunicação entre a equipe e melhorando a organização.

## Acesso

**Primeiro acesso (Setup do Banco):** `http://localhost/Comanda-Online-main/setup.html`

**Login:** `http://localhost/Comanda-Online-main/login.html`

## Instalação do Banco de Dados

1. Inicie o XAMPP (Apache e MySQL)
2. Acesse `http://localhost/phpmyadmin`
3. Importe o arquivo `database/comanda_online.sql`
4. Ou acesse `setup.html` para instruções passo a passo

## Funcionalidades

### Login e Autenticação

- Sistema de login com senha para segurança
- Configuração inicial da empresa e administrador
- Controle de acesso por funcionário
- Sessão persistente no navegador

### Dashboard Principal

A página inicial exibe:
- Nome da empresa em destaque
- Estatísticas em tempo real (comandas abertas, vendas do dia, etc.)
- Lista de todas as comandas
- Comandas separadas por funcionário responsável
- Criação rápida de nova comanda

### Gestão de Funcionários

- Cadastro de funcionários com login e senha
- Definição de administradores
- Visualização de comandas por funcionário
- Controle de responsabilidade por comanda

### Edição de Comanda

Ao clicar em uma comanda, você pode:
- Adicionar informações do cliente (nome, contato, observações)
- Selecionar produtos cadastrados por categoria (Bebidas, Espetos, Hamburgers)
- Adicionar itens com quantidade e valor
- Salvar a comanda como imagem para enviar via WhatsApp
- Fechar a comanda com registro de data/hora e duração

### Cadastro de Produtos

- Cadastro de produtos por categoria: Bebidas, Espetos, Hamburgers
- Preços pré-definidos para agilizar atendimento
- Seleção rápida na criação da comanda

### Controle de Estoque

- Cadastro de itens de estoque com categorias
- Controle de quantidade e unidades (kg, litros, unidades, etc.)
- Alertas para itens com quantidade baixa
- Entrada e saída de produtos
- Valor unitário dos itens

### Relatórios Financeiros

Relatórios detalhados por período:
- **Dia**: Vendas de hoje com ticket médio
- **Semana**: Vendas da semana atual
- **Mês**: Vendas por mês selecionável
- **Ano**: Vendas anuais
- **Personalizado**: Período customizado

Cada relatório inclui:
- Total de vendas
- Quantidade de comandas
- Ticket médio
- Vendas por categoria
- Vendas por funcionário (com gráfico)

### Backup e Restauração

- Download de todos os dados em JSON
- Importação de arquivo JSON
- Exclusão completa de dados
- Preservação de dados no MySQL

### Programa de Fidelidade

- Cadastro de clientes com CPF
- Histórico de compras por cliente
- Pontos de fidelidade (1 ponto a cada R$10)
- Identificação automática de cliente ao digitar CPF
- Visualização de total gasto e visitas

### Notificações Toast (Estilo iPhone)

- Notificações flutuantes elegantes
- Desaparecem automaticamente
- Tipos: Sucesso, Erro, Aviso, Info
- Não interrompem o fluxo do usuário

### Lista de Compras Automática

- Detecta automaticamente itens com estoque baixo
- Gera lista de compras prioritária
- Alertas visuais para itens críticos
- Botão para marcar como "Comprado"
- Gera arquivo para impressão

## Estrutura de Arquivos

```
Comanda-Online-main/
├── setup.html          # Setup do banco de dados
├── login.html          # Tela de login
├── index.html          # Dashboard principal
├── funcionarios.html   # Gestão de funcionários
├── comandas.html       # Lista de comandas
├── comanda.html        # Edição de comanda
├── produtos.html       # Cadastro de produtos
├── estoque.html        # Controle de estoque
├── relatorios.html     # Relatórios financeiros
├── download.html       # Backup/Restauração
├── database/
│   └── comanda_online.sql  # Estrutura do banco MySQL
├── api/                # Backend PHP
│   ├── config.php
│   ├── empresa.php
│   ├── funcionarios.php
│   ├── clientes.php
│   ├── produtos.php
│   ├── estoque.php
│   ├── comandas.php
│   ├── comandas_fechar.php
│   ├── lista_compras.php
│   ├── relatorios.php
│   └── login.php
├── css/
│   └── style.css       # Estilos
├── api.js              # Cliente API
├── toast.js            # Notificações
├── storage.js          # Armazenamento (MySQL)
├── index.js            # Dashboard
├── produtos.js         # Produtos
├── comanda.js          # Comandas
└── download.js         # Backup
```

## Como Usar

1. **Primeiro Acesso**:
   - Acesse `login.html`
   - Configure o nome da empresa
   - Cadastre o administrador

2. **Cadastrar Funcionários**:
   - Vá em "Funcionários"
   - Adicione novos funcionários com login e senha

3. **Cadastrar Produtos**:
   - Vá em "Produtos"
   - Cadastre itens por categoria (Bebidas, Espetos, Hamburgers)

4. **Criar Comanda**:
   - No Dashboard, selecione a mesa e o funcionário responsável
   - Adicione itens à comanda
   - Salve e envie para a cozinha

5. **Fechar Comanda**:
   - Ao final do atendimento, feche a comanda
   - O sistema registra hora e duração

6. **Relatórios**:
   - Acesse "Relatórios" para ver vendas
   - Filtre por dia, semana, mês ou ano

## Contribuição

Se você deseja contribuir para o projeto, sinta-se à vontade para abrir uma issue ou enviar um pull request.
