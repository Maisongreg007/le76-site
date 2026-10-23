<?php
/**
 * Réservations du 76 : partie publique (page « Réserver » du site).
 *   GET  ?action=calendrier            jours et créneaux réservables
 *   POST {action:"demande", …}         nouvelle demande de réservation
 *   GET  ?action=annulation&jeton=…    afficher une réservation à annuler
 *   POST {action:"annuler", jeton}     annulation par le client
 */
declare(strict_types=1);

require __DIR__ . '/lib/base.php';
require __DIR__ . '/lib/messages.php';

try {
    $q = lire_requete();
    switch ($q['action'] ?? '') {
        case 'calendrier':
            calendrier();
        case 'demande':
            demande($q);
        case 'annulation':
            annulation($q, false);
        case 'annuler':
            annulation($q, true);
        default:
            erreur('Action inconnue.');
    }
} catch (Throwable $e) {
    error_log('[reservation] ' . $e->getMessage());
    erreur("Un problème technique est survenu. Merci de nous appeler au " . reglages_defaut()['telephone'] . '.', 500);
}

function calendrier(): never
{
    $r = reglages();
    $debut = date('Y-m-d');
    $fin = date('Y-m-d', strtotime("+{$r['jours_avance']} days"));
    $exc = exceptions_jours($debut, $fin);
    $occ = occupation($debut, $fin);
    $jours = [];
    for ($t = strtotime($debut); date('Y-m-d', $t) <= $fin; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $services = [];
        foreach (SERVICES as $s) {
            $e = etat_service($d, $s, $exc, $occ);
            $cr = creneaux_en_ligne($e, $occ);
            $max = $cr ? max(array_column($cr, 'max')) : 0;
            $services[$s] = [
                'ouvert' => $e['ouvert'],
                'en_ligne' => $e['en_ligne'],
                'raison' => $e['ouvert'] ? ($e['en_ligne'] ? '' : 'Réservation par téléphone uniquement') : $e['raison'],
                'max' => $max,
                'creneaux' => $cr,
                'soiree' => $e['soiree'],
                'menu_impose' => $e['menu_impose'],
                'note' => $e['note'],
            ];
        }
        $jours[] = ['date' => $d, 'services' => $services];
    }
    repondre([
        'ok' => true,
        'aujourdhui' => $debut,
        'resa_en_ligne' => (bool) $r['resa_en_ligne'],
        'max_en_ligne' => (int) $r['max_en_ligne'],
        'telephone' => $r['telephone'],
        'delai' => $r['delai_mode'] === 'veille' ? 'veille' : (int) $r['delai_heures'],
        'jours' => $jours,
    ]);
}

