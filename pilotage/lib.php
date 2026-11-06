<?php
/**
 * Pilotage du restaurant du camping (le76.fr/pilotage) : « est-ce que je gagne de l'argent aujourd'hui ? »
 *
 * Ce module ne remplace aucun outil : il LIT les autres en lecture seule et fait le calcul.
 *  - Pain du camping (le76.fr/pain)            → CA et coût du pain, par jour de retrait
 *  - CAMPING OS 76 (le76.fr/camping)           → commandes en golfette payées, par jour
 *  - Personnel (le76.fr/personnel, site camping) → heures pointées (passé) ou prévues (à venir)
 *  - Z quotidien de L'Addition du camping      → CA du restaurant, relevé dans une boîte mail dédiée
 * Ce qu'aucun outil ne connaît (petits-déj, météo, remplissage du camping, animation) se saisit ici.
 *
 * Données propres au pilotage : une petite base SQLite rangée à côté de public_html (le76-pilotage).
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

define('PL_DEMO', defined('PILOTAGE_DEMO'));
define('PL_WEB', dirname(__DIR__)); // public_html

const PL_REGLAGES = [
    'saison_debut' => '2027-04-01',
    'saison_fin' => '2027-09-08',
    'coef_employeur' => 1.67,     // 12 € net de l'heure ≈ 20 € de coût réel (charges + congés payés)
    'food_cost' => 28,            // % du CA HT du restaurant (matière + emballages)
    'part_alcool' => 17,          // % du CA TTC à 20 % de TVA, quand le CA est saisi à la main
    'loyer_pct' => 10,            // % du CA HT du restaurant (pain et petit-déj hors loyer)
    'loyer_min' => 22000,         // minimum garanti sur la saison, € HT
    'frais_carte' => 1.5,         // % du CA TTC (cartes, chèques vacances)
    'fixes_jour' => 65,           // assurances, comptable, entretien, obligations du bail… ÷ jours
    'budget_perso' => 33,         // % du CA HT du restaurant à ne pas dépasser en personnel
    'objectif_saison' => 59220,   // scénario réaliste du prévisionnel
    'pdj_classique' => 8.5,
    'pdj_familial' => 16.9,
    'pdj_food' => 26,             // % matière du petit-déj
    'golfette_dans_caisse' => 1,  // 1 = les commandes golfette sont aussi encaissées dans L'Addition (pas d'ajout)
    'z_hote' => 'imap.hostinger.com',
    'z_user' => 'camping@le76.fr',
    'z_mdp' => '',
    'z_derniere' => '',
    'z_msg' => '',
];

const PL_METEO = ['soleil' => 'Soleil', 'nuageux' => 'Nuageux', 'pluie' => 'Pluie', 'orage' => 'Orage', 'canicule' => 'Canicule'];

if (!function_exists('maintenant')) {
    function maintenant(): string
    {
        return date('Y-m-d H:i:s');
    }
}

final class PlErreur extends RuntimeException
{
}

/* ------------------------------------------------------------------ */
/* Base propre au pilotage                                             */
/* ------------------------------------------------------------------ */

function pl_dossier(): string
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $candidats = PL_DEMO
        ? [dirname(PL_WEB) . '/le76-pilotage-demo', __DIR__ . '/donnees-demo']
        : [dirname(PL_WEB) . '/le76-pilotage', __DIR__ . '/donnees'];
    foreach ($candidats as $c) {
        if (!is_dir($c)) {
            @mkdir($c, 0750, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, __DIR__) && !is_file("$c/.htaccess")) {
                file_put_contents("$c/.htaccess", "Require all denied\n");
            }
            return $d = $c;
        }
    }
    throw new RuntimeException('Impossible de créer le dossier des données du pilotage.');
}

function pl_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . pl_dossier() . '/pilotage.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reglages (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS jours (jour TEXT PRIMARY KEY, data TEXT NOT NULL, modifie_le TEXT NOT NULL)');
    return $pdo;
}

function pl_reglages(): array
{
    $r = PL_REGLAGES;
    foreach (pl_db()->query('SELECT cle, valeur FROM reglages') as $l) {
        if (array_key_exists($l['cle'], $r)) {
            $r[$l['cle']] = is_string(PL_REGLAGES[$l['cle']]) ? $l['valeur'] : (float) $l['valeur'];
        }
    }
    return $r;
}

