<?php
/**
 * API de la précommande du camping. Requêtes JSON en POST : {"action": "...", ...}
 * Partie publique (campeurs) : catalogue, commander, bon, modifier, annuler.
 * Partie équipe (code d'accès) : tout le reste. Export CSV en GET (?action=export).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

try {
    if (($_GET['action'] ?? '') === 'export') {
        connecte() || erreur('Connecte-toi pour continuer.', 401);
        exporter((string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        erreur('Méthode non autorisée.', 405);
    }
    $q = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($q)) {
        erreur('Requête illisible.');
    }
    $action = (string) ($q['action'] ?? '');

    /* ---------- Public ---------- */
    switch ($action) {
        case 'catalogue':
            $r = reglages();
            repondre(['ok' => true, 'products' => array_map(fn($p) => array_diff_key($p, ['cost' => 1]), produits(true)),
                'settings' => array_intersect_key($r, array_flip(['title', 'place', 'message', 'ordering_open', 'pickup_from', 'pickup_to', 'cutoff_days', 'cutoff_time', 'max_per_product', 'phone_required'])),
                'dates' => jours_commandables(), 'today' => date('Y-m-d'), 'now' => date('Y-m-d H:i'), 'staff' => connecte()]);
        case 'commander':
            commander($q, false);
        case 'bon':
            $c = commande_publique($q);
            repondre(['ok' => true, 'order' => vue_commande($c, connecte()), 'staff' => connecte()]);
        case 'modifier':
            modifier_client($q);
        case 'annuler':
            annuler_client($q);
        case 'etat':
            repondre(['ok' => true, 'installe' => code_hache() !== null, 'connecte' => connecte()]);
        case 'installer':
            installer((string) ($q['code'] ?? ''));
        case 'connexion':
            connexion((string) ($q['code'] ?? ''));
        case 'deconnexion':
            fermer_session();
            repondre(['ok' => true]);
    }

    /* ---------- Équipe ---------- */
    connecte() || erreur('Connecte-toi pour continuer.', 401);
    switch ($action) {
        case 'jour':
            jour_equipe((string) ($q['date'] ?? date('Y-m-d')));
        case 'commandes':
            liste_equipe($q);
        case 'retirer':
            retirer((int) ($q['id'] ?? 0), (bool) ($q['force'] ?? false), (bool) ($q['paid'] ?? true));
        case 'annuler_retrait':
            annuler_retrait((int) ($q['id'] ?? 0));
        case 'payer':
            payer((int) ($q['id'] ?? 0), (bool) ($q['paid'] ?? true));
        case 'creer':
            commander($q, true);
        case 'modifier_equipe':
            modifier_equipe($q);
        case 'annuler_equipe':
            annuler_equipe((int) ($q['id'] ?? 0), texte($q['reason'] ?? '', 200));
        case 'retablir':
            retablir((int) ($q['id'] ?? 0));
        case 'produits':
            repondre(['ok' => true, 'products' => produits(false)]);
        case 'produit':
            enregistrer_produit($q);
        case 'ordre_produits':
            ordonner_produits($q['ids'] ?? null);
        case 'reglages':
            repondre(['ok' => true, 'settings' => reglages(), 'dates' => jours_commandables()]);
        case 'reglages_enregistrer':
            $r = valider_reglages(is_array($q['settings'] ?? null) ? $q['settings'] : []);
            db()->prepare("INSERT INTO reglages (cle, valeur) VALUES ('reglages', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
                ->execute([json_encode($r, JSON_UNESCAPED_UNICODE)]);
            journaliser('équipe', 'Réglages modifiés', null);
            repondre(['ok' => true, 'settings' => $r, 'dates' => jours_commandables()]);
        case 'chiffres':
            chiffres((string) ($q['from'] ?? ''), (string) ($q['to'] ?? ''));
        case 'journal':
            $st = db()->prepare('SELECT j.*, c.code FROM journal j LEFT JOIN commandes c ON c.id = j.commande_id ORDER BY j.id DESC LIMIT 300');
            $st->execute();
            repondre(['ok' => true, 'log' => $st->fetchAll()]);
        case 'code':
            changer_code((string) ($q['old'] ?? ''), (string) ($q['new'] ?? ''));
    }
    erreur('Action inconnue.', 404);
} catch (Throwable $e) {
    error_log('pain: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    repondre(['ok' => false, 'error' => 'Erreur du serveur. Réessaie dans un instant.'], 500);
}

/* ================================================================== */

function commande_publique(array $q): array
{
    if (!limiter('bon:' . ip(), 600, 600)) {
        erreur('Trop de demandes. Réessaie dans quelques minutes.', 429);
    }
    $t = (string) ($q['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{16,40}$/', $t)) {
        erreur('Bon introuvable.', 404);
    }
    $c = commande_par('jeton', $t);
    if (!$c) {
        erreur('Bon introuvable.', 404);
    }
    return $c;
}

function commander(array $q, bool $equipe): never
{
    if (!$equipe && (!limiter('cmd:' . ip(), 60, 3600) || !limiter('cmd:tous', 1500, 3600))) {
        erreur('Trop de commandes depuis cet appareil. Réessaie plus tard ou passe au comptoir.', 429);
    }
    $cle = (string) ($q['key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{10,80}$/', $cle)) {
        erreur('Requête invalide. Recharge la page.');
    }
    if ($deja = commande_par('cle', $cle)) {
        repondre(['ok' => true, 'order' => vue_commande($deja, $equipe), 'replayed' => true]);
    }
    $jour = (string) ($q['date'] ?? '');
    if (!date_valide($jour)) {
        erreur('Choisis un jour de retrait.');
    }
    if (!$equipe && !jour_commandable($jour)) {
        erreur('Ce jour n’est plus disponible à la commande. Choisis un autre jour.', 409);
    }
    if ($equipe && $jour < date('Y-m-d')) {
        erreur('Ce jour est déjà passé.');
    }
    [$nom, $empl, $tel, $rem, $langue] = champs_client($q);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $lignes = lignes_depuis($q['items'] ?? null, $jour, 0, $equipe);
    $now = maintenant();
    $pdo->prepare('INSERT INTO commandes (jeton, code, cle, nom, emplacement, telephone, remarque, langue, jour, statut, paye, total, cout, origine, cree_le, modifie_le)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)')
        ->execute([jeton(), nouveau_code(), $cle, $nom, $empl, $tel, $rem, $langue, $jour, 'confirmed', $equipe ? 'equipe' : 'client', $now, $now]);
    $id = (int) $pdo->lastInsertId();
    [$total, $cout] = enregistrer_lignes($id, $lignes);
    $pdo->prepare('UPDATE commandes SET total = ?, cout = ? WHERE id = ?')->execute([$total, $cout, $id]);
    journaliser($equipe ? 'équipe' : 'client', 'Commande créée', $id, $nom . ' · empl. ' . $empl . ' · ' . $jour . ' · ' . resume_lignes($lignes) . ' · ' . eur($total));
    $pdo->exec('COMMIT');
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', $id), $equipe)]);
}

function modifier_client(array $q): never
{
    if (!limiter('mod:' . ip(), 120, 3600)) {
        erreur('Trop de modifications. Réessaie plus tard.', 429);
    }
    $c = commande_publique($q);
    if ($c['statut'] !== 'confirmed' || !jour_commandable($c['jour'])) {
        erreur('Cette commande ne peut plus être modifiée (heure limite dépassée). Passe au comptoir.', 409);
    }
    $jour = (string) ($q['date'] ?? $c['jour']);
    if (!jour_commandable($jour)) {
        erreur('Ce jour n’est pas disponible à la commande.', 409);
    }
    [$nom, $empl, $tel, $rem, $langue] = champs_client($q);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $lignes = lignes_depuis($q['items'] ?? null, $jour, (int) $c['id']);
    [$total, $cout] = enregistrer_lignes((int) $c['id'], $lignes);
    $pdo->prepare('UPDATE commandes SET nom = ?, emplacement = ?, telephone = ?, remarque = ?, langue = ?, jour = ?, total = ?, cout = ?, modifie_le = ? WHERE id = ? AND statut = ?')
        ->execute([$nom, $empl, $tel, $rem, $langue, $jour, $total, $cout, maintenant(), $c['id'], 'confirmed']);
    journaliser('client', 'Commande modifiée', (int) $c['id'], $jour . ' · ' . resume_lignes($lignes) . ' · ' . eur($total));
    $pdo->exec('COMMIT');
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', (int) $c['id']), false)]);
}

function annuler_client(array $q): never
{
    $c = commande_publique($q);
    if ($c['statut'] !== 'confirmed' || !jour_commandable($c['jour'])) {
        erreur('Cette commande ne peut plus être annulée en ligne (heure limite dépassée). Passe au comptoir.', 409);
    }
    db()->prepare("UPDATE commandes SET statut = 'cancelled', annule_le = ?, modifie_le = ? WHERE id = ? AND statut = 'confirmed'")
        ->execute([maintenant(), maintenant(), $c['id']]);
    journaliser('client', 'Commande annulée par le client', (int) $c['id']);
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', (int) $c['id']), false)]);
}

/* ---------- Équipe ---------- */

function commande_equipe(int $id): array
{
    $c = $id > 0 ? commande_par('id', $id) : null;
    if (!$c) {
        erreur('Commande introuvable.', 404);
    }
    return $c;
}

function jour_equipe(string $jour): never
{
    if (!date_valide($jour)) {
        erreur('Date invalide.');
    }
    $st = db()->prepare('SELECT * FROM commandes WHERE jour = ? ORDER BY CAST(emplacement AS INTEGER), emplacement, nom');
    $st->execute([$jour]);
    $orders = array_map(fn($c) => vue_commande($c, true), $st->fetchAll());
    $prep = [];
    foreach ($orders as $o) {
        if ($o['status'] === 'cancelled') {
            continue;
        }
        foreach ($o['items'] as $l) {
            $prep[$l['id']] ??= ['id' => $l['id'], 'name' => $l['name'], 'emoji' => $l['emoji'], 'total' => 0, 'remaining' => 0];
            $prep[$l['id']]['total'] += $l['qty'];
            if ($o['status'] === 'confirmed') {
                $prep[$l['id']]['remaining'] += $l['qty'];
            }
        }
    }
    $actives = array_filter($orders, fn($o) => $o['status'] !== 'cancelled');
    repondre(['ok' => true, 'date' => $jour, 'orders' => $orders, 'prep' => array_values($prep),
        'summary' => [
            'orders' => count($actives),
            'collected' => count(array_filter($orders, fn($o) => $o['status'] === 'collected')),
            'waiting' => count(array_filter($orders, fn($o) => $o['status'] === 'confirmed')),
            'cancelled' => count($orders) - count($actives),
            'total' => array_sum(array_map(fn($o) => $o['total'], $actives)),
            'paid' => array_sum(array_map(fn($o) => $o['paid'] ? $o['total'] : 0, $actives)),
        ],
        'limit' => limite_commande($jour)->format('Y-m-d H:i'), 'open' => jour_ouvert($jour), 'orderable' => jour_commandable($jour)]);
}

function liste_equipe(array $q): never
{
    $from = (string) ($q['from'] ?? '');
    $to = (string) ($q['to'] ?? '');
    $where = [];
    $args = [];
    if ($from !== '') {
        date_valide($from) || erreur('Date invalide.');
        $where[] = 'jour >= ?';
        $args[] = $from;
    }
    if ($to !== '') {
        date_valide($to) || erreur('Date invalide.');
        $where[] = 'jour <= ?';
        $args[] = $to;
    }
    if (in_array($q['status'] ?? '', P_STATUTS, true)) {
        $where[] = 'statut = ?';
        $args[] = $q['status'];
    }
    $s = trim((string) ($q['q'] ?? ''));
    if ($s !== '') {
        $where[] = '(nom LIKE ? OR emplacement LIKE ? OR code LIKE ? OR telephone LIKE ?)';
        array_push($args, "%$s%", "%$s%", "%$s%", "%$s%");
    }
    $st = db()->prepare('SELECT * FROM commandes' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY jour DESC, id DESC LIMIT 500');
    $st->execute($args);
    repondre(['ok' => true, 'orders' => array_map(fn($c) => vue_commande($c, true), $st->fetchAll())]);
}

/** Retrait atomique : une seule validation possible, même si deux personnes scannent en même temps. */
function retirer(int $id, bool $force, bool $paye): never
{
    $c = commande_equipe($id);
    if ($c['statut'] === 'collected') {
        erreur('Déjà retirée le ' . date('d/m à H:i', strtotime($c['retire_le'])) . '.', 409);
    }
    if ($c['statut'] === 'cancelled') {
        erreur('Cette commande est annulée.', 409);
    }
    if ($c['jour'] !== date('Y-m-d') && !$force) {
        erreur($c['jour'] > date('Y-m-d') ? 'Cette commande est prévue pour un autre jour (' . date('d/m', strtotime($c['jour'])) . ').' : 'Cette commande était prévue le ' . date('d/m', strtotime($c['jour'])) . '.', 409);
    }
    $st = db()->prepare("UPDATE commandes SET statut = 'collected', retire_le = ?, paye = CASE WHEN ? THEN 1 ELSE paye END, modifie_le = ? WHERE id = ? AND statut = 'confirmed'");
    $st->execute([maintenant(), $paye ? 1 : 0, maintenant(), $id]);
    if ($st->rowCount() !== 1) {
        erreur('Cette commande vient d’être retirée par quelqu’un d’autre.', 409);
    }
    journaliser('équipe', 'Retrait', $id, $paye ? 'payé' : 'non payé');
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', $id), true)]);
}

function annuler_retrait(int $id): never
{
    $c = commande_equipe($id);
    $st = db()->prepare("UPDATE commandes SET statut = 'confirmed', retire_le = NULL, modifie_le = ? WHERE id = ? AND statut = 'collected'");
    $st->execute([maintenant(), $id]);
    if ($st->rowCount() !== 1) {
        erreur('Cette commande n’est pas retirée.', 409);
    }
    journaliser('équipe', 'Retrait annulé', $id);
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', $id), true)]);
}

function payer(int $id, bool $paye): never
{
    commande_equipe($id);
    db()->prepare('UPDATE commandes SET paye = ?, modifie_le = ? WHERE id = ?')->execute([$paye ? 1 : 0, maintenant(), $id]);
    journaliser('équipe', $paye ? 'Marquée payée' : 'Marquée non payée', $id);
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', $id), true)]);
}

function modifier_equipe(array $q): never
{
    $c = commande_equipe((int) ($q['id'] ?? 0));
    if ($c['statut'] === 'cancelled') {
        erreur('Rétablis d’abord la commande.', 409);
    }
    $jour = (string) ($q['date'] ?? $c['jour']);
    if (!date_valide($jour)) {
        erreur('Date invalide.');
    }
    [$nom, $empl, $tel, $rem, $langue] = champs_client($q + ['lang' => $c['langue']]);
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $lignes = lignes_depuis($q['items'] ?? null, $jour, (int) $c['id'], true);
    [$total, $cout] = enregistrer_lignes((int) $c['id'], $lignes);
    $pdo->prepare('UPDATE commandes SET nom = ?, emplacement = ?, telephone = ?, remarque = ?, jour = ?, total = ?, cout = ?, modifie_le = ? WHERE id = ?')
        ->execute([$nom, $empl, $tel, $rem, $jour, $total, $cout, maintenant(), $c['id']]);
    journaliser('équipe', 'Commande modifiée', (int) $c['id'], $jour . ' · ' . resume_lignes($lignes) . ' · ' . eur($total));
    $pdo->exec('COMMIT');
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', (int) $c['id']), true)]);
}

function annuler_equipe(int $id, string $motif): never
{
    $c = commande_equipe($id);
    if ($c['statut'] === 'cancelled') {
        erreur('Déjà annulée.', 409);
    }
    db()->prepare("UPDATE commandes SET statut = 'cancelled', annule_le = ?, modifie_le = ? WHERE id = ?")->execute([maintenant(), maintenant(), $id]);
    journaliser('équipe', 'Commande annulée', $id, $motif);
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', $id), true)]);
}

function retablir(int $id): never
{
    $c = commande_equipe($id);
    if ($c['statut'] !== 'cancelled') {
        erreur('Cette commande n’est pas annulée.', 409);
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    // Les limites du jour s'appliquent de nouveau
    lignes_depuis(array_map(fn($l) => ['id' => $l['id'], 'qty' => $l['qty']], lignes_de($id)), $c['jour'], $id, true);
    $pdo->prepare("UPDATE commandes SET statut = 'confirmed', annule_le = NULL, modifie_le = ? WHERE id = ?")->execute([maintenant(), $id]);
    journaliser('équipe', 'Commande rétablie', $id);
    $pdo->exec('COMMIT');
    repondre(['ok' => true, 'order' => vue_commande(commande_par('id', $id), true)]);
}

function centimes(mixed $v, string $nom): int
{
    if (!is_int($v) || $v < 0 || $v > 100000) {
        erreur($nom . ' invalide.');
    }
    return $v;
}

function enregistrer_produit(array $q): never
{
    $p = is_array($q['product'] ?? null) ? $q['product'] : [];
    $nom = texte($p['name'] ?? '', 50, true, 'Le nom du produit');
    $noms = [];
    foreach (['de', 'en', 'nl'] as $l) {
        $t = texte($p['names'][$l] ?? '', 50, false, 'La traduction');
        if ($t !== '') {
            $noms[$l] = $t;
        }
    }
    $emoji = texte($p['emoji'] ?? '', 8);
    $prix = centimes($p['price'] ?? null, 'Le prix de vente');
    if ($prix === 0) {
        erreur('Le prix de vente doit être supérieur à zéro.');
    }
    $cout = centimes($p['cost'] ?? 0, 'Le prix d’achat');
    $limite = $p['dailyLimit'] ?? null;
    if ($limite !== null && (!is_int($limite) || $limite < 0 || $limite > 10000)) {
        erreur('Limite par jour invalide.');
    }
    $actif = (bool) ($p['active'] ?? true);
    $id = (int) ($p['id'] ?? 0);
    if ($id > 0) {
        $st = db()->prepare('SELECT * FROM produits WHERE id = ?');
        $st->execute([$id]);
        $avant = $st->fetch() ?: erreur('Produit introuvable.', 404);
        db()->prepare('UPDATE produits SET nom = ?, noms = ?, emoji = ?, prix = ?, cout = ?, actif = ?, limite_jour = ? WHERE id = ?')
            ->execute([$nom, json_encode($noms, JSON_UNESCAPED_UNICODE), $emoji, $prix, $cout, $actif ? 1 : 0, $limite, $id]);
        journaliser('équipe', 'Produit modifié', null, $nom . ' · ' . eur((int) $avant['prix']) . ' → ' . eur($prix) . ($actif ? '' : ' · retiré de la vente'));
    } else {
        $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre), 0) + 1 FROM produits')->fetchColumn();
        db()->prepare('INSERT INTO produits (nom, noms, emoji, prix, cout, actif, ordre, limite_jour, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nom, json_encode($noms, JSON_UNESCAPED_UNICODE), $emoji, $prix, $cout, $actif ? 1 : 0, $ordre, $limite, maintenant()]);
        journaliser('équipe', 'Produit ajouté', null, $nom . ' · ' . eur($prix));
    }
    repondre(['ok' => true, 'products' => produits(false)]);
}

function ordonner_produits(mixed $ids): never
{
    if (!is_array($ids)) {
        erreur('Ordre invalide.');
    }
    $st = db()->prepare('UPDATE produits SET ordre = ? WHERE id = ?');
    foreach (array_values($ids) as $i => $id) {
        $st->execute([$i + 1, (int) $id]);
    }
    repondre(['ok' => true, 'products' => produits(false)]);
}

function chiffres(string $from, string $to): never
{
    if (!date_valide($from) || !date_valide($to) || $from > $to) {
        erreur('Période invalide.');
    }
    $st = db()->prepare("SELECT jour, statut, paye, total, cout FROM commandes WHERE jour BETWEEN ? AND ? AND statut != 'cancelled'");
    $st->execute([$from, $to]);
    $jours = [];
    foreach ($st->fetchAll() as $c) {
        $j = &$jours[$c['jour']];
        $j ??= ['date' => $c['jour'], 'orders' => 0, 'collected' => 0, 'noshow' => 0, 'total' => 0, 'cost' => 0, 'paid' => 0];
        $j['orders']++;
        $j['total'] += (int) $c['total'];
        $j['cost'] += (int) $c['cout'];
        $j['paid'] += $c['paye'] ? (int) $c['total'] : 0;
        if ($c['statut'] === 'collected') {
            $j['collected']++;
        } elseif ($c['jour'] < date('Y-m-d')) {
            $j['noshow']++;
        }
        unset($j);
    }
    ksort($jours);
    $st = db()->prepare("SELECT l.produit_id id, l.nom name, l.emoji, SUM(l.quantite) qty, SUM(l.quantite * l.prix) total, SUM(l.quantite * l.cout) cost
        FROM lignes l JOIN commandes c ON c.id = l.commande_id WHERE c.jour BETWEEN ? AND ? AND c.statut != 'cancelled' GROUP BY l.produit_id, l.nom ORDER BY qty DESC");
    $st->execute([$from, $to]);
    $produits = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'emoji' => $r['emoji'], 'qty' => (int) $r['qty'], 'total' => (int) $r['total'], 'cost' => (int) $r['cost']], $st->fetchAll());
    repondre(['ok' => true, 'days' => array_values($jours), 'products' => $produits]);
}

function exporter(string $from, string $to): never
{
    if (!date_valide($from) || !date_valide($to)) {
        erreur('Période invalide.');
    }
    $st = db()->prepare('SELECT * FROM commandes WHERE jour BETWEEN ? AND ? ORDER BY jour, id');
    $st->execute([$from, $to]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commandes-camping-' . $from . '-au-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $statuts = ['confirmed' => 'À retirer', 'collected' => 'Retirée', 'cancelled' => 'Annulée'];
    fputcsv($out, ['Jour', 'Code', 'Nom', 'Emplacement', 'Téléphone', 'Détail', 'Total (€)', 'Coût d’achat (€)', 'Statut', 'Payée', 'Créée le', 'Retirée le', 'Remarque'], ';', '"', '');
    foreach ($st->fetchAll() as $c) {
        fputcsv($out, [$c['jour'], $c['code'], $c['nom'], $c['emplacement'], $c['telephone'], resume_lignes(lignes_de((int) $c['id'])),
            number_format($c['total'] / 100, 2, ',', ''), number_format($c['cout'] / 100, 2, ',', ''), $statuts[$c['statut']] ?? $c['statut'],
            $c['paye'] ? 'oui' : 'non', $c['cree_le'], $c['retire_le'] ?? '', $c['remarque']], ';', '"', '');
    }
    exit;
}

function eur(int $c): string
{
    return number_format($c / 100, 2, ',', ' ') . ' €';
}

function installer(string $code): never
{
    if (!code_valide($code)) {
        erreur('Le code doit faire de 4 à 8 chiffres.');
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    if (code_hache() !== null) {
        erreur('Le code est déjà choisi : entre-le pour ouvrir l’espace équipe.', 409);
    }
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('code', ?)")->execute([password_hash($code, PASSWORD_DEFAULT)]);
    $pdo->exec('COMMIT');
    journaliser('équipe', 'Code de l’équipe choisi', null);
    ouvrir_session();
    repondre(['ok' => true]);
}

function connexion(string $code): never
{
    if (!limiter('cnx:' . ip(), 8, 900) || !limiter('cnx:tous', 60, 900)) {
        erreur('Trop d’essais. Réessaie dans 15 minutes.', 429);
    }
    $h = code_hache();
    if ($h === null) {
        erreur('L’espace équipe n’est pas encore installé.', 409);
    }
    if (!password_verify($code, $h)) {
        erreur('Code incorrect.', 401);
    }
    ouvrir_session();
    repondre(['ok' => true]);
}

function changer_code(string $ancien, string $nouveau): never
{
    if (!password_verify($ancien, (string) code_hache())) {
        erreur('Code actuel incorrect.', 401);
    }
    if (!code_valide($nouveau)) {
        erreur('Le nouveau code doit faire de 4 à 8 chiffres.');
    }
    db()->prepare("UPDATE reglages SET valeur = ? WHERE cle = 'code'")->execute([password_hash($nouveau, PASSWORD_DEFAULT)]);
    db()->exec('DELETE FROM sessions');
    ouvrir_session();
    journaliser('équipe', 'Code de l’équipe changé', null);
    repondre(['ok' => true]);
}