function demande(array $q): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        erreur('Méthode non autorisée.', 405);
    }
    $tel_resto = reglages()['telephone'];
    // Piège à robots : ce champ est caché, un humain ne le remplit jamais.
    if (trim((string) ($q['site_web'] ?? '')) !== '') {
        repondre(['ok' => true, 'reservation' => null]);
    }
    if (!limiter('demande:' . ip_client(), 6, 3600)) {
        erreur("Trop de demandes envoyées d'ici. Merci de nous appeler au $tel_resto.", 429);
    }

    $date = (string) ($q['date'] ?? '');
    $heure = (string) ($q['heure'] ?? '');
    $couverts = (int) ($q['couverts'] ?? 0);
    $nom = nettoyer_texte($q['nom'] ?? '', 80);
    $tel = nettoyer_texte($q['telephone'] ?? '', 25);
    $email = mb_strtolower(nettoyer_texte($q['email'] ?? '', 120));
    $remarque = nettoyer_texte($q['remarque'] ?? '', 500);

    if (!date_valide($date) || !heure_valide($heure)) {
        erreur('Choisissez une date et une heure.');
    }
    if ($couverts < 1) {
        erreur('Indiquez le nombre de personnes.');
    }
    if (mb_strlen($nom) < 2) {
        erreur('Indiquez votre nom.');
    }
    if (!telephone_valide($tel)) {
        erreur('Le numéro de téléphone ne semble pas correct.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        erreur("L'adresse e-mail ne semble pas correcte.");
    }

    $r = reglages();
    if ($couverts > $r['max_en_ligne']) {
        erreur("Pour plus de {$r['max_en_ligne']} personnes, appelez-nous au $tel_resto.");
    }
    if ($date > date('Y-m-d', strtotime("+{$r['jours_avance']} days"))) {
        erreur('Cette date est trop lointaine pour réserver en ligne.');
    }
    $service = service_de($heure);

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE'); // personne d'autre ne réserve pendant la vérification
    try {
        $occ = occupation($date, $date);
        $etat = etat_service($date, $service, null, $occ);
        if (!$etat['ouvert']) {
            throw new DomainException('Le restaurant est fermé à ce moment-là.');
        }
        if (!$etat['en_ligne']) {
            throw new DomainException("La réservation en ligne est fermée pour ce service : appelez-nous au $tel_resto.");
        }
        if ($etat['menu_impose'] && empty($q['menu_accepte'])) {
            throw new DomainException('Merci de confirmer que vous avez bien noté le menu de la soirée.');
        }
        $creneau = null;
        foreach (creneaux_en_ligne($etat, $occ) as $c) {
            if ($c['h'] === $heure) {
                $creneau = $c;
            }
        }
        if (!$creneau) {
            throw new DomainException("Cette heure n'est pas proposée.");
        }
        if ($creneau['max'] < $couverts) {
            throw new DomainException($creneau['max'] > 0
                ? "Il ne reste de la place que pour {$creneau['max']} personne(s) à cette heure. Choisissez une autre heure ou appelez-nous."
                : "Ce créneau n'est plus disponible. Choisissez une autre heure ou appelez-nous au $tel_resto.");
        }
        $resa = [
            'date' => $date, 'service' => $service, 'heure' => $heure, 'couverts' => $couverts,
            'nom' => $nom, 'telephone' => $tel, 'email' => $email, 'remarque' => $remarque,
            'statut' => 'attente', 'source' => 'site', 'jeton' => jeton(), 'cree_le' => maintenant(),
        ];
        $st = $pdo->prepare('INSERT INTO reservations (date, service, heure, couverts, nom, telephone, email, remarque, statut, source, jeton, cree_le)
            VALUES (:date, :service, :heure, :couverts, :nom, :telephone, :email, :remarque, :statut, :source, :jeton, :cree_le)');
        $st->execute($resa);
        $resa['id'] = (int) $pdo->lastInsertId();
        $pdo->exec('COMMIT');
    } catch (DomainException $e) {
        $pdo->exec('ROLLBACK');
        erreur($e->getMessage(), 409);
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }

    // Réponse rapide au client, puis e-mails et notifications.
    mail_client($resa, 'recue');
    alerter_equipe($resa, 'nouvelle');
    repondre(['ok' => true, 'reservation' => resa_publique($resa)]);
}

function annulation(array $q, bool $confirmer): never
{
    $jeton = (string) ($q['jeton'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) {
        erreur('Lien invalide.', 404);
    }
    if (!limiter('annul:' . ip_client(), 30, 3600)) {
        erreur('Trop de tentatives.', 429);
    }
    $st = db()->prepare('SELECT * FROM reservations WHERE jeton = ?');
    $st->execute([$jeton]);
    $resa = $st->fetch();
    if (!$resa) {
        erreur("Cette réservation est introuvable.", 404);
    }
    $passee = strtotime($resa['date'] . ' ' . $resa['heure']) < time();
    $annulable = in_array($resa['statut'], STATUTS_ACTIFS, true) && !$passee;
    if (!$confirmer) {
        repondre(['ok' => true, 'reservation' => resa_publique($resa), 'annulable' => $annulable, 'passee' => $passee]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        erreur('Méthode non autorisée.', 405);
    }
    if (!$annulable) {
        erreur($resa['statut'] === 'annulee' ? 'Cette réservation est déjà annulée.' : "Cette réservation ne peut plus être annulée en ligne. Appelez-nous au " . reglages()['telephone'] . '.');
    }
    db()->prepare("UPDATE reservations SET statut = 'annulee', modifie_le = ?, traite_par = 'Client (lien e-mail)' WHERE id = ?")
        ->execute([maintenant(), $resa['id']]);
    $resa['statut'] = 'annulee';
    mail_client($resa, 'annulee');
    alerter_equipe($resa, 'annulee');
    repondre(['ok' => true, 'reservation' => resa_publique($resa)]);
}