function pl_reglage_ecrire(string $cle, string|float|int $v): void
{
    pl_db()->prepare('INSERT OR REPLACE INTO reglages (cle, valeur) VALUES (?, ?)')->execute([$cle, (string) $v]);
}

/** Enregistre les réglages modifiables depuis l'écran (le mot de passe mail seulement s'il est fourni). */
function pl_reglages_ecrire(array $in): array
{
    $nombres = ['coef_employeur' => [1, 3], 'food_cost' => [5, 60], 'part_alcool' => [0, 80], 'loyer_pct' => [0, 30],
        'loyer_min' => [0, 200000], 'frais_carte' => [0, 10], 'fixes_jour' => [0, 2000], 'budget_perso' => [10, 80],
        'objectif_saison' => [0, 1000000], 'pdj_classique' => [0, 50], 'pdj_familial' => [0, 80], 'pdj_food' => [0, 80],
        'golfette_dans_caisse' => [0, 1]];
    foreach ($nombres as $k => [$min, $max]) {
        if (!isset($in[$k]) || $in[$k] === '') {
            continue;
        }
        $v = (float) str_replace(',', '.', (string) $in[$k]);
        if ($v < $min || $v > $max) {
            throw new PlErreur('Valeur hors limites pour « ' . $k . ' ».');
        }
        pl_reglage_ecrire($k, $v);
    }
    foreach (['saison_debut', 'saison_fin'] as $k) {
        if (isset($in[$k]) && $in[$k] !== '') {
            if (!pl_date_ok($in[$k])) {
                throw new PlErreur('Date de saison invalide.');
            }
            pl_reglage_ecrire($k, (string) $in[$k]);
        }
    }
    foreach (['z_hote', 'z_user'] as $k) {
        if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') {
            pl_reglage_ecrire($k, mb_substr(trim($in[$k]), 0, 120));
        }
    }
    if (isset($in['z_mdp']) && is_string($in['z_mdp']) && $in['z_mdp'] !== '') {
        pl_reglage_ecrire('z_mdp', $in['z_mdp']);
    }
    return pl_reglages_vue();
}

/** Réglages tels que l'écran les voit (sans le mot de passe). */
function pl_reglages_vue(): array
{
    $r = pl_reglages();
    $r['z_mdp'] = $r['z_mdp'] !== '';
    return $r;
}

function pl_date_ok(mixed $d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1 && strtotime($d) !== false;
}

function pl_saisie(string $jour): array
{
    $st = pl_db()->prepare('SELECT data FROM jours WHERE jour = ?');
    $st->execute([$jour]);
    $d = $st->fetchColumn();
    return $d ? (json_decode((string) $d, true) ?: []) : [];
}

function pl_saisies(string $du, string $au): array
{
    $st = pl_db()->prepare('SELECT jour, data FROM jours WHERE jour BETWEEN ? AND ?');
    $st->execute([$du, $au]);
    $o = [];
    foreach ($st as $l) {
        $o[$l['jour']] = json_decode((string) $l['data'], true) ?: [];
    }
    return $o;
}

/** Enregistre ce qui se saisit à la main pour un jour (fusion avec l'existant). */
function pl_saisie_ecrire(string $jour, array $in): array
{
    if (!pl_date_ok($jour)) {
        throw new PlErreur('Date invalide.');
    }
    $d = pl_saisie($jour);
    $num = function (string $k, float $max) use ($in, &$d): void {
        if (!array_key_exists($k, $in)) {
            return;
        }
        if ($in[$k] === '' || $in[$k] === null) {
            unset($d[$k]);
            return;
        }
        $v = (float) str_replace([',', ' '], ['.', ''], (string) $in[$k]);
        if ($v < 0 || $v > $max) {
            throw new PlErreur('Montant ou quantité hors limites.');
        }
        $d[$k] = round($v, 2);
    };
    $num('ca_ttc', 100000);     // CA restaurant TTC, si pas de Z
    $num('ca_ht', 100000);      // CA restaurant HT, si connu
    $num('pdj_classique', 500);
    $num('pdj_familial', 500);
    $num('occupation', 100);    // % de remplissage du camping
    if (array_key_exists('meteo', $in)) {
        $d['meteo'] = isset(PL_METEO[$in['meteo']]) ? $in['meteo'] : null;
    }
    if (array_key_exists('animation', $in)) {
        $d['animation'] = mb_substr(trim((string) $in['animation']), 0, 80);
    }
    if (array_key_exists('note', $in)) {
        $d['note'] = mb_substr(trim((string) $in['note']), 0, 300);
    }
    pl_db()->prepare('INSERT OR REPLACE INTO jours (jour, data, modifie_le) VALUES (?, ?, ?)')
        ->execute([$jour, json_encode($d, JSON_UNESCAPED_UNICODE), maintenant()]);
    return $d;
}

