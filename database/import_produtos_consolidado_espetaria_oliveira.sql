-- SQL consolidado de importacao de produtos
-- Blocos: Lanches, Bebidas, Cervejas, Doces/Balas e Pao de Alho
-- Gerado automaticamente para importacao unica

-- ===== INICIO: import_produtos_espetaria_oliveira.sql =====
-- Importacao automatica de produtos
-- Cardapio: Espetaria Oliveira
-- Compatibilidade: MySQL/MariaDB

SET NAMES utf8mb4;
START TRANSACTION;

-- =========================
-- 1) HAMBURGERS / LANCHES
-- =========================

-- Hamburguer Simples
UPDATE produtos
SET nome = 'Hamburguer Simples',
    categoria = 'hamburgers',
    preco = 15.00,
    descricao = 'Pao, carne, queijo e ovo.',
    is_active = 1
WHERE LOWER(nome) IN ('hamburguer simples', 'hamburger simples');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Hamburguer Simples', 'hamburgers', 15.00, 'Pao, carne, queijo e ovo.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('hamburguer simples', 'hamburger simples')
);

-- X Salada (normaliza variacoes com hifen)
UPDATE produtos
SET nome = 'X Salada',
    categoria = 'hamburgers',
    preco = 17.00,
    descricao = 'Pao, alface, queijo, tomate, milho, presunto e ovo.',
    is_active = 1
WHERE LOWER(REPLACE(nome, '-', ' ')) = 'x salada';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'X Salada', 'hamburgers', 17.00, 'Pao, alface, queijo, tomate, milho, presunto e ovo.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(REPLACE(nome, '-', ' ')) = 'x salada'
);

-- Desativa duplicatas de X Salada e mantem somente o registro mais recente
UPDATE produtos
SET is_active = 0
WHERE nome = 'X Salada'
  AND id NOT IN (
      SELECT id FROM (
          SELECT MAX(id) AS id
          FROM produtos
          WHERE nome = 'X Salada'
      ) AS keep_row
  );

-- X Tudo
UPDATE produtos
SET nome = 'X Tudo',
    categoria = 'hamburgers',
    preco = 24.00,
    descricao = 'Pao, alface, carne, queijo, presunto, tomate, milho e ovo.',
    is_active = 1
WHERE LOWER(nome) = 'x tudo';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'X Tudo', 'hamburgers', 24.00, 'Pao, alface, carne, queijo, presunto, tomate, milho e ovo.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'x tudo'
);

-- X Burguer
UPDATE produtos
SET nome = 'X Burguer',
    categoria = 'hamburgers',
    preco = 17.00,
    descricao = 'Pao, carne, alface, queijo, tomate, presunto e ovo.',
    is_active = 1
WHERE LOWER(nome) = 'x burguer';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'X Burguer', 'hamburgers', 17.00, 'Pao, carne, alface, queijo, tomate, presunto e ovo.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'x burguer'
);

-- X Bacon
UPDATE produtos
SET nome = 'X Bacon',
    categoria = 'hamburgers',
    preco = 24.00,
    descricao = 'Pao, carne, queijo, bacon, molho barbecue e ovo.',
    is_active = 1
WHERE LOWER(nome) = 'x bacon';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'X Bacon', 'hamburgers', 24.00, 'Pao, carne, queijo, bacon, molho barbecue e ovo.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'x bacon'
);

-- X Calabresa
UPDATE produtos
SET nome = 'X Calabresa',
    categoria = 'hamburgers',
    preco = 24.00,
    descricao = 'Pao, carne, queijo, alface, tomate, presunto, calabresa e ovo.',
    is_active = 1
WHERE LOWER(nome) = 'x calabresa';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'X Calabresa', 'hamburgers', 24.00, 'Pao, carne, queijo, alface, tomate, presunto, calabresa e ovo.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'x calabresa'
);

