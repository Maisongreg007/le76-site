<?php
/**
 * Paiement par carte en ligne avec SumUp (page de paiement hébergée par SumUp).
 *
 * - La clé API et le code marchand sont saisis par l'équipe dans les réglages ; ils restent sur le serveur
 *   (dossier des données) et ne sont jamais renvoyés au navigateur.
 * - Une commande n'est validée que lorsque SumUp confirme le paiement (statut PAID, bon montant, en euros) :
 *   au retour du client, à la notification de SumUp, ou au plus tard avant l'annulation automatique.
 */
declare(strict_types=1);

function sumup_api(): string
{
    return rtrim(getenv('SUMUP_API') ?: 'https://api.sumup.com', '/');
}

function sumup_config(): array
{
    $st = db()->prepare("SELECT valeur FROM reglages WHERE cle = 'sumup'");
    $st->execute();
    $v = $st->fetchColumn();
    $c = $v === false ? [] : (json_decode((string) $v, true) ?: []);
    return ['enabled' => (bool) ($c['enabled'] ?? false), 'key' => (string) ($c['key'] ?? ''), 'merchant' => (string) ($c['merchant'] ?? '')];
}

function sumup_actif(): bool
{
    $c = sumup_config();
    return $c['enabled'] && $c['key'] !== '' && $c['merchant'] !== '';
}

/** Vue pour l'équipe : jamais la clé elle-même. */
function sumup_vue(): array
{
    $c = sumup_config();
    return ['enabled' => $c['enabled'], 'merchant' => $c['merchant'], 'hasKey' => $c['key'] !== '', 'keyHint' => $c['key'] !== '' ? substr($c['key'], 0, 7) . '…' . substr($c['key'], -4) : ''];
}

function sumup_enregistrer(array $in): array
{
    $c = sumup_config();
    $c['enabled'] = (bool) ($in['enabled'] ?? $c['enabled']);
    $m = strtoupper(trim((string) ($in['merchant'] ?? $c['merchant'])));
    if ($m !== '' && !preg_match('/^[A-Z0-9]{4,20}$/', $m)) {
        erreur('Code marchand SumUp invalide (ex. MCABC123).');
    }
    $c['merchant'] = $m;
    $k = trim((string) ($in['key'] ?? ''));
    if ($k !== '') {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $k)) {
            erreur('Clé API SumUp invalide.');
        }
        $c['key'] = $k;
    }
    if (!empty($in['removeKey'])) {
        $c['key'] = '';
    }
    if ($c['enabled'] && ($c['key'] === '' || $c['merchant'] === '')) {
        erreur('Pour activer la carte en ligne, enregistre la clé API et le code marchand SumUp.');
    }
    db()->prepare("INSERT INTO reglages (cle, valeur) VALUES ('sumup', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
        ->execute([json_encode($c)]);
    journaliser('équipe', 'Réglages SumUp modifiés', null, $c['enabled'] ? 'carte en ligne activée' : 'carte en ligne désactivée');
    return sumup_vue();
}

/** Appel HTTPS à SumUp. Retourne [code HTTP, réponse décodée]. */
function sumup_requete(string $methode, string $chemin, ?array $corps = null, ?string $cle = null): array
{
    $cle ??= sumup_config()['key'];
    $ch = curl_init(sumup_api() . $chemin);
    $entetes = ['Authorization: Bearer ' . $cle, 'Accept: application/json'];
    $opts = [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
    if ($corps !== null) {
        $entetes[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $entetes;
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) {
        error_log('sumup: ' . $err);
        return [0, []];
    }
    return [$code, json_decode((string) $r, true) ?: []];
}

function sumup_tester(): array
{
    $c = sumup_config();
    if ($c['key'] === '') {
        erreur('Enregistre d’abord la clé API SumUp.');
    }
    [$code, $j] = sumup_requete('GET', '/v0.1/me');
    if ($code === 401) {
        erreur('SumUp refuse la clé API (clé invalide ou révoquée).', 400);
    }
    if ($code !== 200) {
        erreur('Impossible de joindre SumUp (code ' . $code . '). Réessaie dans un instant.', 502);
    }
    $mc = (string) ($j['merchant_profile']['merchant_code'] ?? $j['merchant_code'] ?? '');
    $nom = (string) ($j['merchant_profile']['company_name'] ?? $j['merchant_profile']['doing_business_as']['business_name'] ?? '');
    return ['ok' => true, 'merchant' => $mc, 'name' => $nom, 'matches' => $mc === '' || $mc === $c['merchant']];
}

function url_site(): string
{
    $hote = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'le76.fr'));
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $hote . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/pain/api.php'), '/') . '/';
}