/* ------------------------------------------------------------------ */
/* Lecture des autres outils (lecture seule)                           */
/* ------------------------------------------------------------------ */

function pl_ouvrir(array $candidats): ?PDO
{
    foreach ($candidats as $f) {
        if (!is_file($f)) {
            continue;
        }
        try {
            $pdo = new PDO('sqlite:' . $f, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return $pdo;
        } catch (Throwable $e) {
            error_log('[pilotage] ' . $f . ' : ' . $e->getMessage());
        }
    }
    return null;
}

/** Pain du camping : [jour => ['ttc' => €, 'cout' => €, 'pieces' => n, 'commandes' => n]]. Commandes payées seulement. */
function pl_pain(string $du, string $au): ?array
{
    $pdo = pl_ouvrir([dirname(PL_WEB) . '/le76-pain/pain.sqlite', PL_WEB . '/pain/donnees/pain.sqlite']);
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT c.jour, COUNT(DISTINCT c.id) AS n, SUM(l.prix * l.quantite) AS ttc, SUM(l.cout * l.quantite) AS cout, SUM(l.quantite) AS pieces
            FROM commandes c JOIN lignes l ON l.commande_id = c.id
            WHERE c.jour BETWEEN ? AND ? AND c.statut IN ('confirmed','collected') GROUP BY c.jour");
        $st->execute([$du, $au]);
        $o = [];
        foreach ($st as $l) {
            $o[$l['jour']] = ['ttc' => (int) $l['ttc'] / 100, 'cout' => (int) $l['cout'] / 100, 'pieces' => (int) $l['pieces'], 'commandes' => (int) $l['n']];
        }
        return $o;
    } catch (Throwable $e) {
        error_log('[pilotage] pain : ' . $e->getMessage());
        return null;
    }
}

/** CAMPING OS 76 : commandes payées (livrées ou retirées), par jour de paiement. */
function pl_golfette(string $du, string $au): ?array
{
    $pdo = pl_ouvrir([dirname(PL_WEB) . '/le76-camping/camping.sqlite', PL_WEB . '/camping/donnees/camping.sqlite']);
    if (!$pdo) {
        return null;
    }
    try {
        $o = [];
        $paris = new DateTimeZone('Europe/Paris');
        foreach ($pdo->query("SELECT data FROM docs WHERE col = 'orders'") as $l) {
            $c = json_decode((string) $l['data'], true);
            if (!is_array($c) || empty($c['paid']['at'])) {
                continue;
            }
            $j = (new DateTimeImmutable((string) $c['paid']['at']))->setTimezone($paris)->format('Y-m-d');
            if ($j < $du || $j > $au) {
                continue;
            }
            $o[$j] ??= ['ttc' => 0.0, 'commandes' => 0, 'livrees' => 0];
            $o[$j]['ttc'] += (int) ($c['paid']['amount'] ?? $c['total'] ?? 0) / 100;
            $o[$j]['commandes']++;
            $o[$j]['livrees'] += ($c['mode'] ?? '') === 'delivery' ? 1 : 0;
        }
        return $o;
    } catch (Throwable $e) {
        error_log('[pilotage] golfette : ' . $e->getMessage());
        return null;
    }
}

/**
 * Personnel du camping, en NET (comme l'outil Personnel) : [jour => ['min' => minutes, 'net' => €, 'source' => reel|prevu]].
 * Jours passés : heures pointées. Aujourd'hui : le plus grand de pointé et prévu (la journée n'est pas finie). À venir : planning.
 */
function pl_personnel(string $du, string $au): ?array
{
    $lib = PL_WEB . '/personnel/lib.php';
    if (!is_file($lib)) {
        return null;
    }
    try {
        require_once $lib;
        $c = couts_jours($du, $au, 'camping');
        $o = [];
        foreach ($c as $j => $x) {
            $o[$j] = ['min' => $x['fixes_min'] + $x['extras_min'], 'net' => $x['fixes_eur'] + $x['extras_eur'], 'source' => $x['source']];
        }
        $auj = date('Y-m-d');
        if (isset($o[$auj])) {
            $p = pl_personnel_prevu($auj);
            if ($p['net'] > $o[$auj]['net']) {
                $o[$auj] = $p + ['source' => 'prevu'];
            }
        }
        return $o;
    } catch (Throwable $e) {
        error_log('[pilotage] personnel : ' . $e->getMessage());
        return null;
    }
}