-- X Banana
UPDATE produtos
SET nome = 'X Banana',
    categoria = 'hamburgers',
    preco = 24.00,
    descricao = 'Pao, banana, bacon, presunto, queijo, ovo, alface e tomate.',
    is_active = 1
WHERE LOWER(nome) = 'x banana';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'X Banana', 'hamburgers', 24.00, 'Pao, banana, bacon, presunto, queijo, ovo, alface e tomate.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'x banana'
);

-- =========================
-- 2) OUTROS
-- =========================

-- Cachorro-Quente
UPDATE produtos
SET nome = 'Cachorro-Quente',
    categoria = 'outros',
    preco = 13.00,
    descricao = 'Carne moida, salsicha, milho e batata palha.',
    is_active = 1
WHERE LOWER(REPLACE(nome, '-', ' ')) = 'cachorro quente';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Cachorro-Quente', 'outros', 13.00, 'Carne moida, salsicha, milho e batata palha.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(REPLACE(nome, '-', ' ')) = 'cachorro quente'
);

-- Misto-Quente
UPDATE produtos
SET nome = 'Misto-Quente',
    categoria = 'outros',
    preco = 11.00,
    descricao = 'Pao, queijo e presunto.',
    is_active = 1
WHERE LOWER(REPLACE(nome, '-', ' ')) = 'misto quente';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Misto-Quente', 'outros', 11.00, 'Pao, queijo e presunto.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(REPLACE(nome, '-', ' ')) = 'misto quente'
);

COMMIT;

-- Verificacao rapida apos importacao (opcional)
-- SELECT id, nome, categoria, preco, is_active FROM produtos
-- WHERE categoria IN ('hamburgers','outros')
-- ORDER BY categoria, nome;

-- ===== FIM: import_produtos_espetaria_oliveira.sql =====

-- ===== INICIO: import_produtos_bebidas_cervejas_doces_espetaria_oliveira.sql =====
-- Importacao automatica de produtos
-- Bloco: Bebidas, Cervejas e Doces/Balas
-- Compatibilidade: MySQL/MariaDB

SET NAMES utf8mb4;
START TRANSACTION;

-- =========================
-- 1) BEBIDAS
-- =========================

-- Agua Mineral
UPDATE produtos
SET nome = 'Agua Mineral',
    categoria = 'bebidas',
    preco = 3.00,
    descricao = 'Bebida sem gas.',
    is_active = 1
WHERE LOWER(nome) IN ('agua mineral', 'agua');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Agua Mineral', 'bebidas', 3.00, 'Bebida sem gas.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('agua mineral', 'agua')
);

-- H2O
UPDATE produtos
SET nome = 'H2O',
    categoria = 'bebidas',
    preco = 6.00,
    descricao = 'Bebida saborizada.',
    is_active = 1
WHERE LOWER(nome) = 'h2o';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'H2O', 'bebidas', 6.00, 'Bebida saborizada.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'h2o'
);

-- Red Bull
UPDATE produtos
SET nome = 'Red Bull',
    categoria = 'bebidas',
    preco = 12.00,
    descricao = 'Energetico.',
    is_active = 1
WHERE LOWER(nome) = 'red bull';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Red Bull', 'bebidas', 12.00, 'Energetico.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'red bull'
);

-- Extra Power
UPDATE produtos
SET nome = 'Extra Power',
    categoria = 'bebidas',
    preco = 8.00,
    descricao = 'Energetico.',
    is_active = 1
WHERE LOWER(nome) = 'extra power';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Extra Power', 'bebidas', 8.00, 'Energetico.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'extra power'
);

-- Refrigerante 600 ml
UPDATE produtos
SET nome = 'Refrigerante 600 ml',
    categoria = 'bebidas',
    preco = 10.00,
    descricao = 'Volume: 600 ml.',
    is_active = 1
WHERE LOWER(nome) = 'refrigerante 600 ml';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Refrigerante 600 ml', 'bebidas', 10.00, 'Volume: 600 ml.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'refrigerante 600 ml'
);

