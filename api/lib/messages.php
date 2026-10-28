<?php
/**
 * E-mails (clients et équipe) et notifications sur les téléphones de l'équipe.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* E-mails                                                             */
/* ------------------------------------------------------------------ */

function envoyer_mail(string $a, string $sujet, string $html, string $texte, ?string $repondre_a = null, array $entetes_en_plus = []): bool
{
    if (defined('DEMO')) {
        return true; // démo : aucun e-mail n'est réellement envoyé
    }
    $r = reglages();
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $exp = $r['expediteur'];
    $limite = 'b' . jeton(8);
    $domaine = substr(strrchr($exp, '@') ?: '@le76.fr', 1);
    $entetes = [
        'Date: ' . date('r'),
        'Message-ID: <' . jeton(12) . '@' . $domaine . '>',
        'From: ' . mb_encode_mimeheader($r['nom'], 'UTF-8') . " <$exp>",
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$limite\"",
    ];
    $rep = $repondre_a ?: ($r['alertes_email'][0] ?? null);
    if ($rep && filter_var($rep, FILTER_VALIDATE_EMAIL)) {
        $entetes[] = "Reply-To: $rep";
    }
    // Entêtes supplémentaires (ex. lien de désinscription en un clic des offres du Club)
    foreach ($entetes_en_plus as $e) {
        if (is_string($e) && !preg_match('/[\r\n]/', $e)) {
            $entetes[] = $e;
        }
    }
    $corps = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($texte))
        . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$limite--\r\n";
    $sujet_enc = mb_encode_mimeheader($sujet, 'UTF-8', 'B', "\r\n");

    $smtp = smtp_config();
    // Pour les tests : les e-mails sont écrits dans un fichier au lieu d'être envoyés.
    if ($journal = getenv('RESA_MAIL_LOG')) {
        file_put_contents($journal, json_encode(['a' => $a, 'sujet' => $sujet, 'texte' => $texte, 'repondre_a' => $rep], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        $ok = true;
    } elseif ($smtp['pret']) {
        // Envoi par la messagerie Hostinger (authentifié : beaucoup moins de spam)
        $ok = smtp_envoyer($smtp, $exp, $a, "To: <$a>\r\nSubject: $sujet_enc\r\n" . implode("\r\n", $entetes) . "\r\n\r\n" . $corps);
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

/* ------------------------------------------------------------------ */
/* Envoi SMTP (messagerie Hostinger), réglé depuis l'appli             */
/* Le mot de passe est rangé dans le dossier des données, jamais dans  */
/* le dépôt ni renvoyé à l'appli.                                      */
/* ------------------------------------------------------------------ */

function smtp_config(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $f = dossier_donnees() . '/smtp.json';
    $c = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $c += ['actif' => false, 'hote' => 'smtp.hostinger.com', 'port' => 465, 'utilisateur' => '', 'mdp' => '', 'derniere_erreur' => ''];
    if ($c['utilisateur'] === '') {
        $c['utilisateur'] = reglages()['expediteur']; // boîte vide = l'adresse d'envoi
    }
    $c['pret'] = $c['actif'] && $c['utilisateur'] !== '' && $c['mdp'] !== '';
    return $c;
}

function smtp_enregistrer(array $n): array
{
    $c = smtp_config();
    unset($c['pret']);
    if (array_key_exists('actif', $n)) {
        $c['actif'] = (bool) $n['actif'];
    }
    if (!empty($n['hote']) && preg_match('/^[a-z0-9.-]+$/i', (string) $n['hote'])) {
        $c['hote'] = (string) $n['hote'];
    }
    if (!empty($n['port']) && in_array((int) $n['port'], [465, 587], true)) {
        $c['port'] = (int) $n['port'];
    }
    if (isset($n['utilisateur']) && ($n['utilisateur'] === '' || filter_var($n['utilisateur'], FILTER_VALIDATE_EMAIL))) {
        $c['utilisateur'] = (string) $n['utilisateur'];
    }
    if (isset($n['mdp']) && $n['mdp'] !== '') {
        $c['mdp'] = (string) $n['mdp'];
    }
    $f = dossier_donnees() . '/smtp.json';
    file_put_contents($f, json_encode($c));
    @chmod($f, 0600);
    return smtp_public(true);
}

function smtp_public(bool $recharger = false): array
{
    if ($recharger) {
        // vider le cache statique
        $f = dossier_donnees() . '/smtp.json';
        $c = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $c += ['actif' => false, 'hote' => 'smtp.hostinger.com', 'port' => 465, 'utilisateur' => '', 'mdp' => '', 'derniere_erreur' => ''];
    } else {
        $c = smtp_config();
    }
    return ['actif' => (bool) $c['actif'], 'hote' => $c['hote'], 'port' => (int) $c['port'], 'utilisateur' => $c['utilisateur'], 'mdp_enregistre' => $c['mdp'] !== '', 'derniere_erreur' => $c['derniere_erreur'] ?? ''];
}

function smtp_noter_erreur(string $e): void
{
    $f = dossier_donnees() . '/smtp.json';
    $c = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $c['derniere_erreur'] = $e === '' ? '' : date('d/m H:i') . ' : ' . $e;
    file_put_contents($f, json_encode($c));
}

function smtp_envoyer(array $c, string $de, string $a, string $message): bool
{
    $erreur = '';
    $ok = (function () use ($c, $de, $a, $message, &$erreur): bool {
        $hote = ((int) $c['port'] === 465 ? 'ssl://' : 'tcp://') . $c['hote'] . ':' . (int) $c['port'];
        $s = @stream_socket_client($hote, $no, $msg, 12, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if (!$s) {
            $erreur = "connexion impossible ($msg)";
            return false;
        }
        stream_set_timeout($s, 15);
        $lire = function () use ($s): string {
            $r = '';
            while (($l = fgets($s, 1024)) !== false) {
                $r .= $l;
                if (strlen($l) < 4 || $l[3] === ' ') {
                    break;
                }
            }
            return $r;
        };
        $dire = function (string $cmd, array $codes) use ($s, $lire, &$erreur): bool {
            if ($cmd !== '') {
                fwrite($s, $cmd . "\r\n");
            }
            $r = $lire();
            if (!in_array((int) substr($r, 0, 3), $codes, true)) {
                $erreur = trim(preg_replace('/AUTH PLAIN .*/', 'AUTH PLAIN ***', $cmd) . ' → ' . $r);
                return false;
            }
            return true;
        };
        $hello = 'EHLO ' . (gethostname() ?: 'le76.fr');
        if (!$dire('', [220]) || !$dire($hello, [250])) {
            return false;
        }
        if ((int) $c['port'] === 587) {
            if (!$dire('STARTTLS', [220]) || !stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$dire($hello, [250])) {
                $erreur = $erreur ?: 'STARTTLS impossible';
                return false;
            }
        }
        if (!$dire('AUTH PLAIN ' . base64_encode("\0{$c['utilisateur']}\0{$c['mdp']}"), [235])) {
            $erreur = 'identifiant ou mot de passe de la boîte refusé';
            return false;
        }
        // Expéditeur : l'adresse d'envoi (alias) si le serveur l'accepte, sinon la boîte elle-même
        if (!$dire("MAIL FROM:<$de>", [250])) {
            $dire('RSET', [250]);
            if (!$dire("MAIL FROM:<{$c['utilisateur']}>", [250])) {
                return false;
            }
        }
        if (!$dire("RCPT TO:<$a>", [250, 251]) || !$dire('DATA', [354])) {
            return false;
        }
        $message = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message));
        if (!$dire($message . "\r\n.", [250])) {
            return false;
        }
        fwrite($s, "QUIT\r\n");
        fclose($s);
        return true;
    })();
    smtp_noter_erreur($ok ? '' : $erreur);
    return $ok;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Mise en page commune des e-mails : titre, paragraphes, encadré récapitulatif, bouton éventuel. */
function gabarit_mail(string $titre, array $paragraphes, array $recap = [], ?array $bouton = null, string $pied = '', string $l = 'fr'): array
{
    $r = reglages();
    $site = rtrim($r['site'], '/') . ($l === 'de' ? '/de/' : '');
    $html = '<!doctype html><html lang="' . $l . '"><body style="margin:0;background:#fbf5ec;font-family:Helvetica,Arial,sans-serif;color:#2b1d16">'
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
            $txt .= ($l === 'de' ? "$libelle: $valeur\n" : "$libelle : $valeur\n");
        }
        $html .= '</table></td></tr>';
        $txt .= "\n";
    }
    if ($bouton) {
        $html .= '<tr><td style="padding:12px 26px"><a href="' . h($bouton[1]) . '" style="display:inline-block;background:#b7372a;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px">' . h($bouton[0]) . '</a></td></tr>';
        $txt .= ($l === 'de' ? "{$bouton[0]}: {$bouton[1]}\n\n" : "{$bouton[0]} : {$bouton[1]}\n\n");
    }
    $signature = $r['nom'] . ' · ' . $r['adresse'] . ($l === 'de' ? ', Frankreich' : '') . ' · ' . tel_client($l);
    $html .= ($pied ? '<tr><td style="padding:8px 26px;font-size:13px;color:#7a6a5c">' . nl2br(h($pied)) . '</td></tr>' : '')
        . '<tr><td style="padding:16px 26px 22px;font-size:12px;color:#7a6a5c;border-top:1px solid #e8d9c3">' . h($signature) . '<br><a href="' . h($site) . '" style="color:#b7372a">' . h(rtrim(preg_replace('#^https?://#', '', $site), '/')) . '</a></td></tr>'
        . '</table></td></tr></table></body></html>';
    $txt .= ($pied ? "$pied\n\n" : '') . "--\n$signature\n$site\n";
    return [$html, $txt];
}

/** Numéro du restaurant tel qu'on l'écrit au client (format international pour les Allemands). */
function tel_client(string $l = 'fr'): string
{
    $tel = reglages()['telephone'];
    return $l === 'de' && str_starts_with($tel, '0') ? '+33 ' . substr($tel, 1) : $tel;
}

function langue_resa(array $resa): string
{
    return ($resa['langue'] ?? 'fr') === 'de' ? 'de' : 'fr';
}

function recap_resa(array $resa): array
{
    $l = langue_resa($resa);
    $o = [
        t('Date', 'Datum', $l) => ($l === 'de' ? date_de($resa['date'], true) : ucfirst(date_fr($resa['date'], true))),
        t('Heure', 'Uhrzeit', $l) => heure_l($resa['heure'], $l),
        t('Personnes', 'Personen', $l) => (string) $resa['couverts'],
        t('Au nom de', 'Name', $l) => $resa['nom'],
    ];
    if (trim($resa['remarque'] ?? '') !== '') {
        $o[t('Votre message', 'Ihre Nachricht', $l)] = $resa['remarque'];
    }
    return $o;
}

function lien_annulation(array $resa): string
{
    return rtrim(reglages()['site'], '/') . '/reserver/?annuler=' . $resa['jeton'] . (langue_resa($resa) === 'de' ? '&lang=de' : '');
}

/** E-mail au client : 'recue' | 'confirmee' | 'refusee' | 'annulee' | 'rappel', dans sa langue. */
function mail_client(array $resa, string $type, string $message = ''): bool
{
    if (!filter_var($resa['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $r = reglages();
    $l = langue_resa($resa);
    $de = $l === 'de';
    $tel = tel_client($l);
    $jour = $de ? date_de($resa['date']) : date_fr($resa['date']);
    $heure = heure_l($resa['heure'], $l);
    $quand = $de ? "$jour, $heure" : "$jour à $heure";
    $soiree = ($resa['service'] === 'soir') ? evenement_du($resa['date']) : null;
    $recap = recap_resa($resa);
    if ($soiree && !$soiree['fermeture']) {
        $recap = [t('Soirée', 'Abend', $l) => traduire($soiree['titre'], $l) . ($soiree['details'] ? "\n" . traduire($soiree['details'], $l) : '')] + $recap;
    }
    $bonjour = $de ? "Guten Tag {$resa['nom']}," : "Bonjour {$resa['nom']},";
    $mot_resto = trim($de ? ($r['mot_client_de'] ?? '') : $r['mot_client']);
    $mot = trim($message);
    $bouton = null;
    $pied = '';
    $annuler = [t('Annuler ma réservation', 'Reservierung stornieren', $l), lien_annulation($resa)];
    $empechement = $de
        ? "Sie können doch nicht kommen? Bitte sagen Sie uns Bescheid, über den Button oben oder telefonisch unter $tel: So kann der Tisch an andere Gäste gehen."
        : "Un empêchement ? Merci de nous prévenir en annulant avec le bouton ci-dessus ou au $tel : la table pourra profiter à d'autres clients.";
    switch ($type) {
        case 'recue':
            $sujet = $de ? "Ihre Reservierungsanfrage im Le 76 · $quand" : "Votre demande de réservation au 76 · $quand";
            $titre = t('Demande bien reçue', 'Anfrage erhalten', $l);
            $p = [$bonjour, $de
                ? 'Vielen Dank für Ihre Reservierungsanfrage. Die Reservierung ist noch nicht bestätigt: Wir prüfen sie und schicken Ihnen sehr bald eine Bestätigung per E-Mail.'
                : "Merci pour votre demande de réservation. Ce n'est pas encore une réservation confirmée : nous vérifions et vous envoyons très vite un e-mail de confirmation."];
            $pied = $de ? "Fragen oder Änderungen? Rufen Sie uns an: $tel." : "Une question ou un changement ? Appelez-nous au $tel.";
            break;
        case 'confirmee':
            $sujet = $de ? "Reservierung bestätigt im Le 76 · $quand" : "Réservation confirmée au 76 · $quand";
            $titre = t('Votre table est réservée', 'Ihr Tisch ist reserviert', $l);
            $p = [$bonjour, t('Bonne nouvelle : votre réservation est confirmée. Nous avons hâte de vous accueillir !', 'Gute Nachricht: Ihre Reservierung ist bestätigt. Wir freuen uns auf Ihren Besuch!', $l)];
            if ($mot !== '') {
                $p[] = $mot;
            }
            if ($mot_resto !== '') {
                $p[] = $mot_resto;
            }
            $bouton = $annuler;
            $pied = $empechement;
            break;
        case 'refusee':
            $sujet = $de ? "Ihre Reservierungsanfrage im Le 76 · $quand" : "Votre demande de réservation au 76 · $quand";
            $titre = t('Désolés, nous sommes complets', 'Leider sind wir ausgebucht', $l);
            $p = [$bonjour, t('Nous ne pouvons malheureusement pas vous accueillir pour cette date et cette heure.', 'Leider können wir Sie zu diesem Datum und dieser Uhrzeit nicht empfangen.', $l)];
            if ($mot !== '') {
                $p[] = $mot;
            }
            $p[] = $de ? "Gerne können Sie eine neue Anfrage für einen anderen Termin stellen oder uns unter $tel anrufen."
                : "N'hésitez pas à faire une nouvelle demande pour un autre moment, ou à nous appeler au $tel.";
            $bouton = [t('Choisir une autre date', 'Anderes Datum wählen', $l), rtrim($r['site'], '/') . '/reserver/' . ($de ? '?lang=de' : '')];
            break;
        case 'rappel':
            $sujet = $de ? "Erinnerung: Ihr Tisch im Le 76 morgen um $heure" : "Rappel : votre table au 76 demain à $heure";
            $titre = t('À demain au 76 !', 'Bis morgen im Le 76!', $l);
            $p = [$bonjour, $de ? "Eine kleine Erinnerung: Wir freuen uns, Sie morgen, $jour, um $heure bei uns begrüßen zu dürfen."
                : "Petit rappel : nous avons le plaisir de vous accueillir demain, $jour à $heure."];
            if ($mot_resto !== '') {
                $p[] = $mot_resto;
            }
            $bouton = $annuler;
            $pied = $empechement;
            break;
        case 'annulee':
            $sujet = $de ? "Reservierung storniert · $quand" : "Réservation annulée · $quand";
            $titre = t('Votre réservation est annulée', 'Ihre Reservierung ist storniert', $l);
            $p = [$bonjour, t('Votre réservation a bien été annulée.', 'Ihre Reservierung wurde storniert.', $l)];
            if ($mot !== '') {
                $p[] = $mot;
            }
            $p[] = t('À bientôt au 76 !', 'Bis bald im Le 76!', $l);
            break;
        default:
            return false;
    }
    [$html, $txt] = gabarit_mail($titre, $p, $recap, $bouton, $pied, $l);
    return envoyer_mail($resa['email'], $sujet, $html, $txt);
}

/** Alerte à l'équipe (e-mail + notification téléphone). */
function alerter_equipe(array $resa, string $type): void
{
    $r = reglages();
    $quand = ucfirst(date_fr($resa['date'])) . ' à ' . heure_fr($resa['heure']);
    $qui = "{$resa['nom']} · {$resa['couverts']} pers." . (langue_resa($resa) === 'de' ? ' 🇩🇪' : '');
    if ($type === 'nouvelle') {
        $titre = "🔔 Nouvelle réservation : $qui";
        $corps = "$quand · à valider dans l'appli" . ($resa['remarque'] ? "\n« {$resa['remarque']} »" : '');
    } else { // annulée par le client
        $titre = "❌ Réservation annulée : $qui";
        $corps = "$quand · annulée par le client, les places sont libérées";
    }
    $lien = rtrim($r['site'], '/') . '/equipe/' . ($type === 'nouvelle' ? '#demandes' : '#jour=' . $resa['date']);

    $recap = recap_resa(['langue' => 'fr'] + $resa);
    unset($recap['Votre message']);
    if (langue_resa($resa) === 'de') {
        $recap['Langue'] = '🇩🇪 Client allemand : ses e-mails partent en allemand';
    }
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
    if (defined('DEMO')) {
        return 0;
    }
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
    // Pastille rouge sur l'icône de l'appli : nombre de demandes en attente
    $st0 = db()->prepare("SELECT COUNT(*) FROM reservations WHERE statut = 'attente' AND date >= ?");
    $st0->execute([date('Y-m-d')]);
    $pastille = (int) $st0->fetchColumn();
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
        $code = push_envoyer($abo, ['titre' => $titre, 'corps' => $corps, 'url' => $url, 'tag' => $tag, 'pastille' => $pastille]);
        if ($code === 404 || $code === 410) {
            db()->prepare('DELETE FROM push WHERE endpoint = ?')->execute([$abo['endpoint']]);
        } elseif ($code >= 200 && $code < 300) {
            $envoyes++;
        }
    }
    return $envoyes;
}

/**
 * Rappels de la veille : envoyés une seule fois par réservation confirmée, à partir de l'heure réglée.
 * Lancé en arrière-plan à chaque passage sur l'appli ou la page client (au plus toutes les 10 minutes).
 */
function envoyer_rappels(bool $forcer = false): int
{
    if (defined('DEMO')) {
        return 0;
    }
    $r = reglages();
    if (!$r['rappel_veille'] || date('H:i') < $r['rappel_heure']) {
        return 0;
    }
    $verrou = dossier_donnees() . '/rappels.last';
    if (!$forcer && is_file($verrou) && time() - (int) @file_get_contents($verrou) < 600) {
        return 0;
    }
    @file_put_contents($verrou, (string) time());
    $demain = date('Y-m-d', strtotime('+1 day'));
    $st = db()->prepare("SELECT * FROM reservations WHERE date = ? AND statut = 'confirmee' AND rappel_envoye = 0 AND email != '' AND substr(cree_le, 1, 10) < ?");
    $st->execute([$demain, date('Y-m-d')]);
    $n = 0;
    foreach ($st->fetchAll() as $resa) {
        // marquer d'abord : jamais deux rappels, même si deux passages tombent en même temps
        $up = db()->prepare('UPDATE reservations SET rappel_envoye = 1 WHERE id = ? AND rappel_envoye = 0');
        $up->execute([$resa['id']]);
        if ($up->rowCount() === 1 && mail_client($resa, 'rappel')) {
            $n++;
        }
    }
    return $n;
}

/** Après avoir répondu au navigateur, lance les rappels sans faire attendre personne. */
function rappels_en_arriere_plan(): void
{
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        try {
            envoyer_rappels();
        } catch (Throwable $e) {
            error_log('[rappels] ' . $e->getMessage());
        }
    });
}