/** Ce que coûte le planning d'un jour (camping), hors congés et maladies. */
function pl_personnel_prevu(string $jour): array
{
    $emps = [];
    foreach (employes(false) as $e) {
        $emps[(int) $e['id']] = $e;
    }
    $st = db()->prepare("SELECT * FROM planning WHERE jour = ? AND etab = 'camping'");
    $st->execute([$jour]);
    $iv = [];
    foreach ($st->fetchAll() as $s) {
        if (in_array($s['motif'], ['malade', 'conge'], true)) {
            continue;
        }
        $iv[(int) $s['employe_id']][] = shift_bounds($s);
    }
    $min = 0;
    $net = 0.0;
    foreach ($iv as $id => $l) {
        $e = $emps[$id] ?? null;
        if (!$e) {
            continue;
        }
        $m = minutes_union($l);
        $min += $m;
        $net += $e['type'] === 'extra' ? $m / 60 * (float) $e['taux'] : $m / 60 * taux_horaire_fixe($e);
    }
    return ['min' => $min, 'net' => $net];
}

/* ------------------------------------------------------------------ */
/* Z quotidien de L'Addition (compte du camping)                       */
/* ------------------------------------------------------------------ */

/**
 * Relève les Z du camping dans la boîte dédiée (même lecteur que le coût matière du 76).
 * Au plus toutes les 30 minutes, sauf demande explicite.
 */
function pl_relever_z(bool $forcer = false): array
{
    if (PL_DEMO) {
        return ['releve' => false, 'message' => 'Démo : pas de boîte mail reliée.'];
    }
    $r = pl_reglages();
    if ($r['z_mdp'] === '') {
        return ['releve' => false, 'message' => 'Indique le mot de passe de la boîte ' . $r['z_user'] . ' dans les Réglages.'];
    }
    if (!$forcer && $r['z_derniere'] !== '' && strtotime((string) $r['z_derniere']) > time() - 1800) {
        return ['releve' => false, 'message' => (string) $r['z_msg']];
    }
    require_once PL_WEB . '/api/lib/cout-matiere.php';
    pl_reglage_ecrire('z_derniere', maintenant());
    @set_time_limit(120);
    $nouveaux = 0;
    try {
        $fp = cm_imap_ouvrir((string) $r['z_hote'], (string) $r['z_user'], (string) $r['z_mdp']);
        [, $rep] = cm_imap_cmd($fp, 'UID SEARCH SINCE ' . date('d-M-Y', strtotime('-60 days')) . ' TEXT "Z quotidien"');
        $uids = preg_match('/\* SEARCH([\d ]*)/', $rep, $m) ? array_filter(array_map('intval', explode(' ', trim($m[1])))) : [];
        foreach (array_reverse($uids) as $uid) {
            [, $t] = cm_imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[HEADER.FIELDS (SUBJECT)])");
            $sujet = preg_match('/Subject:\s*(.+)/i', $t, $m) ? (string) iconv_mime_decode(trim($m[1]), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : '';
            if (!$forcer && preg_match('/(\d{4}-\d{2}-\d{2})\)/', $sujet, $m) && !empty(pl_saisie($m[1])['z'])) {
                continue;
            }
            [, $b] = cm_imap_cmd($fp, "UID FETCH $uid (BODY.PEEK[])");
            $p = strpos($b, "}\r\n");
            if ($p === false) {
                continue;
            }
            foreach (cm_mime_messages(substr($b, $p + 3)) as $msg) {
                $z = cm_z_lire($msg['html'], $msg['texte'], $msg['sujet'] ?: $sujet);
                if (!$z || $z['ca_ttc'] <= 0) {
                    continue;
                }
                $d = pl_saisie($z['date']);
                $neuf = empty($d['z']);
                unset($z['lignes']);
                $d['z'] = $z;
                pl_db()->prepare('INSERT OR REPLACE INTO jours (jour, data, modifie_le) VALUES (?, ?, ?)')
                    ->execute([$z['date'], json_encode($d, JSON_UNESCAPED_UNICODE), maintenant()]);
                $nouveaux += $neuf ? 1 : 0;
            }
        }
        cm_imap_cmd($fp, 'LOGOUT');
        fclose($fp);
        $msg = $nouveaux ? $nouveaux . ' Z ajouté' . ($nouveaux > 1 ? 's' : '') . '.' : 'Aucun nouveau Z.';
    } catch (Throwable $e) {
        pl_reglage_ecrire('z_msg', $e->getMessage());
        return ['releve' => false, 'message' => $e->getMessage()];
    }
    pl_reglage_ecrire('z_msg', $msg . ' (' . date('d/m H:i') . ')');
    return ['releve' => true, 'nouveaux' => $nouveaux, 'message' => $msg];
}

