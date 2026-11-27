<?php
/**
 * Carte publiée, en lecture libre : ?format=json (résumé + liens des images) ou ?page=N (image JPEG de la page N).
 * Sert au site le76.fr et à la préparation des posts Facebook / Instagram.
 */
declare(strict_types=1);

require __DIR__ . '/../api/lib/base.php';
require __DIR__ . '/../api/lib/carte.php';

$n = (int) ($_GET['page'] ?? 0);
if ($n > 0) {
    $f = ca_dossier('publiee') . '/page-' . $n . '.jpg';
    if (!is_file($f)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

$p = ca_publiee();
$pages = [];
if ($p) {
    $nb = count(glob(ca_dossier('publiee') . '/page-*.jpg') ?: []);
    foreach (($p['meta']['pages'] ?? []) as $i => $pg) {
        $pages[] = ['nom' => $pg['nom'] ?? '', 'image' => $i < $nb ? 'https://le76.fr/carte/publiee.php?page=' . ($i + 1) . '&v=' . ($p['publieLe'] ?? 0) : null];
    }
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'publiee' => (bool) $p,
    'publieLe' => $p ? (int) ($p['publieLe'] ?? 0) : null,
    'publieLeIso' => $p ? date('c', (int) (($p['publieLe'] ?? 0) / 1000)) : null,
    'saison' => $p['meta']['saison'] ?? null,
    'pages' => $pages,
    'lien' => 'https://le76.fr/carte/#carte',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
