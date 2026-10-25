<?php
// Démo du scan des documents : même page, sans mot de passe ni Google Drive.
declare(strict_types=1);
define('SCAN_DEMO', true);
header('X-Robots-Tag: noindex, nofollow');
require __DIR__ . '/../../scan/demo.php';
if (isset($_GET['reinit'])) {
    require_once __DIR__ . '/../../scan/lib.php';
    demo_session();
    header('Location: ./');
    exit;
}
require __DIR__ . '/../../scan/index.php';
