<?php
// Démo : remet des réservations d'exemple propres
declare(strict_types=1);
define('DEMO', true);
require __DIR__ . '/../../api/lib/base.php';
require_once __DIR__ . '/../../api/lib/demo-resa.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    demo_resa_seed(db(), true);
}
header('Location: ../equipe/');
