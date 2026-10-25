<?php
/**
 * Paiement par carte en ligne avec Stripe Checkout (page de paiement hébergée par Stripe).
 *
 * - La clé secrète Stripe est saisie par l'équipe dans les réglages ; elle reste sur le serveur
 *   et n'est jamais renvoyée au navigateur.
 * - Une commande n'est validée que lorsque Stripe confirme le paiement (payé, en euros, montant exact) :
 *   au retour du client, à la notification Stripe (facultative), quand l'équipe ouvre ses listes,
 *   ou au plus tard juste avant l'annulation automatique à l'heure limite.
 */
declare(strict_types=1);

function stripe_api(): string
{
    return rtrim(getenv('STRIPE_API') ?: 'https://api.stripe.com', '/');
}

function stripe_config(): array
{
    $st = db()->prepare("SELECT valeur FROM reglages WHERE cle = 'stripe'");
    $st->execute();
    $v = $st->fetchColumn();
    $c = $v === false ? [] : (json_decode((string) $v, true) ?: []);
    return ['enabled' => (bool) ($c['enabled'] ?? false), 'key' => (string) ($c['key'] ?? '')];
}

function carte_active(): bool
{
    $c = stripe_config();
    return $c['enabled'] && $c['key'] !== '';
}

/** Vue pour l'équipe : jamais la clé elle-même. */
function stripe_vue(): array
{
    $k = stripe_config();
    $cle = $k['key'];
    return [
        'enabled' => $k['enabled'],
        'hasKey' => $cle !== '',
        'keyHint' => $cle !== '' ? substr($cle, 0, 8) . '…' . substr($cle, -4) : '',
        'test' => str_contains($cle, '_test_'),
        'webhook' => url_site() . 'api.php?action=stripe',
    ];
}

function stripe_enregistrer(array $in): array
{
    $c = stripe_config();
    $c['enabled'] = (bool) ($in['enabled'] ?? $c['enabled']);
    $k = trim((string) ($in['key'] ?? ''));
    if ($k !== '') {
        if (str_starts_with($k, 'pk_')) {
            erreur('C’est la clé publique (pk_…). Il faut la clé secrète, qui commence par sk_live_ (ou rk_live_).');
        }
        if (!preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,250}$/', $k)) {
            erreur('Clé Stripe invalide : elle commence par sk_live_ (ou sk_test_ pour les essais).');
        }
        $c['key'] = $k;
    }
    if (!empty($in['removeKey'])) {
        $c['key'] = '';
    }
    if ($c['enabled'] && $c['key'] === '') {
        erreur('Pour activer la carte en ligne, enregistre d’abord la clé secrète Stripe.');
    }
    db()->prepare("INSERT INTO reglages (cle, valeur) VALUES ('stripe', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
        ->execute([json_encode($c)]);
    journaliser('équipe', 'Réglages Stripe modifiés', null, $c['enabled'] ? 'carte en ligne activée' : 'carte en ligne désactivée');
    return stripe_vue();
}

/** Appel HTTPS à Stripe (corps en x-www-form-urlencoded). Retourne [code HTTP, réponse décodée]. */
function stripe_requete(string $methode, string $chemin, ?array $corps = null, ?string $cle = null): array
{
    $cle ??= stripe_config()['key'];
    $ch = curl_init(stripe_api() . $chemin);
    $entetes = ['Authorization: Bearer ' . $cle, 'Accept: application/json', 'Stripe-Version: 2024-06-20'];
    $opts = [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
    if ($corps !== null) {
        $entetes[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts[CURLOPT_POSTFIELDS] = http_build_query($corps, '', '&', PHP_QUERY_RFC1738);
    }
    $opts[CURLOPT_HTTPHEADER] = $entetes;
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) {
        error_log('stripe: ' . $err);
        return [0, []];
    }
    return [$code, json_decode((string) $r, true) ?: []];
}

function stripe_tester(): array
{
    if (stripe_config()['key'] === '') {
        erreur('Enregistre d’abord la clé secrète Stripe.');
    }
    [$code, $j] = stripe_requete('GET', '/v1/balance');
    if ($code === 401) {
        erreur('Stripe refuse la clé (clé invalide ou révoquée).', 400);
    }
    if ($code === 403) {
        erreur('La clé est valide mais n’a pas les droits nécessaires (utilise la clé secrète standard).', 400);
    }
    if ($code !== 200) {
        erreur('Impossible de joindre Stripe (code ' . $code . '). Réessaie dans un instant.', 502);
    }
    [$c2, $a] = stripe_requete('GET', '/v1/account');
    $nom = $c2 === 200 ? (string) ($a['settings']['dashboard']['display_name'] ?? $a['business_profile']['name'] ?? '') : '';
    return ['ok' => true, 'name' => $nom, 'live' => (bool) ($j['livemode'] ?? false), 'charges' => $c2 === 200 ? (bool) ($a['charges_enabled'] ?? true) : true];
}

function url_site(): string
{
    $hote = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'le76.fr'));
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $hote . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/pain/api.php'), '/') . '/';
}

