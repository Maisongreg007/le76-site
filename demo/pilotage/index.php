<?php
// Démo du pilotage : même écran que le76.fr/pilotage, branché sur l'API de démo du dossier courant.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
readfile(__DIR__ . '/../../pilotage/index.html');
