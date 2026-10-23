<?php
/**
 * Réservations du 76 : partie équipe (appli /equipe/).
 * Toutes les actions passent par POST {action: "...", ...}, sauf l'export CSV (GET ?action=export).
 */
declare(strict_types=1);

require __DIR__ . '/lib/base.php';
require __DIR__ . '/lib/messages.php';

const COOKIE = 'le76_equipe';
const DUREE_SESSION = 400 * 86400;

rappels_en_arriere_plan();

try {
    $q = lire_requete();
    $action = (string) ($q['action'] ?? '');
    $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($methode !== 'POST' && !in_array($action, ['export', 'export_abonnes'], true)) {
        erreur('Méthode non autorisée.', 405);
    }

    // Actions possibles sans être connecté
    switch ($action) {
        case 'etat':
            repondre(['ok' => true, 'installe' => nb_utilisateurs() > 0, 'moi' => utilisateur_public(utilisateur()), 'vapid' => utilisateur() ? vapid()['publique'] : null]);
        case 'installer':
            installer($q);
        case 'connexion':
            connexion($q);
        case 'deconnexion':
            deconnexion();
        case 'mdp_oublie':
            mdp_oublie($q);
        case 'mdp_reinit':
            mdp_reinit($q);
    }

    $moi = utilisateur() ?? erreur('Connecte-toi pour continuer.', 401);

    switch ($action) {
        case 'evenements':
            evenements($q);
        case 'jour':
            jour($q);
        case 'demandes':
            demandes();
        case 'avenir':
            avenir($q);
        case 'chercher':
            chercher($q);
        case 'enregistrer':
            enregistrer($q, $moi);
        case 'statut':
            changer_statut($q, $moi);
        case 'arrive':
            arrive($q);
        case 'jour_reglage':
            jour_reglage($q);
        case 'mdp_changer':
            mdp_changer($q, $moi);
        case 'push_abonner':
            push_abonner($q, $moi);
        case 'push_desabonner':
            push_desabonner($q);
        case 'push_test':
            $n = push_tous('Test du 76', 'Les notifications marchent sur ce téléphone 👍', '/equipe/', 'test', (int) $moi['id']);
            repondre(['ok' => true, 'envoyes' => $n]);
    }

    if (!$moi['admin']) {
        erreur('Réservé au responsable.', 403);
    }

    switch ($action) {
        case 'reglages':
            repondre(['ok' => true, 'reglages' => reglages(), 'soirees' => soirees_a_venir(), 'smtp' => smtp_public()]);
        case 'smtp_ecrire':
            repondre(['ok' => true, 'smtp' => smtp_enregistrer(is_array($q['smtp'] ?? null) ? $q['smtp'] : [])]);
        case 'reglages_ecrire':
            repondre(['ok' => true, 'reglages' => reglages_enregistrer(is_array($q['reglages'] ?? null) ? $q['reglages'] : [])]);
        case 'mail_test':
            mail_test();
        case 'journal_mails':
            repondre(['ok' => true, 'journal' => db()->query('SELECT quand, a, sujet, ok FROM journal_mails ORDER BY id DESC LIMIT 60')->fetchAll(), 'alertes' => reglages()['alertes_email']]);
        case 'equipe':
            repondre(['ok' => true, 'equipe' => liste_equipe()]);
        case 'equipe_ajouter':
            equipe_ajouter($q);
        case 'equipe_modifier':
            equipe_modifier($q, $moi);
        case 'equipe_supprimer':
            equipe_supprimer($q, $moi);
        case 'export':
            export_csv();
        case 'abonnes':
            repondre(['ok' => true, 'abonnes' => db()->query('SELECT email, nom, telephone, inscrit_le FROM abonnes WHERE desinscrit_le IS NULL ORDER BY inscrit_le DESC')->fetchAll(),
                'desinscrits' => (int) db()->query('SELECT COUNT(*) FROM abonnes WHERE desinscrit_le IS NOT NULL')->fetchColumn()]);
        case 'export_abonnes':
            export_abonnes();
    }
    erreur('Action inconnue.');
} catch (Throwable $e) {
    error_log('[equipe] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur('Problème technique : ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Comptes                                                             */
/* ------------------------------------------------------------------ */

function nb_utilisateurs(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
}

function utilisateur(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    $j = (string) ($_COOKIE[COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $j)) {
        return null;
    }
    $st = db()->prepare('SELECT u.* FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id WHERE s.jeton = ? AND s.expire > ? AND u.actif = 1');
    $st->execute([hash('sha256', $j), time()]);
    $u = $st->fetch() ?: null;
    return $u;
}

function utilisateur_public(?array $u): ?array
{
    return $u ? ['id' => (int) $u['id'], 'nom' => $u['nom'], 'identifiant' => $u['identifiant'], 'admin' => (bool) $u['admin']] : null;
}

function ouvrir_session(int $id): void
{
    $j = jeton(32);
    db()->prepare('DELETE FROM sessions WHERE expire < ?')->execute([time()]);
    db()->prepare('INSERT INTO sessions (jeton, utilisateur_id, expire) VALUES (?, ?, ?)')->execute([hash('sha256', $j), $id, time() + DUREE_SESSION]);
    setcookie(COOKIE, $j, [
        'expires' => time() + DUREE_SESSION,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function verifier_identifiants(string $identifiant, string $mdp, string $nom): void
{
    if (mb_strlen($nom) < 2) {
        erreur('Indique un prénom.');
    }
    if (!preg_match('/^[a-z0-9._-]{2,30}$/i', $identifiant)) {
        erreur("L'identifiant doit faire 2 à 30 caractères (lettres, chiffres, point, tiret), sans espace ni accent.");
    }
    if (mb_strlen($mdp) < 6) {
        erreur('Le mot de passe doit faire au moins 6 caractères.');
    }
}

function installer(array $q): never
{
    $nom = nettoyer_texte($q['nom'] ?? '', 40);
    $id = nettoyer_texte($q['identifiant'] ?? '', 30);
    $mdp = (string) ($q['mdp'] ?? '');
    verifier_identifiants($id, $mdp, $nom);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    if (nb_utilisateurs() > 0) {
        $pdo->exec('ROLLBACK');
        erreur("L'appli est déjà installée : connecte-toi.", 409);
    }
    $pdo->prepare('INSERT INTO utilisateurs (nom, identifiant, mdp, admin, cree_le) VALUES (?, ?, ?, 1, ?)')
        ->execute([$nom, $id, password_hash($mdp, PASSWORD_DEFAULT), maintenant()]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->exec('COMMIT');
    vapid(); // prépare les clés de notification
    ouvrir_session($uid);
    repondre(['ok' => true]);
}

function connexion(array $q): never
{
    $id = nettoyer_texte($q['identifiant'] ?? '', 30);
    if (!limiter('cnx:' . ip_client(), 10, 900)) {
        erreur('Trop d’essais. Réessaie dans 15 minutes.', 429);
    }
    $st = db()->prepare('SELECT * FROM utilisateurs WHERE identifiant = ? AND actif = 1');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u || !password_verify((string) ($q['mdp'] ?? ''), $u['mdp'])) {
        erreur('Identifiant ou mot de passe incorrect.', 401);
    }
    ouvrir_session((int) $u['id']);
    repondre(['ok' => true]);
}

function deconnexion(): never
{
    $j = (string) ($_COOKIE[COOKIE] ?? '');
    if ($j !== '') {
        db()->prepare('DELETE FROM sessions WHERE jeton = ?')->execute([hash('sha256', $j)]);
    }
    setcookie(COOKIE, '', ['expires' => 1, 'path' => '/']);
    repondre(['ok' => true]);
}

/**
 * Mot de passe oublié : pour un responsable, un lien valable 1 heure part aux adresses d'alerte.
 * Pour un compte d'équipe, c'est le responsable qui remet un mot de passe (Plus → L'équipe).
 */
function mdp_oublie(array $q): never
{
    $id = nettoyer_texte($q['identifiant'] ?? '', 30);
    if (!limiter('oubli:' . ip_client(), 5, 3600)) {
        erreur('Trop de demandes. Réessaie dans une heure.', 429);
    }
    $st = db()->prepare('SELECT * FROM utilisateurs WHERE identifiant = ? AND actif = 1');
    $st->execute([$id]);
    $u = $st->fetch();
    $r = reglages();
    if ($u && !$u['admin']) {
        repondre(['ok' => true, 'message' => 'Demande à ton responsable de te remettre un mot de passe : dans l’appli, Plus → L’équipe → Mot de passe.']);
    }
    if ($u && $r['alertes_email']) {
        $j = jeton(24);
        db()->prepare('DELETE FROM reinit_mdp WHERE expire < ? OR utilisateur_id = ?')->execute([time(), $u['id']]);
        db()->prepare('INSERT INTO reinit_mdp (jeton, utilisateur_id, expire) VALUES (?, ?, ?)')->execute([hash('sha256', $j), $u['id'], time() + 3600]);
        $lien = rtrim($r['site'], '/') . '/equipe/#reinit=' . $j;
        [$html, $txt] = gabarit_mail('Nouveau mot de passe', [
            "Bonjour {$u['nom']},",
            "Une demande de nouveau mot de passe a été faite pour l'identifiant « {$u['identifiant']} » de l'appli des réservations. Le lien ci-dessous est valable 1 heure.",
            "Si ce n'est pas toi, ignore simplement cet e-mail : rien ne change.",
        ], [], ['Choisir un nouveau mot de passe', $lien]);
        foreach ($r['alertes_email'] as $a) {
            envoyer_mail($a, 'Réservations du 76 : nouveau mot de passe', $html, $txt);
        }
    }
    repondre(['ok' => true, 'message' => 'Si cet identifiant est celui d’un responsable, un lien vient de partir à l’adresse des alertes (regarde aussi les spams).']);
}

function mdp_reinit(array $q): never
{
    $j = (string) ($q['jeton'] ?? '');
    $mdp = (string) ($q['mdp'] ?? '');
    if (!limiter('reinit:' . ip_client(), 10, 3600)) {
        erreur('Trop d’essais.', 429);
    }
    $st = db()->prepare('SELECT * FROM reinit_mdp WHERE jeton = ? AND expire > ?');
    $st->execute([hash('sha256', $j), time()]);
    $x = $st->fetch();
    if (!$x) {
        erreur('Ce lien a expiré ou a déjà servi. Refais une demande « Mot de passe oublié ».');
    }
    if (mb_strlen($mdp) < 6) {
        erreur('Le mot de passe doit faire au moins 6 caractères.');
    }
    db()->prepare('UPDATE utilisateurs SET mdp = ? WHERE id = ?')->execute([password_hash($mdp, PASSWORD_DEFAULT), $x['utilisateur_id']]);
    db()->prepare('DELETE FROM reinit_mdp WHERE utilisateur_id = ?')->execute([$x['utilisateur_id']]);
    db()->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$x['utilisateur_id']]);
    ouvrir_session((int) $x['utilisateur_id']);
    repondre(['ok' => true]);
}

function mdp_changer(array $q, array $moi): never
{
    if (!password_verify((string) ($q['ancien'] ?? ''), $moi['mdp'])) {
        erreur('Ancien mot de passe incorrect.');
    }
    $n = (string) ($q['nouveau'] ?? '');
    if (mb_strlen($n) < 6) {
        erreur('Le mot de passe doit faire au moins 6 caractères.');
    }
    db()->prepare('UPDATE utilisateurs SET mdp = ? WHERE id = ?')->execute([password_hash($n, PASSWORD_DEFAULT), $moi['id']]);
    repondre(['ok' => true]);
}

function liste_equipe(): array
{
    $o = [];
    foreach (db()->query('SELECT u.id, u.nom, u.identifiant, u.admin, u.actif, (SELECT COUNT(*) FROM push p WHERE p.utilisateur_id = u.id) AS telephones FROM utilisateurs u ORDER BY u.actif DESC, u.nom') as $u) {
        $o[] = ['id' => (int) $u['id'], 'nom' => $u['nom'], 'identifiant' => $u['identifiant'], 'admin' => (bool) $u['admin'], 'actif' => (bool) $u['actif'], 'telephones' => (int) $u['telephones']];
    }
    return $o;
}

function equipe_ajouter(array $q): never
{
    $nom = nettoyer_texte($q['nom'] ?? '', 40);
    $id = nettoyer_texte($q['identifiant'] ?? '', 30);
    $mdp = (string) ($q['mdp'] ?? '');
    verifier_identifiants($id, $mdp, $nom);
    try {
        db()->prepare('INSERT INTO utilisateurs (nom, identifiant, mdp, admin, cree_le) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nom, $id, password_hash($mdp, PASSWORD_DEFAULT), empty($q['admin']) ? 0 : 1, maintenant()]);
    } catch (PDOException $e) {
        erreur('Cet identifiant existe déjà.');
    }
    repondre(['ok' => true, 'equipe' => liste_equipe()]);
}

function equipe_supprimer(array $q, array $moi): never
{
    $id = (int) ($q['id'] ?? 0);
    if ($id === (int) $moi['id']) {
        erreur('Tu ne peux pas supprimer ton propre compte.');
    }
    foreach (['sessions', 'push', 'reinit_mdp'] as $t) {
        db()->prepare("DELETE FROM $t WHERE utilisateur_id = ?")->execute([$id]);
    }
    db()->prepare('DELETE FROM utilisateurs WHERE id = ?')->execute([$id]);
    repondre(['ok' => true, 'equipe' => liste_equipe()]);
}

function equipe_modifier(array $q, array $moi): never
{
    $id = (int) ($q['id'] ?? 0);
    if ($id === (int) $moi['id'] && (isset($q['admin']) || isset($q['actif']))) {
        erreur('Tu ne peux pas retirer tes propres droits.');
    }
    if (isset($q['actif'])) {
        db()->prepare('UPDATE utilisateurs SET actif = ? WHERE id = ?')->execute([$q['actif'] ? 1 : 0, $id]);
        if (!$q['actif']) {
            db()->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM push WHERE utilisateur_id = ?')->execute([$id]);
        }
    }
    if (isset($q['admin'])) {
        db()->prepare('UPDATE utilisateurs SET admin = ? WHERE id = ?')->execute([$q['admin'] ? 1 : 0, $id]);
    }
    if (!empty($q['mdp'])) {
        if (mb_strlen((string) $q['mdp']) < 6) {
            erreur('Le mot de passe doit faire au moins 6 caractères.');
        }
        db()->prepare('UPDATE utilisateurs SET mdp = ? WHERE id = ?')->execute([password_hash((string) $q['mdp'], PASSWORD_DEFAULT), $id]);
        db()->prepare('DELETE FROM sessions WHERE utilisateur_id = ?')->execute([$id]);
    }
    repondre(['ok' => true, 'equipe' => liste_equipe()]);
}

/* ------------------------------------------------------------------ */
/* Carnet                                                              */
/* ------------------------------------------------------------------ */

function resa_equipe(array $r): array
{
    foreach (['id', 'couverts', 'arrive'] as $c) {
        $r[$c] = (int) $r[$c];
    }
    $r['arrive'] = (bool) $r['arrive'];
    unset($r['jeton']);
    return $r;
}

function charger_resa(int $id): array
{
    $st = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: erreur('Réservation introuvable.', 404);
}

function nb_demandes(): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM reservations WHERE statut = 'attente' AND date >= ?");
    $st->execute([date('Y-m-d')]);
    return (int) $st->fetchColumn();
}

function jour(array $q): never
{
    $date = (string) ($q['date'] ?? date('Y-m-d'));
    if (!date_valide($date)) {
        erreur('Date invalide.');
    }
    $st = db()->prepare('SELECT * FROM reservations WHERE date = ? ORDER BY heure, cree_le');
    $st->execute([$date]);
    $resas = array_map('resa_equipe', $st->fetchAll());
    $services = [];
    foreach (SERVICES as $s) {
        $services[$s] = etat_service($date, $s);
        $services[$s]['creneaux'] = creneaux($services[$s]['debut'], $services[$s]['fin'], (int) reglages()['pas']);
    }
    repondre(['ok' => true, 'date' => $date, 'services' => $services, 'reservations' => $resas, 'demandes' => nb_demandes()]);
}

/** Réservations arrivées du site et annulations des clients depuis un moment donné (alertes à l'écran). */
function evenements(array $q): never
{
    $depuis = (string) ($q['depuis'] ?? '');
    $maintenant = maintenant();
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $depuis)) {
        repondre(['ok' => true, 'maintenant' => $maintenant, 'evenements' => []]);
    }
    $st = db()->prepare("SELECT * FROM reservations WHERE (source = 'site' AND cree_le > ?)
        OR (statut = 'annulee' AND traite_par = 'Client (lien e-mail)' AND modifie_le > ?) ORDER BY id LIMIT 30");
    $st->execute([$depuis, $depuis]);
    $o = [];
    foreach ($st->fetchAll() as $r) {
        $annul = $r['statut'] === 'annulee' && $r['traite_par'] === 'Client (lien e-mail)' && $r['modifie_le'] > $depuis;
        $o[] = ['type' => $annul ? 'annulee' : 'nouvelle', 'reservation' => resa_equipe($r)];
    }
    repondre(['ok' => true, 'maintenant' => $maintenant, 'evenements' => $o]);
}

function demandes(): never
{
    $st = db()->prepare("SELECT * FROM reservations WHERE statut = 'attente' AND date >= ? ORDER BY date, heure");
    $st->execute([date('Y-m-d')]);
    $o = [];
    $cache = [];
    foreach ($st->fetchAll() as $r) {
        $k = $r['date'] . '|' . $r['service'];
        $cache[$k] ??= etat_service($r['date'], $r['service']);
        $x = resa_equipe($r);
        $x['etat'] = ['capacite' => $cache[$k]['capacite'], 'occupe' => $cache[$k]['occupe'], 'soiree' => $cache[$k]['soiree']];
        $o[] = $x;
    }
    repondre(['ok' => true, 'demandes' => $o]);
}

function avenir(array $q): never
{
    $debut = date_valide($q['debut'] ?? null) ? $q['debut'] : date('Y-m-d');
    $nb = max(1, min(62, (int) ($q['jours'] ?? 21)));
    $fin = date('Y-m-d', strtotime("$debut +" . ($nb - 1) . ' days'));
    $exc = exceptions_jours($debut, $fin);
    $occ = occupation($debut, $fin);
    $st = db()->prepare("SELECT date, service, statut, SUM(couverts) AS c, COUNT(*) AS n FROM reservations
        WHERE date BETWEEN ? AND ? AND statut IN ('attente','confirmee') GROUP BY date, service, statut");
    $st->execute([$debut, $fin]);
    $detail = [];
    foreach ($st as $l) {
        $detail[$l['date'] . '|' . $l['service']][$l['statut']] = ['couverts' => (int) $l['c'], 'resas' => (int) $l['n']];
    }
    $jours = [];
    for ($t = strtotime($debut); date('Y-m-d', $t) <= $fin; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $sv = [];
        foreach (SERVICES as $s) {
            $e = etat_service($d, $s, $exc, $occ);
            $sv[$s] = [
                'ouvert' => $e['ouvert'], 'en_ligne' => $e['en_ligne'], 'raison' => $e['raison'],
                'capacite' => $e['capacite'], 'occupe' => $e['occupe'], 'soiree' => $e['soiree'],
                'confirmees' => $detail["$d|$s"]['confirmee'] ?? ['couverts' => 0, 'resas' => 0],
                'attente' => $detail["$d|$s"]['attente'] ?? ['couverts' => 0, 'resas' => 0],
            ];
        }
        $jours[] = ['date' => $d, 'services' => $sv];
    }
    repondre(['ok' => true, 'jours' => $jours]);
}

function chercher(array $q): never
{
    $t = nettoyer_texte($q['q'] ?? '', 60);
    if (mb_strlen($t) < 2) {
        repondre(['ok' => true, 'resultats' => []]);
    }
    $chiffres = preg_replace('/\D/', '', $t);
    $sql = "SELECT * FROM reservations WHERE date >= ? AND (nom LIKE ? OR email LIKE ?";
    $p = [date('Y-m-d', strtotime('-60 days')), "%$t%", "%$t%"];
    if (strlen($chiffres) >= 4) {
        $sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(telephone,' ',''),'.',''),'-',''),'/','') LIKE ?";
        $p[] = "%$chiffres%";
    }
    $sql .= ') ORDER BY date DESC, heure LIMIT 60';
    $st = db()->prepare($sql);
    $st->execute($p);
    repondre(['ok' => true, 'resultats' => array_map('resa_equipe', $st->fetchAll())]);
}

