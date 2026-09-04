
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `action_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `action_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor_nome` varchar(120) DEFAULT NULL,
  `actor_login` varchar(120) DEFAULT NULL,
  `acao` varchar(80) NOT NULL,
  `entidade` varchar(80) NOT NULL,
  `entidade_id` varchar(80) DEFAULT NULL,
  `detalhes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalhes`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action_log_created_at` (`created_at`),
  KEY `idx_action_log_actor_id` (`actor_id`),
  KEY `idx_action_log_entidade` (`entidade`)
) ENGINE=InnoDB AUTO_INCREMENT=201 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_request_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_request_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `rota` varchar(255) NOT NULL,
  `metodo` varchar(10) NOT NULL,
  `status_code` int(11) NOT NULL,
  `duracao_ms` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_api_log_data_status` (`created_at`,`status_code`),
  KEY `idx_api_log_rota` (`rota`(120),`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8176 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caixa_movimentacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caixa_movimentacoes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `caixa_sessao_id` bigint(20) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_caixa_mov_sessao` (`caixa_sessao_id`,`created_at`),
  KEY `idx_caixa_mov_tipo` (`tipo`,`created_at`),
  CONSTRAINT `caixa_movimentacoes_ibfk_1` FOREIGN KEY (`caixa_sessao_id`) REFERENCES `caixa_sessoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caixa_sessoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caixa_sessoes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `operador_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'aberto',
  `valor_inicial` decimal(12,2) NOT NULL DEFAULT 0.00,
  `valor_contado` decimal(12,2) DEFAULT NULL,
  `divergencia` decimal(12,2) DEFAULT NULL,
  `observacao_abertura` varchar(255) DEFAULT NULL,
  `observacao_fechamento` varchar(255) DEFAULT NULL,
  `aberto_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `fechado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_caixa_status_aberto` (`status`,`aberto_em`),
  KEY `idx_caixa_operador` (`operador_id`,`aberto_em`),
  CONSTRAINT `caixa_sessoes_ibfk_1` FOREIGN KEY (`operador_id`) REFERENCES `funcionarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_consentimento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_consentimento` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `tipo_consentimento` varchar(40) NOT NULL,
  `aceito` tinyint(1) NOT NULL DEFAULT 0,
  `origem` varchar(40) DEFAULT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cliente_consent_tipo` (`cliente_id`,`tipo_consentimento`),
  KEY `idx_consent_tipo_data` (`tipo_consentimento`,`created_at`),
  CONSTRAINT `cliente_consentimento_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `comanda_id` int(11) NOT NULL,
  `valor_total` decimal(10,2) NOT NULL,
  `pontos_ganhos` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `comanda_id` (`comanda_id`),
  CONSTRAINT `cliente_historico_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `cliente_historico_ibfk_2` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `cpf` varchar(14) DEFAULT NULL,
  `contato` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `pontos_fidelidade` int(11) DEFAULT 0,
  `total_gasto` decimal(10,2) DEFAULT 0.00,
  `total_visitas` int(11) DEFAULT 0,
  `ultima_visita` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `observacoes` text DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cpf` (`cpf`),
  KEY `idx_clientes_cpf` (`cpf`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comanda_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comanda_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comanda_id` int(11) NOT NULL,
  `produto_id` int(11) DEFAULT NULL,
  `nome_item` varchar(255) NOT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `quantidade` int(11) NOT NULL,
  `valor_unitario` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `kitchen_status` enum('recebido','em_preparo','pronto','entregue','cancelado') NOT NULL DEFAULT 'recebido',
  `kitchen_pronto_at` timestamp NULL DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `kitchen_setor` varchar(40) NOT NULL DEFAULT 'cozinha',
  `enviado_producao_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `idx_comanda_itens_comanda` (`comanda_id`),
  KEY `idx_comanda_itens_comanda_status` (`comanda_id`,`kitchen_status`),
  CONSTRAINT `comanda_itens_ibfk_1` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comanda_itens_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comanda_operacoes_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comanda_operacoes_historico` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `operacao` varchar(60) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `actor_id` int(11) DEFAULT NULL,
  `actor_login` varchar(120) DEFAULT NULL,
  `actor_nome` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_comanda_operacao_created` (`created_at`),
  KEY `idx_comanda_operacao_tipo` (`operacao`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comanda_request_dedupe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comanda_request_dedupe` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` varchar(80) NOT NULL,
  `comanda_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_comanda_request` (`request_id`),
  KEY `idx_comanda_request_created` (`created_at`),
  KEY `comanda_id` (`comanda_id`),
  CONSTRAINT `comanda_request_dedupe_ibfk_1` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comanda_status_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comanda_status_historico` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `comanda_id` int(11) NOT NULL,
  `status_anterior` varchar(40) DEFAULT NULL,
  `status_novo` varchar(40) NOT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_nome` varchar(120) DEFAULT NULL,
  `actor_login` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hist_status_comanda` (`comanda_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comandas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comandas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_mesa` varchar(50) NOT NULL,
  `funcionario_id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `status` enum('aberta','fechada','cancelada') NOT NULL DEFAULT 'aberta',
  `total` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fechamento_data` timestamp NULL DEFAULT NULL,
  `duracao` varchar(20) DEFAULT NULL,
  `versao` int(11) NOT NULL DEFAULT 1,
  `forma_pagamento` varchar(50) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comandas_status` (`status`),
  KEY `idx_comandas_funcionario` (`funcionario_id`),
  KEY `idx_comandas_cliente` (`cliente_id`),
  CONSTRAINT `comandas_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`),
  CONSTRAINT `comandas_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cupons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cupons` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `tipo_desconto` enum('percentual','valor') NOT NULL DEFAULT 'percentual',
  `valor_desconto` decimal(12,2) NOT NULL,
  `valor_minimo_pedido` decimal(12,2) NOT NULL DEFAULT 0.00,
  `validade_inicio` datetime DEFAULT NULL,
  `validade_fim` datetime DEFAULT NULL,
  `limite_uso` int(11) DEFAULT NULL,
  `usos_atuais` int(11) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `regras` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`regras`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cupom_codigo` (`codigo`),
  KEY `idx_cupom_ativo_validade` (`ativo`,`validade_fim`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `db_bootstrap_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `db_bootstrap_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `nivel` varchar(20) NOT NULL,
  `mensagem` varchar(255) NOT NULL,
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bootstrap_log_data` (`created_at`,`nivel`)
) ENGINE=InnoDB AUTO_INCREMENT=442 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `empresa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empresa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `cnpj` varchar(20) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `logo_path` varchar(255) DEFAULT NULL,
  `cor_primaria` varchar(20) DEFAULT NULL,
  `cor_secundaria` varchar(20) DEFAULT NULL,
  `modulos_habilitados` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `error_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `error_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `rota` varchar(255) NOT NULL,
  `metodo` varchar(10) NOT NULL,
  `status_code` int(11) NOT NULL,
  `error_code` varchar(80) DEFAULT NULL,
  `mensagem` varchar(255) DEFAULT NULL,
  `detalhes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalhes`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_error_events_data` (`created_at`,`status_code`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `quantidade` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unidade` varchar(20) NOT NULL,
  `quantidade_minima` decimal(10,2) DEFAULT 5.00,
  `valor_unitario` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `custo_medio` decimal(12,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_estoque_quantidade` (`quantidade`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_movimentacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_movimentacoes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `estoque_id` int(11) NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `quantidade` decimal(12,4) NOT NULL,
  `custo_unitario` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `comanda_id` int(11) DEFAULT NULL,
  `referencia_tipo` varchar(40) DEFAULT NULL,
  `referencia_id` varchar(80) DEFAULT NULL,
  `documento_origem` varchar(80) DEFAULT NULL,
  `fornecedor_nome` varchar(160) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `metadados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadados`)),
  `actor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_est_mov_item_data` (`estoque_id`,`created_at`),
  KEY `idx_est_mov_tipo_data` (`tipo`,`created_at`),
  CONSTRAINT `estoque_movimentacoes_ibfk_1` FOREIGN KEY (`estoque_id`) REFERENCES `estoque` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feedbacks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) DEFAULT NULL,
  `funcionario_nome` varchar(120) DEFAULT NULL,
  `tipo` enum('sugestao','erro','melhoria','outro') NOT NULL DEFAULT 'outro',
  `mensagem` text NOT NULL,
  `lido` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `funcionarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `login` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `permissoes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sessao_versao` int(11) NOT NULL DEFAULT 1,
  `sessao_revogada_em` datetime DEFAULT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'garcom',
  `nome_exibicao` varchar(120) DEFAULT NULL,
  `ultimo_login` datetime DEFAULT NULL,
  `failed_login_attempts` smallint(6) NOT NULL DEFAULT 0,
  `blocked_until` datetime DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `idx_funcionarios_role_status` (`role`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kds_impressao_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kds_impressao_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `comanda_id` int(11) NOT NULL,
  `setor` varchar(40) NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` varchar(20) NOT NULL DEFAULT 'gerado',
  `actor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `impresso_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_kds_print` (`comanda_id`,`setor`,`payload_hash`),
  KEY `idx_kds_print_status` (`status`,`created_at`),
  KEY `idx_kds_print_comanda_setor` (`comanda_id`,`setor`,`created_at`),
  CONSTRAINT `kds_impressao_log_ibfk_1` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lista_compras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lista_compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estoque_id` int(11) DEFAULT NULL,
  `nome_item` varchar(255) NOT NULL,
  `quantidade_necessaria` decimal(10,2) NOT NULL,
  `quantidade_minima` decimal(10,2) DEFAULT NULL,
  `unidade` varchar(20) DEFAULT NULL,
  `prioridade` enum('baixa','media','alta') DEFAULT 'media',
  `status` enum('pendente','comprado','cancelado') DEFAULT 'pendente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fornecedor_nome` varchar(160) DEFAULT NULL,
  `nota_fiscal` varchar(80) DEFAULT NULL,
  `custo_unitario_real` decimal(12,4) DEFAULT NULL,
  `recebido_em` datetime DEFAULT NULL,
  `observacoes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estoque_id` (`estoque_id`),
  CONSTRAINT `lista_compras_ibfk_1` FOREIGN KEY (`estoque_id`) REFERENCES `estoque` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_automacoes_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketing_automacoes_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(40) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` varchar(20) NOT NULL DEFAULT 'pendente',
  `executado_em` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_marketing_tipo_status` (`tipo`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notificacoes_fila`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notificacoes_fila` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `titulo` varchar(140) NOT NULL,
  `mensagem` text NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` enum('pendente','lida') NOT NULL DEFAULT 'pendente',
  `lida_em` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_func_status` (`funcionario_id`,`status`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pagamentos_comanda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagamentos_comanda` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `comanda_id` int(11) NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'confirmado',
  `transacao_id` varchar(120) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pagamentos_comanda` (`comanda_id`,`created_at`),
  KEY `idx_pagamentos_tipo_status` (`tipo`,`status`),
  CONSTRAINT `pagamentos_comanda_ibfk_1` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissoes_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissoes_catalog` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `chave` varchar(120) NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `categoria` varchar(80) NOT NULL DEFAULT 'geral',
  `is_critica` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_permissao_chave` (`chave`),
  KEY `idx_permissao_categoria` (`categoria`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=196592 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produto_adicionais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produto_adicionais` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) DEFAULT NULL,
  `categoria` varchar(80) DEFAULT NULL,
  `nome` varchar(120) NOT NULL,
  `preco` decimal(12,2) NOT NULL DEFAULT 0.00,
  `obrigatorio` tinyint(1) NOT NULL DEFAULT 0,
  `limite_min` int(11) NOT NULL DEFAULT 0,
  `limite_max` int(11) NOT NULL DEFAULT 3,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_add_prod_cat` (`produto_id`,`categoria`,`is_active`),
  CONSTRAINT `produto_adicionais_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produto_combos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produto_combos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `preco_combo` decimal(12,2) NOT NULL,
  `regras` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`regras`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produto_combos_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produto_combos_itens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `combo_id` bigint(20) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade` decimal(12,2) NOT NULL DEFAULT 1.00,
  `obrigatorio` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `idx_combo_item_combo` (`combo_id`),
  CONSTRAINT `produto_combos_itens_ibfk_1` FOREIGN KEY (`combo_id`) REFERENCES `produto_combos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `produto_combos_itens_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produto_fichas_tecnicas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produto_fichas_tecnicas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `estoque_id` int(11) NOT NULL,
  `quantidade` decimal(12,4) NOT NULL,
  `unidade` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ficha_item` (`produto_id`,`estoque_id`),
  KEY `idx_ficha_produto` (`produto_id`,`is_active`),
  KEY `estoque_id` (`estoque_id`),
  CONSTRAINT `produto_fichas_tecnicas_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `produto_fichas_tecnicas_ibfk_2` FOREIGN KEY (`estoque_id`) REFERENCES `estoque` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produto_promocoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produto_promocoes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT 'percentual',
  `valor` decimal(12,2) NOT NULL,
  `produto_id` int(11) DEFAULT NULL,
  `categoria` varchar(80) DEFAULT NULL,
  `dia_semana` varchar(20) DEFAULT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fim` time DEFAULT NULL,
  `regras` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`regras`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `idx_promocoes_ativas` (`is_active`,`produto_id`,`categoria`),
  CONSTRAINT `produto_promocoes_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produto_variacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produto_variacoes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `grupo` varchar(80) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `preco_delta` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_var_prod_grupo` (`produto_id`,`grupo`,`is_active`),
  CONSTRAINT `produto_variacoes_ibfk_1` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produtos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `categoria` enum('bebidas','espetos','hamburgers','outros') NOT NULL,
  `preco` decimal(10,2) NOT NULL,
  `descricao` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `setor` varchar(40) NOT NULL DEFAULT 'cozinha',
  `imagem_url` varchar(255) DEFAULT NULL,
  `tags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags_json`)),
  `is_disponivel` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qr_menu_idempotencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_menu_idempotencia` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `mesa_numero` varchar(50) NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `janela_slot` bigint(20) NOT NULL,
  `qr_pedido_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_qr_idempotencia` (`mesa_numero`,`payload_hash`,`janela_slot`),
  KEY `idx_qr_idempotencia_data` (`created_at`),
  KEY `qr_pedido_id` (`qr_pedido_id`),
  CONSTRAINT `qr_menu_idempotencia_ibfk_1` FOREIGN KEY (`qr_pedido_id`) REFERENCES `qr_menu_pedidos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qr_menu_pedido_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_menu_pedido_itens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `qr_pedido_id` bigint(20) NOT NULL,
  `comanda_item_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `produto_nome` varchar(255) NOT NULL,
  `quantidade` decimal(10,2) NOT NULL,
  `valor_unitario` decimal(10,2) NOT NULL,
  `variacao_nome` varchar(120) DEFAULT NULL,
  `adicionais_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`adicionais_json`)),
  `observacao_item` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qr_item_pedido` (`qr_pedido_id`),
  KEY `idx_qr_item_comanda_item` (`comanda_item_id`),
  CONSTRAINT `qr_menu_pedido_itens_ibfk_1` FOREIGN KEY (`qr_pedido_id`) REFERENCES `qr_menu_pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `qr_menu_pedido_itens_ibfk_2` FOREIGN KEY (`comanda_item_id`) REFERENCES `comanda_itens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `qr_menu_pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_menu_pedidos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `comanda_id` int(11) NOT NULL,
  `mesa_numero` varchar(50) NOT NULL,
  `cliente_nome` varchar(120) NOT NULL,
  `observacao_cliente` varchar(255) DEFAULT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qr_pedidos_mesa_data` (`mesa_numero`,`created_at`),
  KEY `idx_qr_pedidos_comanda_data` (`comanda_id`,`created_at`),
  CONSTRAINT `qr_menu_pedidos_ibfk_1` FOREIGN KEY (`comanda_id`) REFERENCES `comandas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissoes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `role` varchar(30) NOT NULL,
  `permissao_chave` varchar(120) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_perm` (`role`,`permissao_chave`),
  KEY `idx_role_permissoes_role` (`role`,`allowed`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_version`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_version` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `version` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_schema_version` (`version`)
) ENGINE=InnoDB AUTO_INCREMENT=8350 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `funcionario_id` (`funcionario_id`),
  CONSTRAINT `sessoes_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

