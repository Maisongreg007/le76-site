<?php
/**
 * Le 76 · Le Club : carte de fidélité (visites, cadeaux) et offres par e-mail.
 *
 * Même base et mêmes comptes d'équipe que les réservations (tables préfixées club_).
 * Le serveur décide seul : date du jour (Europe/Paris), une visite par jour,
 * cadeaux gagnés, destinataires, une utilisation par offre et par client.
 *
 * Sécurité des envois : tant que le réglage « envoi_reel » est coupé (par défaut),
 * aucune offre ne part ; les campagnes sont seulement simulées. En démo, jamais d'envoi.
 */
declare(strict_types=1);

const CLUB_VERSION_ACCORD = 'club-offres-v1';
const CLUB_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679'; // sans 0/O, 1/I/L, 2/Z, 5/S, 8/B

/* ------------------------------------------------------------------ */
/* Base                                                                */
/* ------------------------------------------------------------------ */

function club_db(): PDO
{
    static $pret = false;
    $pdo = db();
    if (!$pret) {
        $pret = true;
        club_tables($pdo);
        if (defined('DEMO') && !(int) $pdo->query('SELECT COUNT(*) FROM club_membres')->fetchColumn()) {
            require_once __DIR__ . '/demo-club.php';
            demo_club_seed($pdo);
        }
    }
    return $pdo;
}