function enregistrer(array $q, array $moi): never
{
    $id = (int) ($q['id'] ?? 0);
    $date = (string) ($q['date'] ?? '');
    $heure = (string) ($q['heure'] ?? '');
    $couverts = (int) ($q['couverts'] ?? 0);
    $nom = nettoyer_texte($q['nom'] ?? '', 80);
    $tel = nettoyer_texte($q['telephone'] ?? '', 25);
    $email = mb_strtolower(nettoyer_texte($q['email'] ?? '', 120));
    if (!date_valide($date) || !heure_valide($heure)) {
        erreur('Choisis une date et une heure.');
    }
    if ($couverts < 1 || $couverts > 500) {
        erreur('Nombre de personnes invalide.');
    }
    if ($nom === '') {
        erreur('Indique un nom.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        erreur("L'adresse e-mail ne semble pas correcte.");
    }
    $source = in_array($q['source'] ?? '', ['telephone', 'sur_place', 'site', 'autre'], true) ? $q['source'] : 'telephone';
    $champs = [
        'date' => $date, 'service' => service_de($heure), 'heure' => $heure, 'couverts' => $couverts,
        'nom' => $nom, 'telephone' => $tel, 'email' => $email,
        'remarque' => nettoyer_texte($q['remarque'] ?? '', 500),
        'note_interne' => nettoyer_texte($q['note_interne'] ?? '', 500),
    ];
    if ($id) {
        $avant = charger_resa($id);
        $champs['modifie_le'] = maintenant();
        $champs['id'] = $id;
        db()->prepare('UPDATE reservations SET date=:date, service=:service, heure=:heure, couverts=:couverts, nom=:nom, telephone=:telephone,
            email=:email, remarque=:remarque, note_interne=:note_interne, modifie_le=:modifie_le WHERE id=:id')->execute($champs);
        $resa = charger_resa($id);
        $change = $avant['date'] !== $date || $avant['heure'] !== $heure || (int) $avant['couverts'] !== $couverts;
        if (!empty($q['envoyer_email']) && $change && $resa['statut'] === 'confirmee') {
            mail_client($resa, 'confirmee', 'Votre réservation a été modifiée : voici les nouveaux détails.');
        }
    } else {
        $champs += [
            'statut' => 'confirmee', 'source' => $source, 'jeton' => jeton(), 'cree_le' => maintenant(),
            'cree_par' => $moi['nom'], 'traite_par' => $moi['nom'],
        ];
        db()->prepare('INSERT INTO reservations (date, service, heure, couverts, nom, telephone, email, remarque, note_interne, statut, source, jeton, cree_le, cree_par, traite_par)
            VALUES (:date, :service, :heure, :couverts, :nom, :telephone, :email, :remarque, :note_interne, :statut, :source, :jeton, :cree_le, :cree_par, :traite_par)')->execute($champs);
        $resa = charger_resa((int) db()->lastInsertId());
        if (!empty($q['envoyer_email'])) {
            mail_client($resa, 'confirmee');
        }
    }
    $etat = etat_service($resa['date'], $resa['service']);
    repondre(['ok' => true, 'reservation' => resa_equipe($resa), 'depasse' => $etat['occupe'] > $etat['capacite'] ? $etat['occupe'] - $etat['capacite'] : 0]);
}

function changer_statut(array $q, array $moi): never
{
    $resa = charger_resa((int) ($q['id'] ?? 0));
    $statut = (string) ($q['statut'] ?? '');
    if (!in_array($statut, STATUTS, true)) {
        erreur('Statut inconnu.');
    }
    db()->prepare('UPDATE reservations SET statut = ?, modifie_le = ?, traite_par = ? WHERE id = ?')
        ->execute([$statut, maintenant(), $moi['nom'], $resa['id']]);
    $resa['statut'] = $statut;
    $mail = false;
    if (!empty($q['prevenir']) && $statut !== 'attente') {
        $mail = mail_client($resa, $statut, nettoyer_texte($q['message'] ?? '', 600));
    }
    repondre(['ok' => true, 'reservation' => resa_equipe($resa), 'mail' => $mail, 'demandes' => nb_demandes()]);
}

function arrive(array $q): never
{
    $resa = charger_resa((int) ($q['id'] ?? 0));
    db()->prepare('UPDATE reservations SET arrive = ? WHERE id = ?')->execute([empty($q['arrive']) ? 0 : 1, $resa['id']]);
    repondre(['ok' => true]);
}

/** Réglage d'un service précis (ex. fermer la résa en ligne samedi soir). */
function jour_reglage(array $q): never
{
    $date = (string) ($q['date'] ?? '');
    $service = (string) ($q['service'] ?? '');
    if (!date_valide($date) || !in_array($service, SERVICES, true)) {
        erreur('Date ou service invalide.');
    }
    $st = db()->prepare('SELECT * FROM jours WHERE date = ? AND service = ?');
    $st->execute([$date, $service]);
    $x = $st->fetch() ?: ['ouvert' => null, 'ferme_en_ligne' => 0, 'capacite' => null, 'menu_impose' => null, 'note' => ''];
    $nul_ou_int = fn($v) => ($v === null || $v === '' || $v === 'auto') ? null : (int) $v;
    if (array_key_exists('ouvert', $q)) {
        $x['ouvert'] = $q['ouvert'] === null || $q['ouvert'] === 'auto' ? null : ($q['ouvert'] ? 1 : 0);
    }
    if (array_key_exists('ferme_en_ligne', $q)) {
        $x['ferme_en_ligne'] = $q['ferme_en_ligne'] ? 1 : 0;
    }
    if (array_key_exists('capacite', $q)) {
        $c = $nul_ou_int($q['capacite']);
        $x['capacite'] = $c === null ? null : max(0, min(2000, $c));
    }
    if (array_key_exists('menu_impose', $q)) {
        $x['menu_impose'] = $q['menu_impose'] === null || $q['menu_impose'] === 'auto' ? null : ($q['menu_impose'] ? 1 : 0);
    }
    if (array_key_exists('note', $q)) {
        $x['note'] = nettoyer_texte($q['note'], 300);
    }
    if ($x['ouvert'] === null && !$x['ferme_en_ligne'] && $x['capacite'] === null && $x['menu_impose'] === null && $x['note'] === '') {
        db()->prepare('DELETE FROM jours WHERE date = ? AND service = ?')->execute([$date, $service]);
    } else {
        db()->prepare('INSERT INTO jours (date, service, ouvert, ferme_en_ligne, capacite, menu_impose, note) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(date, service) DO UPDATE SET ouvert = excluded.ouvert, ferme_en_ligne = excluded.ferme_en_ligne,
            capacite = excluded.capacite, menu_impose = excluded.menu_impose, note = excluded.note')
            ->execute([$date, $service, $x['ouvert'], $x['ferme_en_ligne'], $x['capacite'], $x['menu_impose'], $x['note']]);
    }
    repondre(['ok' => true, 'etat' => etat_service($date, $service)]);
}

function soirees_a_venir(): array
{
    $o = [];
    $auj = date('Y-m-d');
    foreach (agenda() as $e) {
        if ($e['date_fin'] < $auj) {
            continue;
        }
        $x = $e;
        if (!$e['fermeture']) {
            $x['etat'] = etat_service($e['date'], 'soir');
        }
        $o[] = $x;
    }
    return $o;
}

/* ------------------------------------------------------------------ */
/* Notifications                                                       */
/* ------------------------------------------------------------------ */

function push_abonner(array $q, array $moi): never
{
    $s = $q['abonnement'] ?? null;
    $endpoint = (string) ($s['endpoint'] ?? '');
    $p256dh = (string) ($s['keys']['p256dh'] ?? '');
    $auth = (string) ($s['keys']['auth'] ?? '');
    if (!preg_match('#^https://#', $endpoint) || strlen(b64u_dec($p256dh)) !== 65 || strlen(b64u_dec($auth)) !== 16) {
        erreur('Abonnement invalide.');
    }
    db()->prepare('INSERT INTO push (endpoint, utilisateur_id, p256dh, auth, cree_le) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(endpoint) DO UPDATE SET utilisateur_id = excluded.utilisateur_id, p256dh = excluded.p256dh, auth = excluded.auth')
        ->execute([$endpoint, $moi['id'], $p256dh, $auth, maintenant()]);
    repondre(['ok' => true]);
}

function push_desabonner(array $q): never
{
    db()->prepare('DELETE FROM push WHERE endpoint = ?')->execute([(string) ($q['endpoint'] ?? '')]);
    repondre(['ok' => true]);
}

function mail_test(): never
{
    $r = reglages();
    if (!$r['alertes_email']) {
        erreur("Ajoute d'abord une adresse e-mail pour les alertes.");
    }
    [$html, $txt] = gabarit_mail('E-mail de test', ['Si tu lis ce message, les e-mails des réservations du 76 fonctionnent.', 'Pense à vérifier qu’il n’est pas arrivé dans les indésirables (spam).']);
    $ok = true;
    foreach ($r['alertes_email'] as $a) {
        $ok = envoyer_mail($a, 'Test des réservations du 76', $html, $txt) && $ok;
    }
    $smtp = smtp_public(true);
    $ok ? repondre(['ok' => true, 'via' => $smtp['actif'] ? 'smtp' : 'site'])
        : erreur($smtp['actif'] ? "La messagerie a refusé l'envoi : " . ($smtp['derniere_erreur'] ?: 'raison inconnue') : "L'hébergement a refusé l'envoi de l'e-mail.");
}

function export_csv(): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservations-le76-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    $cols = ['id', 'date', 'heure', 'service', 'couverts', 'nom', 'telephone', 'email', 'remarque', 'note_interne', 'statut', 'source', 'arrive', 'cree_le', 'cree_par', 'traite_par'];
    fputcsv($f, $cols, ';');
    foreach (db()->query('SELECT ' . implode(',', $cols) . ' FROM reservations ORDER BY date, heure') as $l) {
        fputcsv($f, $l, ';');
    }
    exit;
}

function export_abonnes(): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients-infos-le76-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Nom', 'E-mail', 'Téléphone', 'Inscrit le', 'Lien de désinscription (à mettre dans chaque envoi)'], ';');
    $site = rtrim(reglages()['site'], '/');
    foreach (db()->query('SELECT * FROM abonnes WHERE desinscrit_le IS NULL ORDER BY nom') as $a) {
        fputcsv($f, [$a['nom'], $a['email'], $a['telephone'], substr($a['inscrit_le'], 0, 10), $site . '/reserver/?desinscrire=' . $a['jeton']], ';');
    }
    exit;
}
