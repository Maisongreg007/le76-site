<?php
/**
 * Réservations du 76 : résumé du jour pour le brief du matin (lecture seule).
 *
 * GET /api/brief.php?cle=…[&date=AAAA-MM-JJ]
 * Ne renvoie que des chiffres (couverts, tables, demandes en attente) : aucun nom,
 * téléphone ni e-mail de client. Rien ne peut être modifié par ici.
 * La clé n'est pas dans le dépôt, seulement son empreinte SHA-256 ci-dessous.
 */
declare(strict_types=1);

require __DIR__ . '/lib/base.php';

const EMPREINTE_CLE = 'd7359a03daf24eb63f64dd3631be66709f0e5b84c772d5da7ec4fb7752dcda25';
const GRANDE_TABLE = 8;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        erreur('Méthode non autorisée.', 405);
    }
    if (!limiter('brief|' . ip_client(), 30, 3600)) {
        erreur('Trop de demandes, réessaie plus tard.', 429);
    }
    $cle = (string) ($_GET['cle'] ?? '');
    if ($cle === '' || !hash_equals(EMPREINTE_CLE, hash('sha256', $cle))) {
        erreur('Accès refusé.', 403);
    }

    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!date_valide($date)) {
        erreur('Date invalide.');
    }

    $st = db()->prepare("SELECT service, heure, couverts, statut FROM reservations
        WHERE date = ? AND statut IN ('attente','confirmee') ORDER BY heure");
    $st->execute([$date]);
    $resas = $st->fetchAll();

    $services = [];
    foreach (SERVICES as $s) {
        $e = etat_service($date, $s);
        $conf = $att = $nb_conf = $nb_att = 0;
        $grandes = [];
        $premiere = null;
        foreach ($resas as $r) {
            if ($r['service'] !== $s) {
                continue;
            }
            $c = (int) $r['couverts'];
            if ($r['statut'] === 'confirmee') {
                $conf += $c;
                $nb_conf++;
            } else {
                $att += $c;
                $nb_att++;
            }
            $premiere ??= $r['heure'];
            if ($c >= GRANDE_TABLE) {
                $grandes[] = ['heure' => $r['heure'], 'couverts' => $c, 'statut' => $r['statut']];
            }
        }
        $services[$s] = [
            'ouvert' => $e['ouvert'],
            'raison' => $e['raison'],
            'capacite' => $e['capacite'],
            'couverts_confirmes' => $conf,
            'tables_confirmees' => $nb_conf,
            'couverts_en_attente' => $att,
            'demandes_en_attente' => $nb_att,
            'premiere_arrivee' => $premiere,
            'grandes_tables' => $grandes,
            'soiree' => $e['soiree'],
            'menu_impose' => $e['menu_impose'],
            'resa_en_ligne' => $e['en_ligne'],
            'note' => $e['note'],
        ];
    }

    $st = db()->prepare("SELECT COUNT(*) FROM reservations WHERE statut = 'attente' AND date >= ?");
    $st->execute([date('Y-m-d')]);

    header('Cache-Control: no-store');
    repondre([
        'ok' => true,
        'date' => $date,
        'services' => $services,
        'demandes_a_traiter_toutes_dates' => (int) $st->fetchColumn(),
    ]);
} catch (Throwable $e) {
    error_log('[brief] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur('Problème technique.', 500);
}