/* ------------------------------------------------------------------ */
/* Le calcul                                                           */
/* ------------------------------------------------------------------ */

function pl_jours_saison(array $r): int
{
    return max(1, (int) round((strtotime((string) $r['saison_fin']) - strtotime((string) $r['saison_debut'])) / 86400) + 1);
}

/**
 * Le résultat de chaque jour entre deux dates.
 * $cumulAvant : CA HT du restaurant déjà fait dans la saison avant $du (pour le loyer au-delà du minimum).
 */
function pl_calculer(string $du, string $au, float $cumulAvant = 0.0): array
{
    $r = pl_reglages();
    $sources = PL_DEMO ? pl_demo_sources($du, $au) : [
        'pain' => pl_pain($du, $au),
        'golfette' => pl_golfette($du, $au),
        'personnel' => pl_personnel($du, $au),
    ];
    $saisies = pl_saisies($du, $au);
    $nbJours = pl_jours_saison($r);
    $seuilLoyer = $r['loyer_pct'] > 0 ? $r['loyer_min'] / ($r['loyer_pct'] / 100) : INF;
    $cumul = $cumulAvant;
    $o = [];
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        $s = $saisies[$j] ?? [];
        $pain = $sources['pain'][$j] ?? null;
        $golf = $sources['golfette'][$j] ?? null;
        $pers = $sources['personnel'][$j] ?? null;

        // CA du restaurant : le Z de L'Addition d'abord, sinon la saisie
        $caSource = null;
        $caTtc = $caHt = 0.0;
        if (!empty($s['z']['ca_ttc'])) {
            $caTtc = (float) $s['z']['ca_ttc'];
            $caHt = (float) ($s['z']['ca_ht'] ?: $caTtc / 1.117);
            $caSource = 'z';
        } elseif (isset($s['ca_ttc']) || isset($s['ca_ht'])) {
            $alc = $r['part_alcool'] / 100;
            $caTtc = (float) ($s['ca_ttc'] ?? 0);
            $caHt = isset($s['ca_ht']) ? (float) $s['ca_ht'] : $caTtc * (1 - $alc) / 1.10 + $caTtc * $alc / 1.20;
            if (!isset($s['ca_ttc'])) {
                $caTtc = $caHt * 1.117;
            }
            $caSource = 'saisie';
        }
        $golfTtc = $golf['ttc'] ?? 0.0;
        if ($golfTtc > 0 && !$r['golfette_dans_caisse']) {
            $caTtc += $golfTtc;
            $caHt += $golfTtc / 1.10;
        }

        $painTtc = $pain['ttc'] ?? 0.0;
        $painHt = $painTtc / 1.055;
        $painCout = $pain['cout'] ?? 0.0;
        $pdjTtc = ($s['pdj_classique'] ?? 0) * $r['pdj_classique'] + ($s['pdj_familial'] ?? 0) * $r['pdj_familial'];
        $pdjHt = $pdjTtc / 1.10;

        $caTotalHt = $caHt + $painHt + $pdjHt;
        $caTotalTtc = $caTtc + $painTtc + $pdjTtc;
        $matiere = $caHt * $r['food_cost'] / 100;
        $pdjMat = $pdjHt * $r['pdj_food'] / 100;
        $persMin = (int) ($pers['min'] ?? 0);
        $persCout = (float) ($pers['net'] ?? 0) * $r['coef_employeur'];

        // Loyer : la part quotidienne du minimum, plus 10 % de ce qui dépasse le seuil (220 000 € par défaut)
        $part = $r['loyer_min'] / $nbJours;
        $avant = $cumul;
        $cumul += $caHt;
        $auDela = max(0.0, $cumul - max($avant, $seuilLoyer));
        $loyer = ($j >= $r['saison_debut'] && $j <= $r['saison_fin'] ? $part : 0.0) + $auDela * $r['loyer_pct'] / 100;

        $carte = $caTotalTtc * $r['frais_carte'] / 100;
        $fixes = ($j >= $r['saison_debut'] && $j <= $r['saison_fin']) ? (float) $r['fixes_jour'] : 0.0;
        $resultat = $caTotalHt - $matiere - $painCout / 1.055 - $pdjMat - $persCout - $loyer - $carte - $fixes;

        $objectifJour = $r['objectif_saison'] / $nbJours;
        $ratioPers = $caTotalHt > 0 ? $persCout / $caTotalHt * 100 : null;
        $budgetH = $caHt > 0 && $r['coef_employeur'] > 0
            ? ($caHt * $r['budget_perso'] / 100) / (12 * $r['coef_employeur']) + ($painHt > 0 ? 3 : 0) : null;

        $vide = !$caSource && !$painTtc && !$pdjTtc && !$persMin;
        $o[$j] = [
            'jour' => $j,
            'vide' => $vide,
            'ca_source' => $caSource,
            'ca_resto_ttc' => round($caTtc, 2), 'ca_resto_ht' => round($caHt, 2),
            'golfette_ttc' => round($golfTtc, 2), 'golfette_commandes' => $golf['commandes'] ?? 0, 'golfette_livrees' => $golf['livrees'] ?? 0,
            'pain_ttc' => round($painTtc, 2), 'pain_ht' => round($painHt, 2), 'pain_cout' => round($painCout, 2), 'pain_pieces' => $pain['pieces'] ?? 0, 'pain_commandes' => $pain['commandes'] ?? 0,
            'pdj_ht' => round($pdjHt, 2),
            'ca_ht' => round($caTotalHt, 2),
            'matiere' => round($matiere + $pdjMat, 2),
            'pain_achat' => round($painCout / 1.055, 2),
            'personnel' => round($persCout, 2), 'personnel_min' => $persMin, 'personnel_source' => $pers['source'] ?? null,
            'loyer' => round($loyer, 2), 'carte' => round($carte, 2), 'fixes' => round($fixes, 2),
            'resultat' => round($resultat, 2),
            'ratio_personnel' => $ratioPers !== null ? round($ratioPers, 1) : null,
            'heures_budget' => $budgetH !== null ? round($budgetH, 1) : null,
            'objectif' => round($objectifJour, 2),
            'etat' => $vide ? 'vide' : (!$caSource && $j >= date('Y-m-d') ? 'en_cours' : ($resultat < 0 ? 'rouge' : ($resultat < $objectifJour ? 'orange' : 'vert'))),
            'saisie' => array_diff_key($s, ['z' => 1]),
            'z' => $s['z'] ?? null,
            'sources' => [
                'pain' => $sources['pain'] !== null, 'golfette' => $sources['golfette'] !== null, 'personnel' => $sources['personnel'] !== null,
            ],
        ];
        $o[$j]['alertes'] = pl_alertes($o[$j], $r);
    }
    return $o;
}

