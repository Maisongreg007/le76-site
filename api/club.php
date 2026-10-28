<?php
/**
 * Le 76 · Le Club : partie publique (page /club, ouverte par le QR des tables).
 *   GET  ?action=infos                       règle de fidélité affichée sur la page
 *   POST {action:"inscription", …}           créer sa carte
 *   POST {action:"carte", jeton}             afficher sa carte (jeton secret gardé sur le téléphone)
 *   POST {action:"accord", jeton, accord}    accepter / refuser les offres par e-mail
 *   POST {action:"desinscrire", jeton}       lien de désinscription des e-mails
 *   POST ?action=desinscrire_1clic&j=…       désinscription « en un clic » des messageries (Gmail, Apple Mail…)
 *   POST {action:"retrouver", email}         recevoir à nouveau le lien de sa carte
 */
declare(strict_types=1);

require __DIR__ . '/lib/base.php';
require __DIR__ . '/lib/messages.php';
require __DIR__ . '/lib/club.php';

try {
    // Désinscription en un clic : envoyée par la messagerie du client (formulaire, pas du JSON)
    if (($_GET['action'] ?? '') === 'desinscrire_1clic') {
        $m = club_membre_par_jeton((string) ($_GET['j'] ?? ''));
        if ($m && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            club_accord($m, false, 'email-1clic');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $m ? 'OK' : 'Lien invalide';
        exit;
    }
    $q = lire_requete();
    langue((string) ($q['lang'] ?? 'fr'));
    switch ($q['action'] ?? '') {
        case 'infos':
            $r = club_reglages();
            repondre(['ok' => true, 'ouvert' => (bool) $r['inscriptions'], 'seuil' => (int) $r['seuil'], 'cadeau' => club_cadeau_texte()]);
        case 'inscription':
            inscription($q);
        case 'carte':
            carte($q);
        case 'accord':
            accord($q, 'carte');
        case 'desinscrire':
            $q['accord'] = false;
            accord($q, 'lien');
        case 'retrouver':
            retrouver($q);
        default:
            erreur(t('Action inconnue.', 'Unbekannte Aktion.'));
    }
} catch (Throwable $e) {
    error_log('[club] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur(t('Un problème technique est survenu. Merci de réessayer ou de demander à l’équipe.', 'Ein technisches Problem ist aufgetreten. Bitte versuchen Sie es erneut oder fragen Sie das Team.'), 500);
}

function carte_publique(array $m): array
{
    return [
        'jeton' => $m['jeton'],
        'carte' => $m['carte'],
        'prenom' => $m['prenom'],
        'langue' => $m['langue'],
        'offres' => (bool) $m['offres'],
        'inscrit_le' => substr($m['inscrit_le'], 0, 10),
        'compteurs' => club_compteurs($m),
        'cadeau' => club_cadeau_texte(),
        'offres_recues' => club_offres_du_membre((int) $m['id']),
    ];
}

function inscription(array $q): never
{
    if (!club_reglages()['inscriptions']) {
        erreur(t('Les inscriptions au Club sont fermées pour le moment.', 'Die Anmeldung zum Club ist derzeit geschlossen.'), 403);
    }
    if (!empty($q['site_web'])) { // champ piège invisible : rempli seulement par les robots
        repondre(['ok' => true, 'existe' => true, 'message' => '']);
    }
    if (!limiter('club_insc:' . ip_client(), 8, 3600)) {
        erreur(t('Trop d’inscriptions depuis cet appareil. Réessayez dans une heure.', 'Zu viele Anmeldungen von diesem Gerät. Bitte versuchen Sie es in einer Stunde erneut.'), 429);
    }
    $x = club_inscrire((string) ($q['prenom'] ?? ''), (string) ($q['email'] ?? ''), langue(), !empty($q['offres']), ($q['origine'] ?? '') === 'table' ? 'table' : 'site');
    if ($x['existe']) {
        // On ne montre pas la carte d'une adresse existante (quelqu'un d'autre pourrait la taper)
        $envoye = club_mail_bienvenue($x['membre']);
        repondre(['ok' => true, 'existe' => true, 'message' => $envoye
            ? t('Cette adresse a déjà une carte : nous venons de vous renvoyer son lien par e-mail.', 'Diese Adresse hat bereits eine Karte: Wir haben Ihnen den Link erneut per E-Mail geschickt.')
            : t('Cette adresse a déjà une carte du Club. Demandez-la à l’équipe : elle la retrouve en un instant.', 'Diese Adresse hat bereits eine Club-Karte. Fragen Sie einfach das Team, es findet sie sofort.')]);
    }
    club_mail_bienvenue($x['membre']);
    repondre(['ok' => true, 'existe' => false, 'carte' => carte_publique($x['membre'])]);
}

function membre_du_jeton(array $q): array
{
    if (!limiter('club_carte:' . ip_client(), 240, 3600)) {
        erreur(t('Trop de demandes. Réessayez plus tard.', 'Zu viele Anfragen. Bitte später erneut versuchen.'), 429);
    }
    return club_membre_par_jeton((string) ($q['jeton'] ?? ''))
        ?? erreur(t('Carte introuvable. Elle a peut-être été supprimée.', 'Karte nicht gefunden. Sie wurde möglicherweise gelöscht.'), 404);
}

function carte(array $q): never
{
    $m = membre_du_jeton($q);
    langue($m['langue'] === 'de' || ($q['lang'] ?? '') === 'de' ? 'de' : 'fr');
    repondre(['ok' => true, 'carte' => carte_publique($m)]);
}

function accord(array $q, string $source): never
{
    $m = membre_du_jeton($q);
    $m = club_accord($m, !empty($q['accord']), $source);
    repondre(['ok' => true, 'carte' => carte_publique($m)]);
}

function retrouver(array $q): never
{
    if (!limiter('club_retr:' . ip_client(), 5, 3600)) {
        erreur(t('Trop de demandes. Réessayez dans une heure.', 'Zu viele Anfragen. Bitte in einer Stunde erneut versuchen.'), 429);
    }
    $email = mb_strtolower(nettoyer_texte($q['email'] ?? '', 120));
    $st = club_db()->prepare('SELECT * FROM club_membres WHERE email = ?');
    $st->execute([$email]);
    if ($m = $st->fetch()) {
        club_mail_bienvenue($m);
    }
    // Même réponse que l'adresse existe ou non (on ne dévoile pas qui est inscrit)
    repondre(['ok' => true, 'message' => club_reglages()['envoi_reel'] && club_reglages()['bienvenue']
        ? t('Si cette adresse a une carte, son lien vient de partir par e-mail. Sinon, l’équipe peut vous la montrer au restaurant.', 'Falls diese Adresse eine Karte hat, wurde der Link gerade per E-Mail gesendet. Sonst zeigt das Team sie Ihnen gerne im Restaurant.')
        : t('Demandez votre carte à l’équipe : elle la retrouve en un instant.', 'Fragen Sie das Team nach Ihrer Karte: Es findet sie sofort.')]);
}
