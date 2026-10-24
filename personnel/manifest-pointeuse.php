<?php
// Manifeste de l'appli « Pointeuse », différent pour Le 76 et le camping (nom + icône)
declare(strict_types=1);
$e = ($_GET['e'] ?? '') === 'camping' ? 'camping' : 'le76';
$nom = $e === 'camping' ? 'Pointeuse Camping' : 'Pointeuse Le 76';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode([
    'name' => $nom,
    'short_name' => $e === 'camping' ? 'Pointeuse Camping' : 'Pointeuse 76',
    'id' => 'pointeuse-' . $e,
    'start_url' => 'pointeuse.php',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#000000',
    'theme_color' => '#000000',
    'icons' => [
        ['src' => 'assets/icons/pointeuse-' . $e . '-192.png?v=2', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'assets/icons/pointeuse-' . $e . '-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => 'assets/icons/pointeuse-' . $e . '-512.png?v=2', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