function pl_alertes(array $d, array $r): array
{
    if ($d['vide']) {
        return [];
    }
    $a = [];
    if ($d['etat'] === 'en_cours') {
        $a[] = ['', 'Journée en cours : le CA du restaurant arrivera avec le Z de L\'Addition après la fermeture (ou saisis-le).'];
    }
    $h = fn(float $m) => rtrim(rtrim(number_format($m, 1, ',', ' '), '0'), ',') . ' h';
    if ($d['ratio_personnel'] !== null && $d['ratio_personnel'] > 40) {
        $a[] = ['rouge', 'Personnel à ' . round($d['ratio_personnel']) . ' % du CA : trop d\'heures pour ce niveau d\'activité.'];
    } elseif ($d['ratio_personnel'] !== null && $d['ratio_personnel'] > 35) {
        $a[] = ['orange', 'Personnel à ' . round($d['ratio_personnel']) . ' % du CA : vise 30 à 35 %.'];
    }
    if ($d['heures_budget'] !== null && $d['personnel_min'] / 60 > $d['heures_budget'] + 1) {
        $a[] = ['orange', $h($d['personnel_min'] / 60 - $d['heures_budget']) . ' de plus que le budget : décale un renfort sur le rush ou libère quelqu\'un plus tôt.'];
    }
    if (!$d['ca_source'] && $d['jour'] < date('Y-m-d')) {
        $a[] = ['orange', 'Pas de Z ni de CA saisi pour ce jour : le résultat est incomplet.'];
    }
    if ($d['pain_ttc'] > 0) {
        $a[] = ['vert', 'Pain : ' . number_format($d['pain_ht'] - $d['pain_achat'], 0, ',', ' ') . ' € de marge sur ' . $d['pain_pieces'] . ' pièces.'];
    }
    if ($d['etat'] === 'vert') {
        $a[] = ['vert', 'Objectif du jour atteint.'];
    }
    return $a;
}