-- Guarana 1 Litro
UPDATE produtos
SET nome = 'Guarana 1 Litro',
    categoria = 'bebidas',
    preco = 10.00,
    descricao = 'Volume: 1 litro.',
    is_active = 1
WHERE LOWER(nome) IN ('guarana 1 litro', 'guarana 1l');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Guarana 1 Litro', 'bebidas', 10.00, 'Volume: 1 litro.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('guarana 1 litro', 'guarana 1l')
);

-- Coca-Cola 1 Litro
UPDATE produtos
SET nome = 'Coca-Cola 1 Litro',
    categoria = 'bebidas',
    preco = 12.00,
    descricao = 'Volume: 1 litro.',
    is_active = 1
WHERE LOWER(nome) IN ('coca-cola 1 litro', 'coca cola 1 litro', 'coca-cola 1l', 'coca cola 1l');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Coca-Cola 1 Litro', 'bebidas', 12.00, 'Volume: 1 litro.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('coca-cola 1 litro', 'coca cola 1 litro', 'coca-cola 1l', 'coca cola 1l')
);

-- Coca-Cola 2 Litros
UPDATE produtos
SET nome = 'Coca-Cola 2 Litros',
    categoria = 'bebidas',
    preco = 15.00,
    descricao = 'Volume: 2 litros.',
    is_active = 1
WHERE LOWER(nome) IN ('coca-cola 2 litros', 'coca cola 2 litros', 'coca-cola 2l', 'coca cola 2l');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Coca-Cola 2 Litros', 'bebidas', 15.00, 'Volume: 2 litros.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('coca-cola 2 litros', 'coca cola 2 litros', 'coca-cola 2l', 'coca cola 2l')
);

-- Coca-Cola Lata 250 ml
UPDATE produtos
SET nome = 'Coca-Cola Lata 250 ml',
    categoria = 'bebidas',
    preco = 4.00,
    descricao = 'Volume: 250 ml.',
    is_active = 1
WHERE LOWER(nome) = 'coca-cola lata 250 ml' OR LOWER(nome) = 'coca cola lata 250 ml';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Coca-Cola Lata 250 ml', 'bebidas', 4.00, 'Volume: 250 ml.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'coca-cola lata 250 ml' OR LOWER(nome) = 'coca cola lata 250 ml'
);

-- Coca-Cola Lata 350 ml
UPDATE produtos
SET nome = 'Coca-Cola Lata 350 ml',
    categoria = 'bebidas',
    preco = 6.00,
    descricao = 'Volume: 350 ml.',
    is_active = 1
WHERE LOWER(nome) = 'coca-cola lata 350 ml' OR LOWER(nome) = 'coca cola lata 350 ml';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Coca-Cola Lata 350 ml', 'bebidas', 6.00, 'Volume: 350 ml.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'coca-cola lata 350 ml' OR LOWER(nome) = 'coca cola lata 350 ml'
);

-- =========================
-- 2) CERVEJAS (mapeadas em categoria bebidas)
-- =========================

-- Cerveja 250 ml
UPDATE produtos
SET nome = 'Cerveja 250 ml',
    categoria = 'bebidas',
    preco = 5.00,
    descricao = 'Categoria original: Cervejas. Volume: 250 ml.',
    is_active = 1
WHERE LOWER(nome) = 'cerveja 250 ml';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Cerveja 250 ml', 'bebidas', 5.00, 'Categoria original: Cervejas. Volume: 250 ml.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'cerveja 250 ml'
);

-- Cerveja 350 ml
UPDATE produtos
SET nome = 'Cerveja 350 ml',
    categoria = 'bebidas',
    preco = 6.00,
    descricao = 'Categoria original: Cervejas. Volume: 350 ml.',
    is_active = 1
WHERE LOWER(nome) = 'cerveja 350 ml';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Cerveja 350 ml', 'bebidas', 6.00, 'Categoria original: Cervejas. Volume: 350 ml.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'cerveja 350 ml'
);

