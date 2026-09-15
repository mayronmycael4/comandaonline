-- =====================================================
-- BANCO DE DADOS COMANDA ONLINE - COMPLETO E ATUALIZADO
-- Execute este script no phpMyAdmin
-- =====================================================

-- Cria banco de dados
CREATE DATABASE IF NOT EXISTS comanda_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE comanda_online;

-- =====================================================
-- TABELA: EMPRESA
-- =====================================================
CREATE TABLE IF NOT EXISTS empresa (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(255) NOT NULL,
    cnpj VARCHAR(20),
    endereco TEXT,
    telefone VARCHAR(20),
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- TABELA: FUNCIONARIOS
-- =====================================================
CREATE TABLE IF NOT EXISTS funcionarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(255) NOT NULL,
    login VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- TABELA: CLIENTES (para fidelidade)
-- =====================================================
CREATE TABLE IF NOT EXISTS clientes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(255) NOT NULL,
    cpf VARCHAR(14) NULL,
    contato VARCHAR(20),
    email VARCHAR(100),
    observacoes TEXT,
    pontos_fidelidade INT DEFAULT 0,
    total_gasto DECIMAL(10,2) DEFAULT 0,
    total_visitas INT DEFAULT 0,
    ultima_visita TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Índice para CPF (permite múltiplos NULLs)
CREATE UNIQUE INDEX idx_clientes_cpf ON clientes(cpf);

-- =====================================================
-- TABELA: PRODUTOS
-- =====================================================
CREATE TABLE IF NOT EXISTS produtos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(255) NOT NULL,
    categoria ENUM('bebidas', 'espetos', 'hamburgers', 'outros') NOT NULL,
    preco DECIMAL(10,2) NOT NULL,
    descricao TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- TABELA: ESTOQUE
-- =====================================================
CREATE TABLE IF NOT EXISTS estoque (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(255) NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    quantidade DECIMAL(10,2) NOT NULL DEFAULT 0,
    unidade VARCHAR(20) NOT NULL,
    quantidade_minima DECIMAL(10,2) DEFAULT 5,
    valor_unitario DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- TABELA: COMANDAS
-- =====================================================
CREATE TABLE IF NOT EXISTS comandas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    numero_mesa VARCHAR(50) NOT NULL,
    funcionario_id INT NOT NULL,
    cliente_id INT NULL,
    status ENUM('aberta', 'fechada') DEFAULT 'aberta',
    total DECIMAL(10,2) DEFAULT 0,
    observacoes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fechamento_data TIMESTAMP NULL,
    duracao VARCHAR(20),
    FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id)
);

-- =====================================================
-- TABELA: ITENS DA COMANDA
-- =====================================================
CREATE TABLE IF NOT EXISTS comanda_itens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    comanda_id INT NOT NULL,
    produto_id INT NULL,
    nome_item VARCHAR(255) NOT NULL,
    categoria VARCHAR(50),
    quantidade INT NOT NULL,
    valor_unitario DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (comanda_id) REFERENCES comandas(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id)
);

-- =====================================================
-- TABELA: HISTORICO DE CLIENTES
-- =====================================================
CREATE TABLE IF NOT EXISTS cliente_historico (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cliente_id INT NOT NULL,
    comanda_id INT NOT NULL,
    valor_total DECIMAL(10,2) NOT NULL,
    pontos_ganhos INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (comanda_id) REFERENCES comandas(id)
);

-- =====================================================
-- TABELA: LISTA DE COMPRAS
-- =====================================================
CREATE TABLE IF NOT EXISTS lista_compras (
    id INT PRIMARY KEY AUTO_INCREMENT,
    estoque_id INT,
    nome_item VARCHAR(255) NOT NULL,
    quantidade_necessaria DECIMAL(10,2) NOT NULL,
    quantidade_minima DECIMAL(10,2),
    unidade VARCHAR(20),
    prioridade ENUM('baixa', 'media', 'alta') DEFAULT 'media',
    status ENUM('pendente', 'comprado', 'cancelado') DEFAULT 'pendente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (estoque_id) REFERENCES estoque(id)
);

-- =====================================================
-- TABELA: SESSOES (controle de login)
-- =====================================================
CREATE TABLE IF NOT EXISTS sessoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    funcionario_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
);

-- =====================================================
-- USUARIO ADMIN PADRAO (senha: admin123)
-- =====================================================
INSERT INTO funcionarios (nome, login, senha, is_admin) VALUES 
('Administrador', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE)
ON DUPLICATE KEY UPDATE nome = nome;

-- =====================================================
-- INDICES PARA PERFORMANCE
-- =====================================================
CREATE INDEX idx_comandas_status ON comandas(status);
CREATE INDEX idx_comandas_funcionario ON comandas(funcionario_id);
CREATE INDEX idx_comandas_cliente ON comandas(cliente_id);
CREATE INDEX idx_comanda_itens_comanda ON comanda_itens(comanda_id);
CREATE INDEX idx_cliente_historico_cliente ON cliente_historico(cliente_id);
CREATE INDEX idx_estoque_quantidade ON estoque(quantidade);

-- =====================================================
-- MENSAGEM DE SUCESSO
-- =====================================================
SELECT 'Banco de dados criado/atualizado com sucesso!' AS status;
