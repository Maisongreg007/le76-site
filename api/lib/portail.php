<?php
/**
 * Portail du 76 (/admin) : le résumé du jour affiché en haut du portail.
 * Appelé par api/equipe.php (action « portail ») une fois la connexion vérifiée :
 * mêmes comptes et même session que l'appli des réservations.
 * Lecture seule : le portail ne modifie aucune donnée.
 */
declare(strict_types=1);

require_once __DIR__ . '/club.php';

function portail_resume(array $moi): array
{
    $auj = date('Y-m-d');
    return [
        'moi' => ['nom' => $moi['nom'], 'admin' => (bool) $moi['admin']],
        'date' => $auj,
        'resa' => portail_resa($auj),
        'club' => portail_club($auj),
        'pain' => portail_pain($auj),
        'cout' => portail_cout(),
        'demo' => defined('DEMO'),
    ];
}

/** Réservations du jour (couverts confirmés + en attente) et demandes à valider. */
function portail_resa(string $auj): ?array
{
    try {
        $st = db()->prepare("SELECT service, statut, SUM(couverts) AS c, COUNT(*) AS n FROM reservations
            WHERE date = ? AND statut IN ('attente','confirmee') GROUP BY service, statut");
        $st->execute([$auj]);
        $o = ['midi' => 0, 'soir' => 0, 'tables' => 0];
        foreach ($st as $l) {
            $o[$l['service']] += (int) $l['c'];
            $o['tables'] += (int) $l['n'];
        }
        $st = db()->prepare("SELECT COUNT(*) FROM reservations WHERE statut = 'attente' AND date >= ?");
        $st->execute([$auj]);
        $o['demandes'] = (int) $st->fetchColumn();
        foreach (SERVICES as $s) {
            $e = etat_service($auj, $s);
            $o['ouvert_' . $s] = (bool) $e['ouvert'];
            $o['capacite_' . $s] = (int) $e['capacite'];
        }
        return $o;
    } catch (Throwable $e) {
        error_log('[portail] résa : ' . $e->getMessage());
        return null;
    }
}

/** Club : visites du jour, cadeaux à offrir, membres. */
function portail_club(string $auj): ?array
{
    try {
        $pdo = club_db();
        $seuil = (int) club_reglages()['seuil'];
        $dispo = 0;
        foreach ($pdo->query('SELECT (SELECT COUNT(*) FROM club_visites v WHERE v.membre_id = m.id) AS nb, (SELECT COUNT(*) FROM club_cadeaux c WHERE c.membre_id = m.id) AS cad FROM club_membres m') as $x) {
            $dispo += max(0, intdiv((int) $x['nb'], $seuil) - (int) $x['cad']);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM club_visites WHERE jour = ?');
        $st->execute([$auj]);
        return [
            'visites' => (int) $st->fetchColumn(),
            'cadeaux' => $dispo,
            'membres' => (int) $pdo->query('SELECT COUNT(*) FROM club_membres')->fetchColumn(),
            'en_attente' => (int) $pdo->query("SELECT COUNT(*) FROM club_envois WHERE statut = 'attente'")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        error_log('[portail] club : ' . $e->getMessage());
        return null;
    }
}

/** Pain du camping (base séparée, ouverte en lecture seule) : commandes à retirer aujourd'hui et demain. */
function portail_pain(string $auj): ?array
{
    $web = dirname(__DIR__, 2);
    $candidats = defined('DEMO')
        ? [dirname($web) . '/le76-pain-demo/pain.sqlite', "$web/pain/donnees-demo/pain.sqlite"]
        : [dirname($web) . '/le76-pain/pain.sqlite', "$web/pain/donnees/pain.sqlite"];
    foreach ($candidats as $f) {
        if (!is_file($f)) {
            continue;
        }
        try {
            $pdo = new PDO('sqlite:' . $f, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $demain = date('Y-m-d', strtotime('+1 day'));
            $st = $pdo->prepare("SELECT jour, COUNT(*) AS n, SUM(CASE WHEN statut = 'collected' THEN 1 ELSE 0 END) AS retirees
                FROM commandes WHERE jour IN (?, ?) AND statut IN ('pending','confirmed','collected') GROUP BY jour");
            $st->execute([$auj, $demain]);
            $o = ['aujourdhui' => 0, 'retirees' => 0, 'demain' => 0];
            foreach ($st as $l) {
                if ($l['jour'] === $auj) {
                    $o['aujourdhui'] = (int) $l['n'];
                    $o['retirees'] = (int) $l['retirees'];
                } else {
                    $o['demain'] = (int) $l['n'];
                }
            }
            return $o;
        } catch (Throwable $e) {
            error_log('[portail] pain : ' . $e->getMessage());
            return null;
        }
    }
    return null; // pas de base du pain : hors saison ou pas encore installé
}

/** Coût matière : factures reçues à vérifier (base séparée, lecture seule). */
function portail_cout(): ?array
{
    $f = dossier_donnees() . '/cout-matiere/cout-matiere.sqlite';
    if (!is_file($f)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $n = 0;
        foreach ($pdo->query("SELECT data FROM docs WHERE coll = 'factures' AND data IS NOT NULL") as $l) {
            if ((json_decode($l['data'], true)['statut'] ?? '') === 'a_valider') {
                $n++;
            }
        }
        return ['a_verifier' => $n];
    } catch (Throwable $e) {
        error_log('[portail] coût matière : ' . $e->getMessage());
        return null;
    }
}
