-- Adiciona coluna de imagem_url em produtos, se não existir
ALTER TABLE produtos ADD COLUMN IF NOT EXISTS imagem_url VARCHAR(255) NULL AFTER descricao;