/** Le campeur paie sa commande par carte : création d'une page de paiement SumUp. */
function payer_carte(array $q): never
{
    if (!sumup_actif()) {
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
    $cfg = sumup_config();
    $ref = $c['code'] . '-' . bin2hex(random_bytes(4));
    $base = url_site();
    [$code, $j] = sumup_requete('POST', '/v0.1/checkouts', [
        'checkout_reference' => $ref,
        'amount' => round(((int) $c['total']) / 100, 2),
        'currency' => 'EUR',
        'merchant_code' => $cfg['merchant'],
        'description' => 'Commande ' . $c['code'] . ' · ' . reglages()['title'],
        'redirect_url' => $base . '?c=' . rawurlencode($c['jeton']) . '&paye=1',
        'return_url' => $base . 'api.php?action=sumup',
        'hosted_checkout' => ['enabled' => true],
    ]);
    if (!in_array($code, [200, 201], true) || empty($j['id']) || empty($j['hosted_checkout_url'])) {
        error_log('sumup checkout ' . $code . ' ' . json_encode($j));
        erreur('Le paiement par carte est momentanément indisponible. Réessaie ou paie au comptoir.', 502);
    }
    db()->prepare('UPDATE commandes SET checkout_id = ?, checkout_montant = ?, modifie_le = ? WHERE id = ?')
        ->execute([(string) $j['id'], (int) $c['total'], maintenant(), $c['id']]);
    journaliser('client', 'Paiement carte en ligne commencé', (int) $c['id'], 'SumUp ' . $ref);
    repondre(['ok' => true, 'url' => (string) $j['hosted_checkout_url']]);
}

/**
 * Interroge SumUp pour une commande en attente qui a un paiement en cours.
 * La commande n'est validée que si SumUp indique PAID, en euros, pour le montant exact de la commande.
 */
function verifier_carte(array $c): bool
{
    if ($c['statut'] !== 'pending' || $c['paye'] || empty($c['checkout_id']) || sumup_config()['key'] === '') {
        return false;
    }
    [$code, $j] = sumup_requete('GET', '/v0.1/checkouts/' . rawurlencode((string) $c['checkout_id']));
    if ($code !== 200 || ($j['status'] ?? '') !== 'PAID') {
        return false;
    }
    $montant = (int) round(((float) ($j['amount'] ?? 0)) * 100);
    if (($j['currency'] ?? '') !== 'EUR' || $montant !== (int) $c['total'] || $montant !== (int) $c['checkout_montant']) {
        journaliser('système', 'Paiement carte à vérifier : montant différent', (int) $c['id'], 'payé ' . eur($montant) . ' pour ' . eur((int) $c['total']));
        return false;
    }
    $u = db()->prepare("UPDATE commandes SET statut = 'confirmed', paye = 1, paiement = 'en_ligne', modifie_le = ? WHERE id = ? AND statut = 'pending' AND paye = 0");
    $u->execute([maintenant(), $c['id']]);
    if ($u->rowCount() === 1) {
        journaliser('système', 'Paiement encaissé (Carte en ligne)', (int) $c['id'], eur($montant) . ' · SumUp ' . ($j['transaction_code'] ?? $j['id'] ?? ''));
        return true;
    }
    return false;
}

/** Notification de SumUp (return_url) : on revérifie toujours auprès de SumUp avant de valider. */
function notification_sumup(): never
{
    $q = json_decode(file_get_contents('php://input') ?: '', true);
    $id = is_array($q) ? (string) ($q['id'] ?? '') : '';
    if ($id !== '' && preg_match('/^[A-Za-z0-9\-]{6,80}$/', $id)) {
        $c = commande_par('checkout_id', $id);
        if ($c) {
            verifier_carte($c);
        }
    }
    repondre(['ok' => true]);
}
