<?php
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=300');

echo json_encode([
    'id' => './',
    'name' => 'Sistema de Comandas - Espetaria Oliveira',
    'short_name' => 'Espetaria',
    'description' => 'Sistema de comandas para atendimento mobile da Espetaria Oliveira.',
    'start_url' => './index-mobile.html',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#0f0f0f',
    'theme_color' => '#c62828',
    'lang' => 'pt-BR',
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => 'icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => 'icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);