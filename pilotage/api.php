<?php
/**
 * Pilotage du camping : point d'entrée unique, POST JSON {a: "action", ...}.
 * Réservé aux responsables connectés au portail (/admin), même session.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

function pl_repondre(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (PL_DEMO) {
        pl_demo_preparer();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        pl_repondre(['ok' => false, 'erreur' => 'Méthode non autorisée.'], 405);
    }
    $q = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($q)) {
        throw new PlErreur('Requête illisible.');
    }
    $moi = pl_utilisateur();
    if (!$moi) {
        pl_repondre(['ok' => false, 'connexion' => true, 'erreur' => 'Connecte-toi au portail du 76 pour continuer.'], 401);
    }
    if (!$moi['admin']) {
        pl_repondre(['ok' => false, 'erreur' => 'Le pilotage est réservé aux responsables.'], 403);
    }
    $a = (string) ($q['a'] ?? '');

    switch ($a) {
        case 'jour':
            $j = pl_date_ok($q['jour'] ?? null) ? (string) $q['jour'] : date('Y-m-d');
            pl_relever_z(false); // au plus toutes les 30 minutes
            $calc = pl_calculer($j, $j, pl_cumul_avant($j));
            // Demain : ce que coûte le planning, pour vérifier avant qu'il soit trop tard
            $dem = date('Y-m-d', strtotime($j . ' +1 day'));
            $demain = pl_calculer($dem, $dem)[$dem];
            // CA moyen des 4 derniers mêmes jours de la semaine, pour le budget d'heures de demain
            $ref = [];
            for ($k = 1; $k <= 4; $k++) {
                $x = date('Y-m-d', strtotime($dem . " -$k week"));
                $c = pl_calculer($x, $x)[$x];
                if ($c['ca_source']) {
                    $ref[] = $c['ca_resto_ht'];
                }
            }
            $r = pl_reglages();
            $caRef = $ref ? array_sum($ref) / count($ref) : null;
            pl_repondre(['ok' => true, 'moi' => $moi, 'demo' => PL_DEMO, 'aujourdhui' => date('Y-m-d'), 'jour' => $calc[$j],
                'demain' => [
                    'jour' => $dem,
                    'heures' => round($demain['personnel_min'] / 60, 1),
                    'cout' => $demain['personnel'],
                    'ca_reference' => $caRef !== null ? round($caRef, 2) : null,
                    'heures_conseillees' => $caRef !== null ? round(($caRef * $r['budget_perso'] / 100) / (12 * $r['coef_employeur']) + 3, 1) : null,
                ],
                'meteo' => PL_METEO, 'z_msg' => $r['z_msg']]);

        case 'saisir':
            $j = (string) ($q['jour'] ?? '');
            pl_saisie_ecrire($j, is_array($q['valeurs'] ?? null) ? $q['valeurs'] : []);
            pl_repondre(['ok' => true, 'jour' => pl_calculer($j, $j, pl_cumul_avant($j))[$j]]);

        case 'saison':
            pl_repondre(['ok' => true, 'demo' => PL_DEMO] + pl_saison());

        case 'reglages':
            pl_repondre(['ok' => true, 'demo' => PL_DEMO, 'reglages' => pl_reglages_vue()]);

        case 'reglages_ecrire':
            if (PL_DEMO) {
                throw new PlErreur('Démo : les réglages ne sont pas modifiables.');
            }
            pl_repondre(['ok' => true, 'reglages' => pl_reglages_ecrire(is_array($q['reglages'] ?? null) ? $q['reglages'] : [])]);

        case 'relever_z':
            pl_repondre(['ok' => true] + pl_relever_z(true));

        default:
            throw new PlErreur('Action inconnue.');
    }
} catch (PlErreur $e) {
    pl_repondre(['ok' => false, 'erreur' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[pilotage] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    pl_repondre(['ok' => false, 'erreur' => 'Erreur du serveur. Réessaie dans un instant.'], 500);
}