-- Cerveja Original
UPDATE produtos
SET nome = 'Cerveja Original',
    categoria = 'bebidas',
    preco = 6.00,
    descricao = 'Categoria original: Cervejas.',
    is_active = 1
WHERE LOWER(nome) = 'cerveja original';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Cerveja Original', 'bebidas', 6.00, 'Categoria original: Cervejas.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'cerveja original'
);

-- Cerveja Long Neck
UPDATE produtos
SET nome = 'Cerveja Long Neck',
    categoria = 'bebidas',
    preco = 10.00,
    descricao = 'Categoria original: Cervejas.',
    is_active = 1
WHERE LOWER(nome) IN ('cerveja long neck', 'long neck');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Cerveja Long Neck', 'bebidas', 10.00, 'Categoria original: Cervejas.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('cerveja long neck', 'long neck')
);

-- =========================
-- 3) DOCES / BALAS (mapeados em categoria outros)
-- =========================

-- Pacoca / Pacoca (com ou sem acento)
UPDATE produtos
SET nome = 'Pacoca',
    categoria = 'outros',
    preco = 1.00,
    descricao = 'Categoria original: Doces/Balas.',
    is_active = 1
WHERE LOWER(nome) IN ('pacoca', 'paÃ§oca');

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Pacoca', 'outros', 1.00, 'Categoria original: Doces/Balas.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('pacoca', 'paÃ§oca')
);

-- Tridente
UPDATE produtos
SET nome = 'Tridente',
    categoria = 'outros',
    preco = 3.00,
    descricao = 'Categoria original: Doces/Balas.',
    is_active = 1
WHERE LOWER(nome) = 'tridente';

INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Tridente', 'outros', 3.00, 'Categoria original: Doces/Balas.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) = 'tridente'
);

COMMIT;

-- Verificacao rapida apos importacao (opcional)
-- SELECT id, nome, categoria, preco, is_active
-- FROM produtos
-- WHERE nome IN (
--  'Agua Mineral','H2O','Red Bull','Extra Power',
--  'Refrigerante 600 ml','Guarana 1 Litro',
--  'Coca-Cola 1 Litro','Coca-Cola 2 Litros',
--  'Coca-Cola Lata 250 ml','Coca-Cola Lata 350 ml',
--  'Cerveja 250 ml','Cerveja 350 ml','Cerveja Original','Cerveja Long Neck',
--  'Pacoca','Tridente'
-- )
-- ORDER BY nome;

-- ===== FIM: import_produtos_bebidas_cervejas_doces_espetaria_oliveira.sql =====

-- ===== INICIO: import_produto_pao_de_alho_espetaria_oliveira.sql =====
-- Importacao automatica de produto
-- Item: Pao de Alho
-- Compatibilidade: MySQL/MariaDB

SET NAMES utf8mb4;
START TRANSACTION;

-- Atualiza caso ja exista (com ou sem acento)
UPDATE produtos
SET nome = 'Pao de Alho',
    categoria = 'espetos',
    preco = 6.00,
    descricao = 'Pao de alho assado na brasa.',
    is_active = 1
WHERE LOWER(nome) IN ('pao de alho', 'pÃ£o de alho');

-- Insere caso nao exista
INSERT INTO produtos (nome, categoria, preco, descricao, is_active)
SELECT 'Pao de Alho', 'espetos', 6.00, 'Pao de alho assado na brasa.', 1
WHERE NOT EXISTS (
    SELECT 1 FROM produtos
    WHERE LOWER(nome) IN ('pao de alho', 'pÃ£o de alho')
);

COMMIT;

-- Verificacao opcional
-- SELECT id, nome, categoria, preco, is_active
-- FROM produtos
-- WHERE LOWER(nome) IN ('pao de alho', 'pÃ£o de alho');

-- ===== FIM: import_produto_pao_de_alho_espetaria_oliveira.sql =====