/** Le campeur paie sa commande par carte : création d'une page de paiement Stripe Checkout. */
function payer_carte(array $q): never
{
    if (!carte_active()) {
        erreur('Le paiement par carte en ligne n’est pas disponible. Paie au comptoir.', 409);
    }
    if (!limiter('carte:' . ip(), 30, 3600)) {
        erreur('Trop de tentatives. Réessaie plus tard ou paie au comptoir.', 429);
    }
    $c = commande_publique($q);
    verifier_carte($c);
    $c = commande_par('id', (int) $c['id']);
    if ($c['statut'] !== 'pending' || $c['paye']) {
        erreur($c['paye'] ? 'Cette commande est déjà payée.' : 'Cette commande ne peut plus être payée.', 409);
    }
    if (!jour_commandable($c['jour'])) {
        erreur('L’heure limite est dépassée : cette commande ne peut plus être payée en ligne.', 409);
    }
    // La page Stripe expire au plus tard à l'heure limite (Stripe exige au moins 30 minutes).
    $limite = limite_commande($c['jour'], reglages())->getTimestamp();
    $expire = min($limite, time() + 23 * 3600);
    if ($expire - time() < 31 * 60) {
        erreur('Moins de 30 minutes avant l’heure limite : paie au comptoir.', 409);
    }
    $base = url_site();
    $r = reglages();
    $langues = ['fr' => 'fr', 'de' => 'de', 'en' => 'en', 'nl' => 'nl'];
    $retour = $base . '?c=' . rawurlencode($c['jeton']);
    [$code, $j] = stripe_requete('POST', '/v1/checkout/sessions', [
        'mode' => 'payment',
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => (int) $c['total'],
                'product_data' => ['name' => 'Commande ' . $c['code'] . ' · ' . $r['title'], 'description' => resume_lignes(lignes_de((int) $c['id'])) ?: 'Précommande'],
            ],
        ]],
        'client_reference_id' => $c['code'],
        'metadata' => ['commande' => $c['code']],
        'payment_intent_data' => ['description' => 'Commande ' . $c['code'] . ' · ' . trim($c['prenom'] . ' ' . $c['nom']) . ' · empl. ' . $c['emplacement']],
        'locale' => $langues[$c['langue']] ?? 'auto',
        'expires_at' => $expire,
        'success_url' => $retour . '&paye=1',
        'cancel_url' => $retour,
    ]);
    if ($code !== 200 || empty($j['id']) || empty($j['url'])) {
        error_log('stripe checkout ' . $code . ' ' . json_encode($j['error'] ?? $j));
        erreur('Le paiement par carte est momentanément indisponible. Réessaie ou paie au comptoir.', 502);
    }
    db()->prepare('UPDATE commandes SET checkout_id = ?, checkout_montant = ?, modifie_le = ? WHERE id = ?')
        ->execute([(string) $j['id'], (int) $c['total'], maintenant(), $c['id']]);
    journaliser('client', 'Paiement carte en ligne commencé', (int) $c['id'], 'Stripe');
    repondre(['ok' => true, 'url' => (string) $j['url']]);
}

/**
 * Interroge Stripe pour une commande en attente qui a un paiement en cours.
 * La commande n'est validée que si Stripe indique « payé », en euros, pour le montant exact de la commande.
 */
function verifier_carte(array $c): bool
{
    if ($c['statut'] !== 'pending' || $c['paye'] || empty($c['checkout_id']) || stripe_config()['key'] === '') {
        return false;
    }
    [$code, $j] = stripe_requete('GET', '/v1/checkout/sessions/' . rawurlencode((string) $c['checkout_id']));
    if ($code !== 200 || ($j['payment_status'] ?? '') !== 'paid') {
        return false;
    }
    $montant = (int) ($j['amount_total'] ?? -1);
    if (strtolower((string) ($j['currency'] ?? '')) !== 'eur' || $montant !== (int) $c['total'] || $montant !== (int) $c['checkout_montant']) {
        journaliser('système', 'Paiement carte à vérifier : montant différent', (int) $c['id'], 'payé ' . eur(max(0, $montant)) . ' pour ' . eur((int) $c['total']));
        return false;
    }
    $u = db()->prepare("UPDATE commandes SET statut = 'confirmed', paye = 1, paiement = 'en_ligne', modifie_le = ? WHERE id = ? AND statut = 'pending' AND paye = 0");
    $u->execute([maintenant(), $c['id']]);
    if ($u->rowCount() === 1) {
        journaliser('système', 'Paiement encaissé (Carte en ligne)', (int) $c['id'], eur($montant) . ' · Stripe');
        return true;
    }
    return false;
}

/** Vérifie les paiements en cours (quand l'équipe ouvre ses listes), pour voir tout de suite les commandes payées. */
function verifier_cartes_en_cours(): void
{
    if (!carte_active()) {
        return;
    }
    $st = db()->prepare("SELECT * FROM commandes WHERE statut = 'pending' AND paye = 0 AND checkout_id IS NOT NULL AND modifie_le >= ? ORDER BY modifie_le DESC LIMIT 15");
    $st->execute([date('Y-m-d H:i:s', time() - 2 * 86400)]);
    foreach ($st->fetchAll() as $c) {
        verifier_carte($c);
    }
}

/** Notification Stripe (webhook facultatif) : on ne croit jamais le contenu, on revérifie auprès de Stripe. */
function notification_stripe(): never
{
    $q = json_decode(file_get_contents('php://input') ?: '', true);
    $id = is_array($q) ? (string) ($q['data']['object']['id'] ?? '') : '';
    if ($id !== '' && preg_match('/^cs_[A-Za-z0-9_]{6,250}$/', $id)) {
        $c = commande_par('checkout_id', $id);
        if ($c) {
            verifier_carte($c);
        }
    }
    repondre(['ok' => true]);
}