function club_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_membres (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        carte TEXT NOT NULL UNIQUE,
        jeton TEXT NOT NULL UNIQUE,
        prenom TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        langue TEXT NOT NULL DEFAULT 'fr',
        offres INTEGER NOT NULL DEFAULT 0,
        offres_le TEXT,
        offres_retire_le TEXT,
        origine TEXT NOT NULL DEFAULT 'site',
        note TEXT NOT NULL DEFAULT '',
        inscrit_le TEXT NOT NULL,
        inscrit_par TEXT NOT NULL DEFAULT ''
    )");
    // Historique des accords aux offres (preuve du consentement, jamais réécrite)
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_accords (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        membre_id INTEGER NOT NULL REFERENCES club_membres(id) ON DELETE CASCADE,
        quand TEXT NOT NULL,
        accord INTEGER NOT NULL,
        source TEXT NOT NULL,
        version TEXT NOT NULL DEFAULT '',
        par TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_visites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        membre_id INTEGER NOT NULL REFERENCES club_membres(id) ON DELETE CASCADE,
        jour TEXT NOT NULL,
        quand TEXT NOT NULL,
        par TEXT NOT NULL DEFAULT '',
        UNIQUE (membre_id, jour)
    )");
    // Cadeaux remis : le rang (1er, 2e…) est unique par client → impossible d'offrir deux fois le même
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_cadeaux (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        membre_id INTEGER NOT NULL REFERENCES club_membres(id) ON DELETE CASCADE,
        rang INTEGER NOT NULL,
        libelle TEXT NOT NULL,
        quand TEXT NOT NULL,
        par TEXT NOT NULL DEFAULT '',
        UNIQUE (membre_id, rang)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_offres (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titre TEXT NOT NULL,
        objet TEXT NOT NULL,
        objet_de TEXT NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        message_de TEXT NOT NULL DEFAULT '',
        debut TEXT NOT NULL,
        fin TEXT NOT NULL,
        jours TEXT NOT NULL DEFAULT '1234567',
        services TEXT NOT NULL DEFAULT 'midi,soir',
        cible TEXT NOT NULL DEFAULT 'tous',
        statut TEXT NOT NULL DEFAULT 'brouillon',
        simulation INTEGER NOT NULL DEFAULT 1,
        nb_dest INTEGER NOT NULL DEFAULT 0,
        cree_le TEXT NOT NULL,
        cree_par TEXT NOT NULL DEFAULT '',
        modifie_le TEXT,
        valide_le TEXT,
        valide_par TEXT NOT NULL DEFAULT ''
    )");
    // Un destinataire par offre (UNIQUE) : pas de double envoi ni de double utilisation
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_envois (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        offre_id INTEGER NOT NULL REFERENCES club_offres(id) ON DELETE CASCADE,
        membre_id INTEGER NOT NULL REFERENCES club_membres(id) ON DELETE CASCADE,
        statut TEXT NOT NULL,
        tentatives INTEGER NOT NULL DEFAULT 0,
        tente_le TEXT,
        envoye_le TEXT,
        erreur TEXT NOT NULL DEFAULT '',
        utilise_le TEXT,
        utilise_par TEXT NOT NULL DEFAULT '',
        UNIQUE (offre_id, membre_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_club_envois_statut ON club_envois(statut)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_club_visites_jour ON club_visites(jour)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_journal (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        quand TEXT NOT NULL,
        qui TEXT NOT NULL DEFAULT '',
        action TEXT NOT NULL,
        membre_id INTEGER,
        detail TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS club_reglages (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)");
}

function club_journal(string $action, ?int $membre_id = null, string $detail = '', string $qui = ''): void
{
    club_db()->prepare('INSERT INTO club_journal (quand, qui, action, membre_id, detail) VALUES (?, ?, ?, ?, ?)')
        ->execute([maintenant(), $qui, $action, $membre_id, mb_substr($detail, 0, 300)]);
}

/** Exécute $fn dans une transaction exclusive (aucune autre écriture en même temps). */
function club_transaction(callable $fn)
{
    $pdo = club_db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn($pdo);
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/* ------------------------------------------------------------------ */
/* Réglages                                                            */
/* ------------------------------------------------------------------ */

function club_reglages_defaut(): array
{
    return [
        'inscriptions' => true,          // page /club ouverte aux inscriptions
        'seuil' => 6,                    // nombre de visites pour un cadeau
        'cadeau' => 'Un dessert offert',
        'cadeau_de' => 'Ein Dessert gratis',
        'habitues' => 3,                 // « Habitués » : au moins N visites
        'absents' => 30,                 // « À retrouver » : pas venus depuis N jours
        'envoi_reel' => false,           // COUPÉ par défaut : aucune offre ne part
        'par_heure' => 40,               // plafond d'e-mails d'offres par heure (messagerie Hostinger)
        'adresse_test' => '',            // adresse pour s'envoyer un essai
        'bienvenue' => true,             // e-mail de bienvenue avec le lien de la carte (si envoi réel)
    ];
}

function club_reglages(bool $recharger = false): array
{
    static $r = null;
    if ($r !== null && !$recharger) {
        return $r;
    }
    $r = club_reglages_defaut();
    foreach (club_db()->query('SELECT cle, valeur FROM club_reglages') as $l) {
        if (array_key_exists($l['cle'], $r)) {
            $r[$l['cle']] = json_decode($l['valeur'], true);
        }
    }
    if (defined('DEMO')) {
        $r['envoi_reel'] = false;
    }
    return $r;
}

function club_reglages_enregistrer(array $n, string $qui): array
{
    $a = club_reglages();
    $o = [];
    foreach (['inscriptions', 'envoi_reel', 'bienvenue'] as $c) {
        if (array_key_exists($c, $n)) {
            $o[$c] = (bool) $n[$c];
        }
    }
    foreach (['seuil' => [2, 30], 'habitues' => [2, 50], 'absents' => [7, 365], 'par_heure' => [5, 300]] as $c => [$min, $max]) {
        if (isset($n[$c]) && is_numeric($n[$c])) {
            $o[$c] = max($min, min($max, (int) $n[$c]));
        }
    }
    foreach (['cadeau' => 80, 'cadeau_de' => 80] as $c => $lg) {
        if (isset($n[$c]) && is_string($n[$c]) && trim($n[$c]) !== '') {
            $o[$c] = nettoyer_texte($n[$c], $lg);
        }
    }
    if (array_key_exists('adresse_test', $n)) {
        $t = mb_strtolower(nettoyer_texte($n['adresse_test'], 120));
        if ($t !== '' && !filter_var($t, FILTER_VALIDATE_EMAIL)) {
            erreur("L'adresse de test ne semble pas correcte.");
        }
        $o['adresse_test'] = $t;
    }
    if (defined('DEMO') && !empty($o['envoi_reel'])) {
        erreur('Démo : l’envoi réel ne peut pas être activé.');
    }
    $st = club_db()->prepare('INSERT INTO club_reglages (cle, valeur) VALUES (?, ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur');
    foreach ($o as $c => $v) {
        $st->execute([$c, json_encode($v, JSON_UNESCAPED_UNICODE)]);
        if ($a[$c] !== $v) {
            club_journal('reglage', null, "$c : " . json_encode($a[$c], JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($v, JSON_UNESCAPED_UNICODE), $qui);
        }
    }
    return club_reglages(true);
}

function club_cadeau_texte(?string $l = null): string
{
    $r = club_reglages();
    return ($l ?? langue()) === 'de' && $r['cadeau_de'] !== '' ? $r['cadeau_de'] : $r['cadeau'];
}

/* ------------------------------------------------------------------ */
/* Membres                                                             */
/* ------------------------------------------------------------------ */

function club_nouvelle_carte(PDO $pdo): string
{
    $st = $pdo->prepare('SELECT 1 FROM club_membres WHERE carte = ?');
    do {
        $c = '76-';
        for ($i = 0; $i < 5; $i++) {
            $c .= CLUB_ALPHABET[random_int(0, strlen(CLUB_ALPHABET) - 1)];
        }
        $st->execute([$c]);
    } while ($st->fetchColumn());
    return $c;
}

/** Normalise un numéro de carte saisi ou scanné (« 76 k7q4m », « 76-K7Q4M »). */
function club_carte_normale(string $s): ?string
{
    $s = strtoupper(preg_replace('/[^0-9a-z]/i', '', $s) ?? '');
    if (str_starts_with($s, '76')) {
        $s = substr($s, 2);
    }
    return preg_match('/^[' . CLUB_ALPHABET . ']{5}$/', $s) ? '76-' . $s : null;
}

function club_membre(int $id): array
{
    $st = club_db()->prepare('SELECT * FROM club_membres WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: erreur('Client du Club introuvable.', 404);
}

function club_membre_par_jeton(string $j): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $j)) {
        return null;
    }
    $st = club_db()->prepare('SELECT * FROM club_membres WHERE jeton = ?');
    $st->execute([$j]);
    return $st->fetch() ?: null;
}

/** Compteurs de fidélité d'un client, calculés à partir des visites (rien n'est stocké en double). */
function club_compteurs(array $m): array
{
    $pdo = club_db();
    $st = $pdo->prepare('SELECT COUNT(*) AS n, MAX(jour) AS der, MIN(jour) AS prem FROM club_visites WHERE membre_id = ?');
    $st->execute([$m['id']]);
    $v = $st->fetch();
    $st = $pdo->prepare('SELECT COUNT(*) FROM club_cadeaux WHERE membre_id = ?');
    $st->execute([$m['id']]);
    $remis = (int) $st->fetchColumn();
    $seuil = (int) club_reglages()['seuil'];
    $n = (int) $v['n'];
    $gagnes = intdiv($n, $seuil);
    return [
        'visites' => $n,
        'derniere' => $v['der'],
        'premiere' => $v['prem'],
        'seuil' => $seuil,
        'cadeaux_gagnes' => $gagnes,
        'cadeaux_remis' => $remis,
        'cadeaux_dispo' => max(0, $gagnes - $remis),
        'tampons' => $n % $seuil,                   // tampons sur la carte en cours
        'reste' => $seuil - ($n % $seuil),          // visites avant le prochain cadeau
        'venu_aujourdhui' => $v['der'] === date('Y-m-d'),
    ];
}

function club_valider_prenom_email(string $prenom, string $email): void
{
    if (mb_strlen($prenom) < 1) {
        erreur(t('Indiquez votre prénom.', 'Bitte geben Sie Ihren Vornamen an.'));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
        erreur(t("L'adresse e-mail ne semble pas correcte.", 'Die E-Mail-Adresse scheint nicht korrekt zu sein.'));
    }
}

/**
 * Inscription (page /club ou équipe au comptoir).
 * Retourne ['membre' => …, 'existe' => bool]. Si l'adresse a déjà une carte, rien n'est modifié.
 */
function club_inscrire(string $prenom, string $email, string $l, bool $offres, string $origine, string $par = ''): array
{
    $prenom = nettoyer_texte($prenom, 40);
    $email = mb_strtolower(nettoyer_texte($email, 120));
    club_valider_prenom_email($prenom, $email);
    return club_transaction(function (PDO $pdo) use ($prenom, $email, $l, $offres, $origine, $par) {
        $st = $pdo->prepare('SELECT * FROM club_membres WHERE email = ?');
        $st->execute([$email]);
        if ($m = $st->fetch()) {
            return ['membre' => $m, 'existe' => true];
        }
        $now = maintenant();
        $pdo->prepare('INSERT INTO club_membres (carte, jeton, prenom, email, langue, offres, offres_le, origine, inscrit_le, inscrit_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([club_nouvelle_carte($pdo), jeton(), $prenom, $email, $l === 'de' ? 'de' : 'fr', $offres ? 1 : 0, $offres ? $now : null, $origine, $now, $par]);
        $id = (int) $pdo->lastInsertId();
        if ($offres) {
            $pdo->prepare('INSERT INTO club_accords (membre_id, quand, accord, source, version, par) VALUES (?, ?, 1, ?, ?, ?)')
                ->execute([$id, $now, $origine === 'equipe' ? 'equipe' : 'inscription', CLUB_VERSION_ACCORD, $par]);
        }
        club_journal('inscription', $id, ($offres ? 'avec' : 'sans') . " accord offres · $origine", $par);
        $st = $pdo->prepare('SELECT * FROM club_membres WHERE id = ?');
        $st->execute([$id]);
        return ['membre' => $st->fetch(), 'existe' => false];
    });
}

/** Donne ou retire l'accord aux offres par e-mail. Toujours tracé dans club_accords. */
function club_accord(array $m, bool $accord, string $source, string $par = ''): array
{
    if ((bool) $m['offres'] === $accord) {
        return $m;
    }
    $now = maintenant();
    club_transaction(function (PDO $pdo) use ($m, $accord, $source, $par, $now) {
        $accord
            ? $pdo->prepare('UPDATE club_membres SET offres = 1, offres_le = ?, offres_retire_le = NULL WHERE id = ?')->execute([$now, $m['id']])
            : $pdo->prepare('UPDATE club_membres SET offres = 0, offres_retire_le = ? WHERE id = ?')->execute([$now, $m['id']]);
        $pdo->prepare('INSERT INTO club_accords (membre_id, quand, accord, source, version, par) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$m['id'], $now, $accord ? 1 : 0, $source, $accord ? CLUB_VERSION_ACCORD : '', $par]);
        // Un client désinscrit ne reçoit plus rien, même d'une campagne déjà en file d'attente
        if (!$accord) {
            $pdo->prepare("UPDATE club_envois SET statut = 'exclu', erreur = 'Désinscrit avant l’envoi' WHERE membre_id = ? AND statut = 'attente'")->execute([$m['id']]);
        }
    });
    club_journal($accord ? 'accord_offres' : 'retrait_offres', (int) $m['id'], $source, $par);
    return club_membre((int) $m['id']);
}

/** Enregistre la visite du jour (une seule par jour et par client, garantie par la base). */
function club_visite(int $id, string $par): array
{
    $m = club_membre($id);
    $avant = club_compteurs($m);
    $st = club_db()->prepare('INSERT OR IGNORE INTO club_visites (membre_id, jour, quand, par) VALUES (?, ?, ?, ?)');
    $st->execute([$id, date('Y-m-d'), maintenant(), $par]);
    if (!$st->rowCount()) {
        erreur("La visite d'aujourd'hui est déjà comptée pour {$m['prenom']}.", 409);
    }
    club_journal('visite', $id, '', $par);
    $apres = club_compteurs($m);
    return ['compteurs' => $apres, 'cadeau_gagne' => $apres['cadeaux_dispo'] > $avant['cadeaux_dispo']];
}

/** Annule la visite d'aujourd'hui (erreur de saisie), sauf si un cadeau en dépend déjà. */
function club_visite_annuler(int $id, string $par): array
{
    $m = club_membre($id);
    club_transaction(function (PDO $pdo) use ($m) {
        $c = club_compteurs($m);
        if (!$c['venu_aujourdhui']) {
            erreur("Aucune visite aujourd'hui à annuler.");
        }
        if (intdiv($c['visites'] - 1, $c['seuil']) < $c['cadeaux_remis']) {
            erreur('Impossible : le cadeau obtenu grâce à cette visite a déjà été offert.');
        }
        $pdo->prepare('DELETE FROM club_visites WHERE membre_id = ? AND jour = ?')->execute([$m['id'], date('Y-m-d')]);
    });
    club_journal('visite_annulee', $id, '', $par);
    return club_compteurs($m);
}

/** Offre le cadeau de fidélité disponible. Le rang unique empêche tout doublon, même à deux iPad en même temps. */
function club_cadeau(int $id, string $par): array
{
    $m = club_membre($id);
    $libelle = club_cadeau_texte('fr');
    club_transaction(function (PDO $pdo) use ($m, $par, $libelle) {
        $c = club_compteurs($m);
        if ($c['cadeaux_dispo'] < 1) {
            erreur("{$m['prenom']} n'a pas de cadeau disponible.", 409);
        }
        $pdo->prepare('INSERT INTO club_cadeaux (membre_id, rang, libelle, quand, par) VALUES (?, ?, ?, ?, ?)')
            ->execute([$m['id'], $c['cadeaux_remis'] + 1, $libelle, maintenant(), $par]);
    });
    club_journal('cadeau', $id, $libelle, $par);
    return club_compteurs($m);
}

/** Supprime un client et tout son historique (droit à l'effacement). */
function club_supprimer(int $id, string $par): void
{
    $m = club_membre($id);
    club_db()->prepare('DELETE FROM club_membres WHERE id = ?')->execute([$id]);
    club_journal('suppression', null, 'carte ' . $m['carte'], $par);
}

/* ------------------------------------------------------------------ */
/* Offres et campagnes                                                 */
/* ------------------------------------------------------------------ */

const CLUB_CIBLES = ['tous', 'habitues', 'absents'];
const CLUB_JOURS = [1 => ['lundi', 'Montag'], 2 => ['mardi', 'Dienstag'], 3 => ['mercredi', 'Mittwoch'], 4 => ['jeudi', 'Donnerstag'],
    5 => ['vendredi', 'Freitag'], 6 => ['samedi', 'Samstag'], 7 => ['dimanche', 'Sonntag']];

function club_cible_texte(string $cible): string
{
    $r = club_reglages();
    return match ($cible) {
        'habitues' => "Habitués ({$r['habitues']} visites ou plus)",
        'absents' => "À retrouver (pas venus depuis {$r['absents']} jours)",
        default => 'Tous les abonnés',
    };
}

/** Clients qui recevraient l'offre maintenant : uniquement ceux qui ont accepté les offres. */
function club_destinataires(string $cible): array
{
    $r = club_reglages();
    $rows = club_db()->query("SELECT m.id, m.prenom, m.email, m.langue, m.carte,
            (SELECT COUNT(*) FROM club_visites v WHERE v.membre_id = m.id) AS visites,
            (SELECT MAX(jour) FROM club_visites v WHERE v.membre_id = m.id) AS derniere
        FROM club_membres m WHERE m.offres = 1 ORDER BY m.prenom COLLATE NOCASE")->fetchAll();
    $limite = date('Y-m-d', strtotime('-' . (int) $r['absents'] . ' days'));
    return array_values(array_filter($rows, fn($m) => match ($cible) {
        'habitues' => (int) $m['visites'] >= (int) $r['habitues'],
        'absents' => $m['derniere'] !== null && $m['derniere'] <= $limite,
        default => true,
    }));
}

function club_offre(int $id): array
{
    $st = club_db()->prepare('SELECT * FROM club_offres WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: erreur('Offre introuvable.', 404);
}

function club_offre_enregistrer(array $q, string $par): array
{
    $id = (int) ($q['id'] ?? 0);
    $o = [
        'titre' => nettoyer_texte($q['titre'] ?? '', 80),
        'objet' => nettoyer_texte($q['objet'] ?? '', 120),
        'objet_de' => nettoyer_texte($q['objet_de'] ?? '', 120),
        'message' => nettoyer_texte($q['message'] ?? '', 2000),
        'message_de' => nettoyer_texte($q['message_de'] ?? '', 2000),
        'debut' => (string) ($q['debut'] ?? ''),
        'fin' => (string) ($q['fin'] ?? ''),
        'cible' => in_array($q['cible'] ?? '', CLUB_CIBLES, true) ? $q['cible'] : 'tous',
    ];
    $jours = array_values(array_unique(array_filter(array_map('intval', (array) ($q['jours'] ?? [])), fn($j) => $j >= 1 && $j <= 7)));
    sort($jours);
    $services = array_values(array_intersect(['midi', 'soir'], (array) ($q['services'] ?? [])));
    if ($o['titre'] === '' || $o['objet'] === '' || $o['message'] === '') {
        erreur("Complète le nom de l'offre, l'objet et le message.");
    }
    if (!date_valide($o['debut']) || !date_valide($o['fin']) || $o['fin'] < $o['debut']) {
        erreur('Vérifie les dates de validité.');
    }
    if ($o['fin'] < date('Y-m-d')) {
        erreur('La date de fin est déjà passée.');
    }
    if (!$jours || !$services) {
        erreur('Choisis au moins un jour et un service.');
    }
    if (($o['objet_de'] === '') !== ($o['message_de'] === '')) {
        erreur("Pour la version allemande, remplis l'objet ET le message (ou laisse les deux vides).");
    }
    $o['jours'] = implode('', $jours);
    $o['services'] = implode(',', $services);
    if ($id) {
        $avant = club_offre($id);
        if ($avant['statut'] !== 'brouillon') {
            erreur('Cette offre est déjà validée : elle ne peut plus être modifiée. Duplique-la pour en faire une nouvelle.', 409);
        }
        $o['modifie_le'] = maintenant();
        $o['id'] = $id;
        club_db()->prepare('UPDATE club_offres SET titre=:titre, objet=:objet, objet_de=:objet_de, message=:message, message_de=:message_de,
            debut=:debut, fin=:fin, jours=:jours, services=:services, cible=:cible, modifie_le=:modifie_le WHERE id=:id AND statut = \'brouillon\'')->execute($o);
    } else {
        $o['cree_le'] = maintenant();
        $o['cree_par'] = $par;
        club_db()->prepare('INSERT INTO club_offres (titre, objet, objet_de, message, message_de, debut, fin, jours, services, cible, cree_le, cree_par)
            VALUES (:titre, :objet, :objet_de, :message, :message_de, :debut, :fin, :jours, :services, :cible, :cree_le, :cree_par)')->execute($o);
        $id = (int) club_db()->lastInsertId();
    }
    return club_offre($id);
}

/** « Valable du jeudi 5 au dimanche 15 novembre, du mardi au vendredi, le midi. » */
function club_conditions(array $o, string $l = 'fr'): string
{
    $jours = array_map('intval', str_split($o['jours']));
    $services = explode(',', $o['services']);
    $de = $l === 'de';
    $quand = $de
        ? 'Gültig vom ' . date_de($o['debut']) . ' bis ' . date_de($o['fin'], true)
        : 'Valable du ' . date_fr($o['debut']) . ' au ' . date_fr($o['fin'], true);
    if (count($jours) < 7) {
        $consecutifs = $jours === range($jours[0], end($jours)) && count($jours) > 2;
        $noms = array_map(fn($j) => CLUB_JOURS[$j][$de ? 1 : 0], $jours);
        $quand .= $consecutifs
            ? ($de ? ', ' . $noms[0] . ' bis ' . end($noms) : ', du ' . $noms[0] . ' au ' . end($noms))
            : ($de ? ', ' : ', le ') . club_liste($noms, $de ? 'und' : 'et');
    }
    if (count($services) === 1) {
        $quand .= $services[0] === 'midi' ? ($de ? ', mittags' : ', le midi') : ($de ? ', abends' : ', le soir');
    }
    return $quand . '.';
}

function club_liste(array $mots, string $et): string
{
    return count($mots) > 1 ? implode(', ', array_slice($mots, 0, -1)) . " $et " . end($mots) : (string) ($mots[0] ?? '');
}

function club_lien_carte(array $m): string
{
    return rtrim(reglages()['site'], '/') . (defined('DEMO') ? '/demo' : '') . '/club/?c=' . $m['jeton'] . ($m['langue'] === 'de' ? '&lang=de' : '');
}

function club_lien_desinscription(array $m): string
{
    return rtrim(reglages()['site'], '/') . (defined('DEMO') ? '/demo' : '') . '/club/?desinscrire=' . $m['jeton'] . ($m['langue'] === 'de' ? '&lang=de' : '');
}

/** Construit l'e-mail d'une offre pour un client : [objet, html, texte, entêtes]. */
function club_mail_offre(array $o, array $m): array
{
    $de = $m['langue'] === 'de' && $o['objet_de'] !== '' && $o['message_de'] !== '';
    $l = $de ? 'de' : 'fr';
    $objet = $de ? $o['objet_de'] : $o['objet'];
    $msg = str_replace(['{prenom}', '{Prenom}', '{vorname}'], $m['prenom'], $de ? $o['message_de'] : $o['message']);
    $paragraphes = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $msg) ?: []), 'strlen'));
    $recap = $de
        ? ['Gültigkeit' => club_conditions($o, 'de'), 'Ihre Karte' => $m['carte']]
        : ['Validité' => club_conditions($o, 'fr'), 'Votre carte' => $m['carte']];
    $desinscr = club_lien_desinscription($m);
    $pied = $de
        ? "Zeigen Sie Ihre Club-Karte beim Besuch vor. Ein Angebot pro Person, nur einmal einlösbar.\nSie erhalten diese E-Mail, weil Sie den Angeboten des Le 76 zugestimmt haben. Abmelden: $desinscr"
        : "Présentez votre carte du Club lors de votre venue. Offre personnelle, utilisable une seule fois.\nVous recevez cet e-mail car vous avez accepté les offres du 76. Se désinscrire : $desinscr";
    [$html, $txt] = gabarit_mail($objet, $paragraphes, $recap, [$de ? 'Meine Club-Karte' : 'Ma carte du Club', club_lien_carte($m)], $pied, $l);
    $un = rtrim(reglages()['site'], '/') . '/api/club.php?action=desinscrire_1clic&j=' . $m['jeton'];
    return [$objet, $html, $txt, ["List-Unsubscribe: <$un>, <mailto:" . reglages()['expediteur'] . '?subject=desinscription>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click']];
}

/** Aperçu : l'e-mail tel que le recevrait le premier destinataire (ou un exemple), en français et en allemand. */
function club_apercu(array $o): array
{
    $dest = club_destinataires($o['cible']);
    $exemple = fn($l) => ['id' => 0, 'prenom' => $l === 'de' ? 'Julia' : 'Camille', 'carte' => '76-XXXXX', 'jeton' => str_repeat('0', 32), 'langue' => $l];
    $fr = null;
    $de = null;
    foreach ($dest as $d) {
        if ($d['langue'] === 'de' && !$de) {
            $de = $d;
        } elseif ($d['langue'] !== 'de' && !$fr) {
            $fr = $d;
        }
    }
    $mailFr = club_mail_offre($o, ['jeton' => str_repeat('0', 32)] + ($fr ?? $exemple('fr')));
    $mailDe = club_mail_offre($o, ['jeton' => str_repeat('0', 32)] + ($de ?? $exemple('de')));
    $nbDe = count(array_filter($dest, fn($d) => $d['langue'] === 'de'));
    $exclus = (int) club_db()->query('SELECT COUNT(*) FROM club_membres WHERE offres = 0')->fetchColumn();
    return [
        'fr' => ['objet' => $mailFr[0], 'html' => $mailFr[1]],
        'de' => ['objet' => $mailDe[0], 'html' => $mailDe[1], 'traduit' => $o['objet_de'] !== ''],
        'destinataires' => array_map(fn($d) => ['prenom' => $d['prenom'], 'email' => $d['email'], 'langue' => $d['langue'], 'visites' => (int) $d['visites'], 'derniere' => $d['derniere']], $dest),
        'nb' => count($dest),
        'nb_de' => $nbDe,
        'sans_accord' => $exclus,
        'conditions' => club_conditions($o),
    ];
}

/**
 * Valide une offre : fige la liste des destinataires. $attendu = nombre vu dans l'aperçu :
 * si la liste a changé entre-temps (inscription, désinscription), on redemande un aperçu.
 */
function club_offre_valider(int $id, int $attendu, string $par): array
{
    $r = club_reglages();
    $simulation = !$r['envoi_reel'];
    $res = club_transaction(function (PDO $pdo) use ($id, $attendu, $par, $simulation) {
        $o = club_offre($id);
        if ($o['statut'] !== 'brouillon') {
            erreur('Cette offre est déjà validée. Rien n’a été envoyé une deuxième fois.', 409);
        }
        if ($o['fin'] < date('Y-m-d')) {
            erreur('Cette offre est expirée : change les dates.');
        }
        $dest = club_destinataires($o['cible']);
        if (!$dest) {
            erreur('Personne à qui envoyer : aucun client de ce groupe n’a accepté les offres.');
        }
        if (count($dest) !== $attendu) {
            erreur('La liste a changé depuis l’aperçu (' . count($dest) . " au lieu de $attendu). Regarde à nouveau l’aperçu avant de valider.", 409);
        }
        $statut = $simulation ? 'simule' : 'attente';
        $ins = $pdo->prepare('INSERT INTO club_envois (offre_id, membre_id, statut) VALUES (?, ?, ?)');
        foreach ($dest as $d) {
            $ins->execute([$id, $d['id'], $statut]);
        }
        $pdo->prepare("UPDATE club_offres SET statut = ?, simulation = ?, nb_dest = ?, valide_le = ?, valide_par = ? WHERE id = ? AND statut = 'brouillon'")
            ->execute([$simulation ? 'simulee' : 'envoi', $simulation ? 1 : 0, count($dest), maintenant(), $par, $id]);
        return ['nb' => count($dest), 'simulation' => $simulation];
    });
    club_journal($simulation ? 'campagne_simulee' : 'campagne_validee', null, "offre $id · {$res['nb']} destinataires", $par);
    return $res;
}

/** Duplique une offre (pour la renvoyer ou l'adapter) en nouveau brouillon. */
function club_offre_dupliquer(int $id, string $par): array
{
    $o = club_offre($id);
    $debut = max($o['debut'], date('Y-m-d'));
    $fin = $o['fin'] < $debut ? date('Y-m-d', strtotime("$debut +14 days")) : $o['fin'];
    club_db()->prepare('INSERT INTO club_offres (titre, objet, objet_de, message, message_de, debut, fin, jours, services, cible, cree_le, cree_par)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([mb_substr($o['titre'] . ' (copie)', 0, 80), $o['objet'], $o['objet_de'], $o['message'], $o['message_de'], $debut, $fin, $o['jours'], $o['services'], $o['cible'], maintenant(), $par]);
    return club_offre((int) club_db()->lastInsertId());
}

/** État de chaque offre avec ses compteurs d'envoi et d'utilisation. */
function club_offres_liste(): array
{
    $rows = club_db()->query("SELECT o.*,
        (SELECT COUNT(*) FROM club_envois e WHERE e.offre_id = o.id AND e.statut = 'envoye') AS envoyes,
        (SELECT COUNT(*) FROM club_envois e WHERE e.offre_id = o.id AND e.statut IN ('attente','envoi')) AS en_attente,
        (SELECT COUNT(*) FROM club_envois e WHERE e.offre_id = o.id AND e.statut = 'erreur') AS erreurs,
        (SELECT COUNT(*) FROM club_envois e WHERE e.offre_id = o.id AND e.statut = 'exclu') AS exclus,
        (SELECT COUNT(*) FROM club_envois e WHERE e.offre_id = o.id AND e.utilise_le IS NOT NULL) AS utilises
        FROM club_offres o ORDER BY CASE o.statut WHEN 'brouillon' THEN 0 ELSE 1 END, COALESCE(o.valide_le, o.cree_le) DESC LIMIT 60")->fetchAll();
    foreach ($rows as &$o) {
        foreach (['id', 'nb_dest', 'envoyes', 'en_attente', 'erreurs', 'exclus', 'utilises', 'simulation'] as $c) {
            $o[$c] = (int) $o[$c];
        }
        $o['conditions'] = club_conditions($o);
        $o['cible_texte'] = club_cible_texte($o['cible']);
    }
    return $rows;
}

function club_offre_envois(int $id): array
{
    $st = club_db()->prepare('SELECT e.id, e.statut, e.tentatives, e.envoye_le, e.erreur, e.utilise_le, e.utilise_par, m.prenom, m.email, m.carte
        FROM club_envois e JOIN club_membres m ON m.id = e.membre_id WHERE e.offre_id = ? ORDER BY m.prenom COLLATE NOCASE');
    $st->execute([$id]);
    return $st->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Envoi réel (par petits lots, pour respecter la messagerie)          */
/* ------------------------------------------------------------------ */

/**
 * Envoie au plus $max e-mails en attente, sans dépasser le plafond horaire.
 * Chaque envoi est « réservé » par une mise à jour atomique : deux appels simultanés
 * ne peuvent pas envoyer deux fois le même message. Retourne le nombre envoyé.
 */
function club_envoyer_lot(int $max = 10): array
{
    $r = club_reglages();
    $pdo = club_db();
    $res = ['envoyes' => 0, 'erreurs' => 0, 'restants' => 0, 'pause' => false];
    // Un envoi resté « en cours » plus de 15 min (coupure) n'est PAS renvoyé automatiquement : à vérifier
    $pdo->prepare("UPDATE club_envois SET statut = 'erreur', erreur = 'Envoi interrompu : vérifier avant de relancer' WHERE statut = 'envoi' AND tente_le < ?")
        ->execute([date('Y-m-d H:i:s', time() - 900)]);
    $res['restants'] = (int) $pdo->query("SELECT COUNT(*) FROM club_envois WHERE statut = 'attente'")->fetchColumn();
    if (!$r['envoi_reel'] || defined('DEMO') || !$res['restants']) {
        return $res;
    }
    $verrou = fopen(dossier_donnees() . '/club-envoi.lock', 'c');
    if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) {
        return $res; // un autre lot est déjà en train de partir
    }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM club_envois WHERE statut = 'envoye' AND envoye_le > ?");
        $st->execute([date('Y-m-d H:i:s', time() - 3600)]);
        $quota = (int) $r['par_heure'] - (int) $st->fetchColumn();
        if ($quota <= 0) {
            $res['pause'] = true;
            return $res;
        }
        $ids = $pdo->query("SELECT id FROM club_envois WHERE statut = 'attente' ORDER BY id LIMIT " . min($max, $quota))->fetchAll(PDO::FETCH_COLUMN);
        $reserver = $pdo->prepare("UPDATE club_envois SET statut = 'envoi', tentatives = tentatives + 1, tente_le = ? WHERE id = ? AND statut = 'attente'");
        $fini = $pdo->prepare('UPDATE club_envois SET statut = ?, envoye_le = ?, erreur = ? WHERE id = ?');
        foreach ($ids as $eid) {
            $reserver->execute([maintenant(), $eid]);
            if (!$reserver->rowCount()) {
                continue;
            }
            $st = $pdo->prepare('SELECT e.offre_id, m.* FROM club_envois e JOIN club_membres m ON m.id = e.membre_id WHERE e.id = ?');
            $st->execute([$eid]);
            $m = $st->fetch();
            $o = club_offre((int) $m['offre_id']);
            // Dernière vérification au moment même de l'envoi
            if (!(int) $m['offres']) {
                $fini->execute(['exclu', null, 'Désinscrit avant l’envoi', $eid]);
                continue;
            }
            if ($o['fin'] < date('Y-m-d')) {
                $fini->execute(['exclu', null, 'Offre expirée avant l’envoi', $eid]);
                continue;
            }
            [$objet, $html, $txt, $entetes] = club_mail_offre($o, $m);
            $ok = envoyer_mail($m['email'], $objet, $html, $txt, null, $entetes);
            $ok ? $res['envoyes']++ : $res['erreurs']++;
            $fini->execute($ok ? ['envoye', maintenant(), '', $eid] : ['erreur', null, 'Refusé par la messagerie', $eid]);
        }
        // Offres dont tous les envois sont terminés
        $pdo->exec("UPDATE club_offres SET statut = 'envoyee' WHERE statut = 'envoi'
            AND NOT EXISTS (SELECT 1 FROM club_envois e WHERE e.offre_id = club_offres.id AND e.statut IN ('attente','envoi'))");
        $res['restants'] = (int) $pdo->query("SELECT COUNT(*) FROM club_envois WHERE statut = 'attente'")->fetchColumn();
        return $res;
    } finally {
        flock($verrou, LOCK_UN);
        fclose($verrou);
    }
}

/**
 * Après chaque appel de l'appli équipe (les iPad interrogent le serveur toutes les 20 s),
 * envoie un petit lot d'offres en attente sans faire attendre personne.
 */
function club_envois_en_arriere_plan(): void
{
    if (defined('DEMO')) {
        return;
    }
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        try {
            if (club_reglages()['envoi_reel']) {
                club_envoyer_lot(5);
            }
        } catch (Throwable $e) {
            error_log('[club] envoi en arrière-plan : ' . $e->getMessage());
        }
    });
}

/** Remet en file les envois en erreur d'une offre (3 tentatives maximum par client). */
function club_relancer(int $offre_id, string $par): int
{
    $st = club_db()->prepare("UPDATE club_envois SET statut = 'attente', erreur = '' WHERE offre_id = ? AND statut = 'erreur' AND tentatives < 3");
    $st->execute([$offre_id]);
    if ($st->rowCount()) {
        club_db()->prepare("UPDATE club_offres SET statut = 'envoi' WHERE id = ? AND statut = 'envoyee'")->execute([$offre_id]);
        club_journal('relance', null, "offre $offre_id · {$st->rowCount()} envois", $par);
    }
    return $st->rowCount();
}

/** Envoi d'un essai à l'adresse de test (sur demande explicite, même si l'envoi réel est coupé). */
function club_envoyer_test(int $offre_id, string $l, string $par): string
{
    $a = club_reglages()['adresse_test'];
    if (!$a) {
        erreur('Indique d’abord ton adresse de test dans les réglages du Club.');
    }
    $o = club_offre($offre_id);
    $m = ['id' => 0, 'prenom' => $par ?: 'Greg', 'carte' => '76-ESSAI', 'jeton' => str_repeat('0', 32), 'langue' => $l === 'de' ? 'de' : 'fr', 'email' => $a];
    [$objet, $html, $txt, $entetes] = club_mail_offre($o, $m);
    if (!envoyer_mail($a, '[Essai] ' . $objet, $html, $txt, null, $entetes)) {
        erreur("La messagerie a refusé l'essai. Regarde Plus → Journal des e-mails.");
    }
    club_journal('essai', null, "offre $offre_id → $a", $par);
    return $a;
}

/** E-mail de bienvenue avec le lien de la carte (uniquement si l'envoi réel est activé). */
function club_mail_bienvenue(array $m): bool
{
    $r = club_reglages();
    if (!$r['envoi_reel'] || !$r['bienvenue']) {
        return false;
    }
    $l = $m['langue'];
    $c = club_compteurs($m);
    [$html, $txt] = gabarit_mail(
        t("Bienvenue au Club, {$m['prenom']} !", "Willkommen im Club, {$m['prenom']}!", $l),
        [
            t('Votre carte de fidélité du 76 est prête. Présentez-la à chaque visite : l’équipe la scanne en un instant.', 'Ihre Treuekarte vom Le 76 ist bereit. Zeigen Sie sie bei jedem Besuch vor: Das Team scannt sie in einem Augenblick.', $l),
            t("Après {$c['seuil']} visites : " . mb_strtolower(club_cadeau_texte('fr')) . '.', "Nach {$c['seuil']} Besuchen: " . club_cadeau_texte('de') . '.', $l),
            t('Astuce : ouvrez le lien sur votre téléphone puis « Ajouter à l’écran d’accueil » pour toujours l’avoir sous la main.', 'Tipp: Öffnen Sie den Link auf Ihrem Handy und wählen Sie „Zum Home-Bildschirm“, um sie immer dabei zu haben.', $l),
        ],
        [t('Votre carte', 'Ihre Karte', $l) => $m['carte']],
        [t('Ouvrir ma carte', 'Meine Karte öffnen', $l), club_lien_carte($m)],
        (int) $m['offres']
            ? t('Vous avez accepté de recevoir nos offres par e-mail (quelques fois par an). Vous pouvez vous désinscrire à tout moment : ', 'Sie haben zugestimmt, unsere Angebote per E-Mail zu erhalten (einige Male im Jahr). Abmelden jederzeit: ', $l) . club_lien_desinscription($m)
            : t('Vous ne recevrez aucune offre par e-mail : vous pourrez changer d’avis depuis votre carte.', 'Sie erhalten keine Angebote per E-Mail – Sie können dies jederzeit auf Ihrer Karte ändern.', $l),
        $l
    );
    return envoyer_mail($m['email'], t('Votre carte du Club · Le 76', 'Ihre Club-Karte · Le 76', $l), $html, $txt);
}

/* ------------------------------------------------------------------ */
/* Offres reçues et utilisation au restaurant                          */
/* ------------------------------------------------------------------ */

function club_service_maintenant(): string
{
    return date('H:i') < '16:00' ? 'midi' : 'soir';
}

/** Pourquoi une offre ne peut pas être utilisée aujourd'hui (null si elle le peut). */
function club_offre_bloquee(array $o, ?string $l = null): ?string
{
    $auj = date('Y-m-d');
    if ($auj < $o['debut']) {
        return t('Valable à partir du ', 'Gültig ab ', $l) . date_l($o['debut'], false, $l);
    }
    if ($auj > $o['fin']) {
        return t('Offre expirée', 'Angebot abgelaufen', $l);
    }
    if (!str_contains($o['jours'], date('N'))) {
        return t("Pas valable aujourd'hui (", 'Heute nicht gültig (', $l) . CLUB_JOURS[(int) date('N')][($l ?? langue()) === 'de' ? 1 : 0] . ')';
    }
    if (!in_array(club_service_maintenant(), explode(',', $o['services']), true)) {
        return club_service_maintenant() === 'midi' ? t('Valable seulement le soir', 'Nur abends gültig', $l) : t('Valable seulement le midi', 'Nur mittags gültig', $l);
    }
    return null;
}

/** Offres reçues par un client et pas encore expirées (pour sa carte et sa fiche). */
function club_offres_du_membre(int $id, bool $equipe = false, ?string $l = null): array
{
    $statuts = $equipe ? "('envoye','simule')" : "('envoye')";
    $st = club_db()->prepare("SELECT e.id AS envoi_id, e.statut AS envoi_statut, e.utilise_le, e.utilise_par, o.* FROM club_envois e JOIN club_offres o ON o.id = e.offre_id
        WHERE e.membre_id = ? AND e.statut IN $statuts AND (o.fin >= ? OR e.utilise_le >= ?) ORDER BY o.fin");
    $st->execute([$id, date('Y-m-d'), date('Y-m-d', strtotime('-30 days'))]);
    $o = [];
    foreach ($st->fetchAll() as $x) {
        $de = ($l ?? langue()) === 'de' && $x['objet_de'] !== '';
        $o[] = [
            'envoi_id' => (int) $x['envoi_id'],
            'titre' => $equipe ? $x['titre'] : ($de ? $x['objet_de'] : $x['objet']),
            'conditions' => club_conditions($x, $de ? 'de' : 'fr'),
            'fin' => $x['fin'],
            'simulation' => $x['envoi_statut'] === 'simule',
            'utilise_le' => $x['utilise_le'],
            'utilise_par' => $equipe ? $x['utilise_par'] : '',
            'bloque' => $x['utilise_le'] ? null : club_offre_bloquee($x, $l),
        ];
    }
    return $o;
}

/** Utilise une offre reçue : une seule fois par client (mise à jour atomique). */
function club_offre_utiliser(int $envoi_id, int $membre_id, string $par): void
{
    $st = club_db()->prepare('SELECT o.*, e.statut AS envoi_statut FROM club_envois e JOIN club_offres o ON o.id = e.offre_id WHERE e.id = ? AND e.membre_id = ?');
    $st->execute([$envoi_id, $membre_id]);
    $x = $st->fetch() ?: erreur('Cette offre ne correspond pas à ce client.', 404);
    if (!in_array($x['envoi_statut'], ['envoye', 'simule'], true)) {
        erreur("Cette offre n'a pas été envoyée à ce client.", 409);
    }
    if ($pourquoi = club_offre_bloquee($x, 'fr')) {
        erreur($pourquoi . '.', 409);
    }
    $up = club_db()->prepare('UPDATE club_envois SET utilise_le = ?, utilise_par = ? WHERE id = ? AND utilise_le IS NULL');
    $up->execute([maintenant(), $par, $envoi_id]);
    if (!$up->rowCount()) {
        erreur('Offre déjà utilisée par ce client.', 409);
    }
    club_journal('offre_utilisee', $membre_id, $x['titre'], $par);
}

/* ------------------------------------------------------------------ */
/* Sauvegardes et exports                                              */
/* ------------------------------------------------------------------ */

/** Une copie complète de la base par jour (réservations + Club), 30 jours gardés, hors du web. */
function club_sauvegarde_auto(): ?string
{
    if (defined('DEMO')) {
        return null;
    }
    $dir = dossier_donnees() . '/sauvegardes';
    $f = "$dir/base-" . date('Y-m-d') . '.sqlite';
    if (is_file($f)) {
        return null;
    }
    try {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        club_purge(); // une fois par jour, avec la sauvegarde
        club_db()->exec('VACUUM INTO ' . club_db()->quote($f));
        $toutes = glob("$dir/base-*.sqlite") ?: [];
        sort($toutes);
        foreach (array_slice($toutes, 0, max(0, count($toutes) - 30)) as $vieille) {
            @unlink($vieille);
        }
        return $f;
    } catch (Throwable $e) {
        error_log('[club] sauvegarde impossible : ' . $e->getMessage());
        return null;
    }
}

/** Conservation : un client sans visite depuis 3 ans (ou inscrit depuis 3 ans sans jamais venir) est effacé. */
function club_purge(): int
{
    $limite = date('Y-m-d', strtotime('-3 years'));
    $st = club_db()->prepare('DELETE FROM club_membres WHERE substr(inscrit_le, 1, 10) < ?
        AND COALESCE((SELECT MAX(jour) FROM club_visites v WHERE v.membre_id = club_membres.id), substr(inscrit_le, 1, 10)) < ?');
    $st->execute([$limite, $limite]);
    if ($n = $st->rowCount()) {
        club_journal('purge', null, "$n client(s) sans visite depuis 3 ans", 'Automatique');
    }
    return $n;
}

function club_sauvegardes(): array
{
    if (defined('DEMO')) {
        return [];
    }
    $o = [];
    foreach (array_reverse(glob(dossier_donnees() . '/sauvegardes/base-*.sqlite') ?: []) as $f) {
        $o[] = ['jour' => substr(basename($f), 5, 10), 'taille' => filesize($f)];
    }
    return array_slice($o, 0, 30);
}

function club_export_csv(): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="club-le76-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Carte', 'Prénom', 'E-mail', 'Langue', 'Inscrit le', 'Visites', 'Dernière visite', 'Cadeaux offerts', 'Accepte les offres', 'Accord donné le', 'Accord retiré le'], ';');
    $rows = club_db()->query('SELECT m.*, (SELECT COUNT(*) FROM club_visites v WHERE v.membre_id = m.id) AS nb,
        (SELECT MAX(jour) FROM club_visites v WHERE v.membre_id = m.id) AS der,
        (SELECT COUNT(*) FROM club_cadeaux c WHERE c.membre_id = m.id) AS cad FROM club_membres m ORDER BY m.prenom COLLATE NOCASE');
    foreach ($rows as $m) {
        fputcsv($f, [$m['carte'], $m['prenom'], $m['email'], $m['langue'] === 'de' ? 'Allemand' : 'Français', substr($m['inscrit_le'], 0, 10),
            $m['nb'], $m['der'] ?? '', $m['cad'], $m['offres'] ? 'Oui' : 'Non', substr((string) $m['offres_le'], 0, 10), substr((string) $m['offres_retire_le'], 0, 10)], ';');
    }
    exit;
}