/** Saison complète : jours, cumuls et comparaison au prévisionnel. */
function pl_saison(): array
{
    $r = pl_reglages();
    $du = (string) $r['saison_debut'];
    $au = min((string) $r['saison_fin'], date('Y-m-d'));
    $jours = $au >= $du ? pl_calculer($du, $au) : [];
    $t = ['ca_ht' => 0, 'ca_resto_ht' => 0, 'pain_ht' => 0, 'personnel' => 0, 'resultat' => 0, 'jours' => 0, 'verts' => 0, 'orange' => 0, 'rouges' => 0];
    foreach ($jours as $d) {
        if ($d['vide'] || $d['etat'] === 'en_cours') {
            continue;
        }
        $t['jours']++;
        foreach (['ca_ht', 'ca_resto_ht', 'pain_ht', 'personnel', 'resultat'] as $k) {
            $t[$k] += $d[$k];
        }
        $t[match ($d['etat']) { 'vert' => 'verts', 'orange' => 'orange', default => 'rouges' }]++;
    }
    $nb = pl_jours_saison($r);
    $ecoules = $au >= $du ? (int) round((strtotime($au) - strtotime($du)) / 86400) + 1 : 0;
    $t['objectif_a_date'] = round($r['objectif_saison'] * $ecoules / $nb, 2);
    $t['ratio_personnel'] = $t['ca_ht'] > 0 ? round($t['personnel'] / $t['ca_ht'] * 100, 1) : null;
    $t['projection'] = $ecoules > 0 && $t['jours'] > 0 ? round($t['resultat'] / $ecoules * $nb, 2) : null;
    return [
        'debut' => $du, 'fin' => (string) $r['saison_fin'], 'jours_saison' => $nb, 'ecoules' => $ecoules,
        'objectif' => (float) $r['objectif_saison'], 'totaux' => $t,
        'jours' => array_values(array_reverse(array_map(fn($d) => array_intersect_key($d, array_flip(['jour', 'vide', 'ca_ht', 'ca_resto_ht', 'pain_ht', 'personnel', 'ratio_personnel', 'resultat', 'etat', 'ca_source'])), $jours))),
    ];
}

/** CA HT du restaurant fait dans la saison avant un jour (pour le loyer au-delà du minimum). */
function pl_cumul_avant(string $jour): float
{
    $r = pl_reglages();
    $du = (string) $r['saison_debut'];
    $veille = date('Y-m-d', strtotime($jour . ' -1 day'));
    if ($veille < $du) {
        return 0.0;
    }
    $s = 0.0;
    foreach (pl_calculer($du, $veille) as $d) {
        $s += $d['ca_resto_ht'];
    }
    return $s;
}

/* ------------------------------------------------------------------ */
/* Connexion : même compte et même session que le portail /admin       */
/* ------------------------------------------------------------------ */

function pl_utilisateur(): ?array
{
    if (PL_DEMO) {
        return ['id' => 0, 'nom' => 'Démo', 'admin' => true];
    }
    $j = (string) ($_COOKIE['le76_equipe'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $j)) {
        return null;
    }
    $pdo = pl_ouvrir([dirname(PL_WEB) . '/le76-reservations/reservations.sqlite', PL_WEB . '/api/donnees/reservations.sqlite']);
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT u.id, u.nom, u.admin FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id WHERE s.jeton = ? AND s.expire > ? AND u.actif = 1');
        $st->execute([hash('sha256', $j), time()]);
        $u = $st->fetch();
        return $u ? ['id' => (int) $u['id'], 'nom' => (string) $u['nom'], 'admin' => (bool) $u['admin']] : null;
    } catch (Throwable $e) {
        error_log('[pilotage] session : ' . $e->getMessage());
        return null;
    }
}

/* ------------------------------------------------------------------ */
/* Démo : une saison fictive, cohérente avec le prévisionnel           */
/* ------------------------------------------------------------------ */

