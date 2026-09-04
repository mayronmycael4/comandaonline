<?php
// api_cardapio.php - API para cardápio mobile (produtos e categorias)
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Retorna categorias e produtos ativos
        $categorias = [
            ['id' => 'lanches', 'nome' => 'Lanches'],
            ['id' => 'bebidas', 'nome' => 'Bebidas'],
            ['id' => 'porcoes', 'nome' => 'Porções'],
            ['id' => 'acompanhamentos', 'nome' => 'Acompanhamentos']
        ];
        $stmt = $pdo->query("SELECT id, nome, categoria, preco, descricao, imagem_url FROM produtos WHERE is_active = 1");
        $produtos = $stmt->fetchAll();
        jsonResponse([
            'categorias' => $categorias,
            'produtos' => $produtos
        ]);
        break;
    default:
        jsonResponse(['error' => 'Método não permitido'], 405);
}
