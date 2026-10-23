<?php
/**
 * E-mails (clients et équipe) et notifications sur les téléphones de l'équipe.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* E-mails                                                             */
/* ------------------------------------------------------------------ */

function envoyer_mail(string $a, string $sujet, string $html, string $texte, ?string $repondre_a = null): bool
{
    $r = reglages();
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $exp = $r['expediteur'];
    $limite = 'b' . jeton(8);
    $entetes = [
        'From: ' . mb_encode_mimeheader($r['nom'], 'UTF-8') . " <$exp>",
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$limite\"",
        'X-Mailer: Le76-Reservations',
    ];
    $rep = $repondre_a ?: ($r['alertes_email'][0] ?? null);
    if ($rep && filter_var($rep, FILTER_VALIDATE_EMAIL)) {
        $entetes[] = "Reply-To: $rep";
    }
    $corps = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($texte))
        . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$limite--\r\n";
    $sujet_enc = mb_encode_mimeheader($sujet, 'UTF-8', 'B', "\r\n");

    // Pour les tests : les e-mails sont écrits dans un fichier au lieu d'être envoyés.
    if ($journal = getenv('RESA_MAIL_LOG')) {
        file_put_contents($journal, json_encode(['a' => $a, 'sujet' => $sujet, 'texte' => $texte, 'repondre_a' => $rep], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        $ok = true;
    } else {
        $ok = @mail($a, $sujet_enc, $corps, implode("\r\n", $entetes), '-f' . $exp);
    }
    // Journal des envois (consultable dans l'appli : Plus → Journal des e-mails)
    try {
        db()->prepare('INSERT INTO journal_mails (quand, a, sujet, ok) VALUES (?, ?, ?, ?)')->execute([maintenant(), $a, $sujet, $ok ? 1 : 0]);
        db()->exec('DELETE FROM journal_mails WHERE id NOT IN (SELECT id FROM journal_mails ORDER BY id DESC LIMIT 300)');
    } catch (Throwable $e) {
    }
    return $ok;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Mise en page commune des e-mails : titre, paragraphes, encadré récapitulatif, bouton éventuel. */
function gabarit_mail(string $titre, array $paragraphes, array $recap = [], ?array $bouton = null, string $pied = ''): array
{
    $r = reglages();
    $html = '<!doctype html><html><body style="margin:0;background:#fbf5ec;font-family:Helvetica,Arial,sans-serif;color:#2b1d16">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf5ec;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#fffbf5;border:1px solid #e8d9c3;border-radius:14px">'
        . '<tr><td style="padding:22px 26px 6px;font-family:Georgia,serif;font-size:28px;font-weight:bold;color:#2b1d16">Le <span style="color:#c98a3a">76</span></td></tr>'
        . '<tr><td style="padding:6px 26px 4px;font-family:Georgia,serif;font-size:21px;color:#4a2c1d">' . h($titre) . '</td></tr>';
    $txt = "$titre\n\n";
    foreach ($paragraphes as $p) {
        $html .= '<tr><td style="padding:8px 26px;font-size:15px;line-height:1.55">' . nl2br(h($p)) . '</td></tr>';
        $txt .= "$p\n\n";
    }
    if ($recap) {
        $html .= '<tr><td style="padding:10px 26px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3e7d6;border-radius:10px">';
        foreach ($recap as $libelle => $valeur) {
            $html .= '<tr><td style="padding:8px 14px;font-size:13px;color:#7a6a5c;width:38%">' . h($libelle) . '</td>'
                . '<td style="padding:8px 14px;font-size:15px;font-weight:bold">' . nl2br(h($valeur)) . '</td></tr>';
            $txt .= "$libelle : $valeur\n";
        }
        $html .= '</table></td></tr>';
        $txt .= "\n";
    }
    if ($bouton) {
        $html .= '<tr><td style="padding:12px 26px"><a href="' . h($bouton[1]) . '" style="display:inline-block;background:#b7372a;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px">' . h($bouton[0]) . '</a></td></tr>';
        $txt .= "{$bouton[0]} : {$bouton[1]}\n\n";
    }
    $signature = $r['nom'] . ' · ' . $r['adresse'] . ' · ' . $r['telephone'];
    $html .= ($pied ? '<tr><td style="padding:8px 26px;font-size:13px;color:#7a6a5c">' . nl2br(h($pied)) . '</td></tr>' : '')
        . '<tr><td style="padding:16px 26px 22px;font-size:12px;color:#7a6a5c;border-top:1px solid #e8d9c3">' . h($signature) . '<br><a href="' . h($r['site']) . '" style="color:#b7372a">' . h(preg_replace('#^https?://#', '', $r['site'])) . '</a></td></tr>'
        . '</table></td></tr></table></body></html>';
    $txt .= ($pied ? "$pied\n\n" : '') . "--\n$signature\n{$r['site']}\n";
    return [$html, $txt];
}

function recap_resa(array $resa): array
{
    $o = [
        'Date' => ucfirst(date_fr($resa['date'], true)),
        'Heure' => heure_fr($resa['heure']),
        'Personnes' => (string) $resa['couverts'],
        'Au nom de' => $resa['nom'],
    ];
    if (trim($resa['remarque'] ?? '') !== '') {
        $o['Votre message'] = $resa['remarque'];
    }
    return $o;
}

function lien_annulation(array $resa): string
{
    return rtrim(reglages()['site'], '/') . '/reserver/?annuler=' . $resa['jeton'];
}

/** E-mail au client : 'recue' | 'confirmee' | 'refusee' | 'annulee' */
function mail_client(array $resa, string $type, string $message = ''): bool
{
    if (!filter_var($resa['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $r = reglages();
    $quand = date_fr($resa['date']) . ' à ' . heure_fr($resa['heure']);
    $soiree = ($resa['service'] === 'soir') ? evenement_du($resa['date']) : null;
    $recap = recap_resa($resa);
    if ($soiree && !$soiree['fermeture']) {
        $recap = ['Soirée' => $soiree['titre'] . ($soiree['details'] ? "\n" . $soiree['details'] : '')] + $recap;
    }
    $mot = trim($message);
    $bouton = null;
    $pied = '';
    switch ($type) {
        case 'recue':
            $sujet = "Votre demande de réservation au 76 · $quand";
            $titre = 'Demande bien reçue';
            $p = [
                "Bonjour {$resa['nom']},",
                "Merci pour votre demande de réservation. Ce n'est pas encore une réservation confirmée : nous vérifions et vous envoyons très vite un e-mail de confirmation.",
            ];
            $pied = "Une question ou un changement ? Appelez-nous au {$r['telephone']}.";
            break;
        case 'confirmee':
            $sujet = "Réservation confirmée au 76 · $quand";
            $titre = 'Votre table est réservée';
            $p = ["Bonjour {$resa['nom']},", 'Bonne nouvelle : votre réservation est confirmée. Nous avons hâte de vous accueillir !'];
            if ($mot !== '') {
                $p[] = $mot;
            }
            if (trim($r['mot_client']) !== '') {
                $p[] = $r['mot_client'];
            }
            $bouton = ['Annuler ma réservation', lien_annulation($resa)];
            $pied = "Un empêchement ? Merci de nous prévenir en annulant avec le bouton ci-dessus ou au {$r['telephone']} : la table pourra profiter à d'autres clients.";
            break;
        case 'refusee':
            $sujet = "Votre demande de réservation au 76 · $quand";
            $titre = 'Désolés, nous sommes complets';
            $p = ["Bonjour {$resa['nom']},", "Nous ne pouvons malheureusement pas vous accueillir pour cette date et cette heure."];
            if ($mot !== '') {
                $p[] = $mot;
            }
            $p[] = "N'hésitez pas à faire une nouvelle demande pour un autre moment, ou à nous appeler au {$r['telephone']}.";
            $bouton = ['Choisir une autre date', rtrim($r['site'], '/') . '/reserver/'];
            break;
        case 'annulee':
            $sujet = "Réservation annulée · $quand";
            $titre = 'Votre réservation est annulée';
            $p = ["Bonjour {$resa['nom']},", "Votre réservation a bien été annulée."];
            if ($mot !== '') {
                $p[] = $mot;
            }
            $p[] = 'À bientôt au 76 !';
            break;
        default:
            return false;
    }
    [$html, $txt] = gabarit_mail($titre, $p, $recap, $bouton, $pied);
    return envoyer_mail($resa['email'], $sujet, $html, $txt);
}

/** Alerte à l'équipe (e-mail + notification téléphone). */
function alerter_equipe(array $resa, string $type): void
{
    $r = reglages();
    $quand = date_fr($resa['date']) . ' ' . heure_fr($resa['heure']);
    if ($type === 'nouvelle') {
        $titre = "Nouvelle demande : {$resa['couverts']} pers. · $quand";
        $corps = "{$resa['nom']} · {$resa['telephone']}" . ($resa['remarque'] ? " · « {$resa['remarque']} »" : '');
    } else { // annulée par le client
        $titre = "Annulation : {$resa['couverts']} pers. · $quand";
        $corps = "{$resa['nom']} a annulé sa réservation.";
    }
    $lien = rtrim($r['site'], '/') . '/equipe/' . ($type === 'nouvelle' ? '#demandes' : '#jour=' . $resa['date']);

    $recap = recap_resa($resa);
    unset($recap['Votre message']);
    $recap['Téléphone'] = $resa['telephone'];
    $recap['E-mail'] = $resa['email'];
    if ($resa['remarque']) {
        $recap['Message'] = $resa['remarque'];
    }
    [$html, $txt] = gabarit_mail(
        $type === 'nouvelle' ? 'Nouvelle demande de réservation' : 'Réservation annulée par le client',
        [$type === 'nouvelle' ? 'Une demande attend ta réponse.' : 'Le client a annulé depuis le lien de son e-mail. Les places sont libérées.'],
        $recap,
        [$type === 'nouvelle' ? 'Ouvrir les demandes' : 'Voir le carnet', $lien]
    );
    foreach ($r['alertes_email'] as $a) {
        envoyer_mail($a, $titre, $html, $txt, filter_var($resa['email'], FILTER_VALIDATE_EMAIL) ? $resa['email'] : null);
    }
    push_tous($titre, $corps, $lien, 'resa-' . $resa['id']);
}

/* ------------------------------------------------------------------ */
/* Notifications push (Web Push, sans service payant)                  */
/* ------------------------------------------------------------------ */

function b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function cle_ec_nouvelle()
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$k) {
        throw new RuntimeException('OpenSSL : impossible de créer une clé.');
    }
    return $k;
}

function cle_publique_brute($k): string
{
    $d = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

function pem_depuis_publique_brute(string $brute): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $brute;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Clés VAPID du restaurant (créées une fois, gardées dans le dossier des données). */
function vapid(): array
{
    static $v = null;
    if ($v) {
        return $v;
    }
    $f = dossier_donnees() . '/vapid.json';
    if (is_file($f)) {
        $v = json_decode((string) file_get_contents($f), true);
        if (!empty($v['publique']) && !empty($v['privee'])) {
            return $v;
        }
    }
    $k = cle_ec_nouvelle();
    openssl_pkey_export($k, $pem);
    $v = ['publique' => b64u_enc(cle_publique_brute($k)), 'privee' => $pem];
    file_put_contents($f, json_encode($v));
    @chmod($f, 0600);
    return $v;
}

function signature_der_vers_brute(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $o = '';
    for ($i = 0; $i < 2; $i++) {
        $lg = ord($der[$pos + 1]);
        $n = substr($der, $pos + 2, $lg);
        $pos += 2 + $lg;
        $o .= str_pad(ltrim($n, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    return $o;
}

function entete_vapid(string $endpoint): string
{
    $v = vapid();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $contact = reglages()['alertes_email'][0] ?? reglages()['expediteur'];
    $data = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
        . b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . $contact]));
    openssl_sign($data, $der, openssl_pkey_get_private($v['privee']), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $data . '.' . b64u_enc(signature_der_vers_brute($der)) . ', k=' . $v['publique'];
}

/** Chiffrement du message (RFC 8291, aes128gcm). */
function chiffrer_push(string $message, string $p256dh, string $auth): string
{
    $ua_pub = b64u_dec($p256dh);
    $secret = b64u_dec($auth);
    $eph = cle_ec_nouvelle();
    $as_pub = cle_publique_brute($eph);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(pem_depuis_publique_brute($ua_pub)), $eph, 32);
    if ($ecdh === false) {
        throw new RuntimeException('Clé du téléphone invalide.');
    }
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua_pub . $as_pub, $secret);
    $sel = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sel);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sel);
    $chiffre = openssl_encrypt($message . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $sel . pack('N', 4096) . chr(strlen($as_pub)) . $as_pub . $chiffre . $tag;
}

/** Renvoie le code HTTP du service de notification (201 = envoyé). */
function push_envoyer(array $abo, array $contenu): int
{
    if (!function_exists('curl_init')) {
        return 0;
    }
    try {
        $corps = chiffrer_push(json_encode($contenu, JSON_UNESCAPED_UNICODE), $abo['p256dh'], $abo['auth']);
        $vapid = entete_vapid($abo['endpoint']);
    } catch (Throwable $e) {
        return 0;
    }
    $c = curl_init($abo['endpoint']);
    curl_setopt_array($c, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corps,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: ' . $vapid,
        ],
    ]);
    curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $code;
}

function push_tous(string $titre, string $corps, string $url, string $tag = '', ?int $seulement_utilisateur = null): int
{
    if (getenv('RESA_PUSH_LOG')) {
        file_put_contents(getenv('RESA_PUSH_LOG'), json_encode([$titre, $corps, $url], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    $sql = 'SELECT p.* FROM push p JOIN utilisateurs u ON u.id = p.utilisateur_id WHERE u.actif = 1';
    $params = [];
    if ($seulement_utilisateur) {
        $sql .= ' AND u.id = ?';
        $params[] = $seulement_utilisateur;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $envoyes = 0;
    foreach ($st->fetchAll() as $abo) {
        $code = push_envoyer($abo, ['titre' => $titre, 'corps' => $corps, 'url' => $url, 'tag' => $tag]);
        if ($code === 404 || $code === 410) {
            db()->prepare('DELETE FROM push WHERE endpoint = ?')->execute([$abo['endpoint']]);
        } elseif ($code >= 200 && $code < 300) {
            $envoyes++;
        }
    }
    return $envoyes;
}
