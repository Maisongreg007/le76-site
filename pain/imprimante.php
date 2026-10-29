<?php
/**
 * Adresse interrogée par l'imprimante Epson du camping (Server Direct Print).
 *
 * Réglée une fois dans la page de configuration de l'imprimante, avec la clé secrète affichée
 * dans les réglages de l'équipe. Toutes les quelques secondes, l'imprimante :
 *  - demande s'il y a des tickets (ConnectionType=GetRequest) → on lui donne ceux en attente ;
 *  - renvoie le résultat de chaque impression (ConnectionType=SetResponse) → on le note.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const IMP_MAX_ESSAIS = 3;

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Ouverte dans un navigateur : simple explication, sans toucher aux tickets ni à l'état de l'imprimante.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['ConnectionType'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Imprimante</title>'
        . '<body style="font:17px -apple-system,system-ui,sans-serif;max-width:560px;margin:40px auto;padding:0 16px;line-height:1.5">'
        . '<h1 style="font-size:22px">Adresse réservée à l’imprimante</h1>'
        . '<p>Cette adresse ne s’ouvre pas dans un navigateur : elle se colle dans la page de configuration de l’imprimante Epson, rubrique <b>Server Direct Print</b>.</p></body>';
    exit;
}

header('Content-Type: text/xml; charset=utf-8');

try {
    $conf = imprimante_conf();
    if (!hash_equals($conf['cle'], (string) ($_GET['k'] ?? ''))) {
        http_response_code(403);
        exit;
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $conf['vu_le'] = maintenant();
    $nom = trim((string) ($_POST['Name'] ?? $_POST['ID'] ?? ''));
    if ($nom !== '') {
        $conf['nom'] = mb_substr(preg_replace('/[^\w .\-]/u', '', $nom) ?? '', 0, 40);
    }
    imprimante_enregistrer($conf);

    if (($_POST['ConnectionType'] ?? '') === 'SetResponse') {
        foreach (sdp_resultats((string) ($_POST['ResponseFile'] ?? '')) as $job => [$ok, $code]) {
            if ($ok) {
                $pdo->prepare("UPDATE impressions SET statut = 'imprime', fini_le = ?, erreur = '' WHERE job = ?")->execute([maintenant(), $job]);
            } else {
                // Échec (plus de papier, capot ouvert…) : on retente au prochain passage, 3 fois au plus.
                $pdo->prepare("UPDATE impressions SET statut = CASE WHEN essais < ? THEN 'attente' ELSE 'echec' END, fini_le = ?, erreur = ? WHERE job = ?")
                    ->execute([IMP_MAX_ESSAIS, maintenant(), mb_substr((string) $code, 0, 80), $job]);
            }
        }
        $pdo->exec('COMMIT');
        exit;
    }

    // Tickets à donner : en attente, ou envoyés sans réponse depuis 2 minutes (imprimante coupée en route).
    // Rien de plus vieux que 2 jours : une imprimante rallumée après une longue coupure ne ressort pas tout l'historique.
    $st = $pdo->prepare("SELECT * FROM impressions WHERE cree_le >= ? AND essais < ?
        AND (statut = 'attente' OR (statut = 'envoye' AND envoye_le < ?)) ORDER BY id LIMIT 5");
    $st->execute([date('Y-m-d H:i:s', time() - 2 * 86400), IMP_MAX_ESSAIS, date('Y-m-d H:i:s', time() - 120)]);
    $travaux = [];
    $maj = $pdo->prepare("UPDATE impressions SET statut = 'envoye', envoye_le = ?, essais = essais + 1 WHERE id = ?");
    foreach ($st->fetchAll() as $t) {
        $lignes = json_decode((string) $t['lignes'], true);
        if (!is_array($lignes)) {
            continue;
        }
        $maj->execute([maintenant(), $t['id']]);
        $travaux[] = ['job' => $t['job'], 'lignes' => $lignes];
    }
    $pdo->exec('COMMIT');
    echo sdp_travaux($travaux);
} catch (Throwable $e) {
    error_log('pain imprimante: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    http_response_code(500);
}