/** Sources simulées (pain, golfette, personnel) pour la démo, stables d'un appel à l'autre. */
function pl_demo_sources(string $du, string $au): array
{
    $o = ['pain' => [], 'golfette' => [], 'personnel' => []];
    $decal = strtotime('2027-04-01') - strtotime((string) pl_reglages()['saison_debut']);
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        if ($j > date('Y-m-d', strtotime('+1 day'))) {
            break;
        }
        mt_srand(crc32($j));
        $f = pl_demo_affluence(date('Y-m-d', strtotime($j) + $decal));
        $pieces = (int) round(120 + 260 * $f + mt_rand(-20, 20));
        $o['pain'][$j] = ['ttc' => $pieces * 2.0, 'cout' => $pieces * 0.9, 'pieces' => $pieces, 'commandes' => (int) round($pieces / 4.5)];
        $n = (int) round(4 + 28 * $f + mt_rand(-2, 3));
        $o['golfette'][$j] = ['ttc' => $n * 31.5, 'commandes' => $n, 'livrees' => (int) round($n * 0.6)];
        $heures = (int) round(8 + 30 * $f + mt_rand(-1, 3)) + 3;
        $o['personnel'][$j] = ['min' => $heures * 60, 'net' => $heures * 12.0, 'source' => $j > date('Y-m-d') ? 'prevu' : 'reel'];
    }
    return $o;
}

/** 0 = basse saison calme, 1 = cœur d'août. Week-ends et ponts plus forts. */
function pl_demo_affluence(string $j): float
{
    $t = strtotime($j);
    $doy = (int) date('z', $t);
    $pic = exp(-(($doy - 215) ** 2) / (2 * 28 ** 2));        // pic début août
    $we = in_array((int) date('N', $t), [5, 6, 7], true) ? 0.12 : 0.0;
    return max(0.05, min(1.0, $pic + $we));
}

/**
 * La démo vit « en 2027 » : on décale la saison pour que le jour d'aujourd'hui tombe fin juillet,
 * et on remplit les jours passés (Z fictifs, petits-déj, météo). Refait une fois par jour.
 */
function pl_demo_preparer(): void
{
    $pdo = pl_db();
    $deja = $pdo->query("SELECT valeur FROM reglages WHERE cle = 'demo_jour'")->fetchColumn();
    if ($deja === date('Y-m-d')) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('DELETE FROM jours');
        $pdo->exec("DELETE FROM reglages WHERE cle NOT LIKE 'z_%'");
        // Aujourd'hui = 118e jour de saison (fin juillet), saison de 161 jours
        $debut = date('Y-m-d', strtotime('-117 days'));
        $fin = date('Y-m-d', strtotime($debut . ' +160 days'));
        pl_reglage_ecrire('saison_debut', $debut);
        pl_reglage_ecrire('saison_fin', $fin);
        $decal = strtotime('2027-04-01') - strtotime($debut); // pour caler l'affluence sur le vrai calendrier
        $st = $pdo->prepare('INSERT INTO jours (jour, data, modifie_le) VALUES (?, ?, ?)');
        for ($j = $debut; $j < date('Y-m-d'); $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            mt_srand(crc32('z' . $j));
            $f = pl_demo_affluence(date('Y-m-d', strtotime($j) + $decal));
            $ttc = round(380 + 2650 * $f + mt_rand(-150, 220), 2);
            $meteo = $f > 0.5 ? (mt_rand(0, 9) < 7 ? 'soleil' : 'orage') : ['soleil', 'nuageux', 'pluie'][mt_rand(0, 2)];
            if ($meteo === 'pluie') {
                $ttc = round($ttc * 0.72, 2);
            }
            $d = [
                'z' => ['date' => $j, 'ca_ttc' => $ttc, 'ca_ht' => round($ttc / 1.117, 2), 'couverts' => (int) round($ttc / 18)],
                'pdj_classique' => (int) round(3 + 10 * $f + mt_rand(-1, 2)), 'pdj_familial' => (int) round(1 + 4 * $f),
                'occupation' => (int) round(35 + 60 * $f), 'meteo' => $meteo,
            ];
            if ((int) date('N', strtotime($j)) === 5 && $f > 0.4) {
                $d['animation'] = 'Soirée moules-frites';
            }
            $st->execute([$j, json_encode($d, JSON_UNESCAPED_UNICODE), maintenant()]);
        }
        pl_reglage_ecrire('demo_jour', date('Y-m-d'));
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
