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

rappels_en_arriere_plan();

try {
    $q = lire_requete();
    langue((string) ($q['lang'] ?? $q['langue'] ?? 'fr'));
    switch ($q['action'] ?? '') {
        case 'calendrier':
            calendrier();
        case 'places':
            places($q);
        case 'demande':
            demande($q);
        case 'annulation':
            annulation($q, false);
        case 'annuler':
            annulation($q, true);
        case 'desinscrire':
            desinscrire($q);
        default:
            erreur(t('Action inconnue.', 'Unbekannte Aktion.'));
    }
} catch (Throwable $e) {
    error_log('[reservation] ' . $e->getMessage());
    erreur(t('Un problème technique est survenu. Merci de nous appeler au 03 88 86 31 87.', 'Ein technisches Problem ist aufgetreten. Bitte rufen Sie uns an: +33 3 88 86 31 87.'), 500);
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
            $commence = $r['fermer_pendant_service'] && $e['ouvert'] && $e['en_ligne'] && $d === date('Y-m-d') && date('H:i') >= $e['debut'];
            $services[$s] = [
                'ouvert' => $e['ouvert'],
                'commence' => $commence,
                'en_ligne' => $e['en_ligne'],
                'raison' => $e['ouvert'] ? ($e['en_ligne'] ? '' : t('Réservation par téléphone uniquement', 'Reservierung nur telefonisch')) : traduire_raison($e['raison']),
                'max' => $max,
                'creneaux' => $cr,
                'soiree' => $e['soiree'] ? ['titre' => traduire($e['soiree']['titre']), 'details' => traduire($e['soiree']['details'])] + $e['soiree'] : null,
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
        'fermer_pendant_service' => (bool) $r['fermer_pendant_service'],
        'midi_debut' => $r['midi_debut'],
        'soir_debut' => $r['soir_debut'],
        'telephone' => $r['telephone'],
        'delai' => $r['delai_mode'] === 'veille' ? 'veille' : (int) $r['delai_heures'],
        'jours' => $jours,
    ]);
}

function demande(array $q): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        erreur(t('Méthode non autorisée.', 'Nicht erlaubt.'), 405);
    }
    $tel_resto = tel_affiche();
    // Piège à robots : ce champ est caché, un humain ne le remplit jamais.
    if (trim((string) ($q['site_web'] ?? '')) !== '') {
        repondre(['ok' => true, 'reservation' => null]);
    }
    if (!limiter('demande:' . ip_client(), 6, 3600)) {
        erreur(t("Trop de demandes envoyées d'ici. Merci de nous appeler au $tel_resto.", "Zu viele Anfragen von diesem Gerät. Bitte rufen Sie uns an: $tel_resto."), 429);
    }

    $date = (string) ($q['date'] ?? '');
    $heure = (string) ($q['heure'] ?? '');
    $couverts = (int) ($q['couverts'] ?? 0);
    $prenom = nettoyer_texte($q['prenom'] ?? '', 40);
    $nom_famille = nettoyer_texte($q['nom'] ?? '', 40);
    $nom = trim("$prenom $nom_famille");
    $tel = nettoyer_texte($q['telephone'] ?? '', 25);
    $email = mb_strtolower(nettoyer_texte($q['email'] ?? '', 120));
    $remarque = nettoyer_texte($q['remarque'] ?? '', 500);

    if (!date_valide($date) || !heure_valide($heure)) {
        erreur(t('Choisissez une date et une heure.', 'Bitte wählen Sie Datum und Uhrzeit.'));
    }
    if ($couverts < 1) {
        erreur(t('Indiquez le nombre de personnes.', 'Bitte geben Sie die Personenzahl an.'));
    }
    if (mb_strlen($prenom) < 2) {
        erreur(t('Indiquez votre prénom.', 'Bitte geben Sie Ihren Vornamen an.'));
    }
    if (mb_strlen($nom_famille) < 2) {
        erreur(t('Indiquez votre nom.', 'Bitte geben Sie Ihren Nachnamen an.'));
    }
    if (!mobile_valide($tel)) {
        erreur(t('Indiquez un numéro de téléphone mobile valide.', 'Bitte geben Sie eine gültige Handynummer an.'));
    }
    if (empty($q['accord'])) {
        erreur(t("Merci de cocher la case d'accord pour l'utilisation de vos coordonnées.", 'Bitte bestätigen Sie die Verwendung Ihrer Kontaktdaten.'));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        erreur(t("L'adresse e-mail ne semble pas correcte.", 'Die E-Mail-Adresse scheint nicht korrekt zu sein.'));
    }

    $r = reglages();
    if ($couverts > $r['max_en_ligne']) {
        erreur(t("Pour plus de {$r['max_en_ligne']} personnes, appelez-nous au $tel_resto.", "Für mehr als {$r['max_en_ligne']} Personen rufen Sie uns bitte an: $tel_resto."));
    }
    if ($date > date('Y-m-d', strtotime("+{$r['jours_avance']} days"))) {
        erreur(t('Cette date est trop lointaine pour réserver en ligne.', 'Dieses Datum liegt zu weit in der Zukunft für eine Online-Reservierung.'));
    }
    $service = service_de($heure);

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE'); // personne d'autre ne réserve pendant la vérification
    try {
        $occ = occupation($date, $date);
        $etat = etat_service($date, $service, null, $occ);
        if (!$etat['ouvert']) {
            throw new DomainException(t('Le restaurant est fermé à ce moment-là.', 'Das Restaurant ist zu dieser Zeit geschlossen.'));
        }
        if (!$etat['en_ligne']) {
            throw new DomainException(t("La réservation en ligne est fermée pour ce service : appelez-nous au $tel_resto.", "Für diesen Service ist keine Online-Reservierung möglich. Bitte rufen Sie uns an: $tel_resto."));
        }
        if ($etat['menu_impose'] && empty($q['menu_accepte'])) {
            throw new DomainException(t('Merci de confirmer que vous avez bien noté le menu de la soirée.', 'Bitte bestätigen Sie, dass Sie das Menü des Abends zur Kenntnis genommen haben.'));
        }
        $creneau = null;
        foreach (creneaux_en_ligne($etat, $occ) as $c) {
            if ($c['h'] === $heure) {
                $creneau = $c;
            }
        }
        if (!$creneau) {
            throw new DomainException(t("Cette heure n'est pas proposée.", 'Diese Uhrzeit ist nicht verfügbar.'));
        }
        if ($creneau['max'] < $couverts) {
            throw new DomainException($creneau['max'] > 0
                ? t("Il ne reste de la place que pour {$creneau['max']} personne(s) à cette heure. Choisissez une autre heure ou appelez-nous.", "Zu dieser Uhrzeit ist nur noch Platz für {$creneau['max']} Person(en). Bitte wählen Sie eine andere Uhrzeit oder rufen Sie uns an.")
                : t("Ce créneau n'est plus disponible. Choisissez une autre heure ou appelez-nous au $tel_resto.", "Diese Uhrzeit ist nicht mehr verfügbar. Bitte wählen Sie eine andere oder rufen Sie uns an: $tel_resto."));
        }
        $resa = [
            'date' => $date, 'service' => $service, 'heure' => $heure, 'couverts' => $couverts,
            'nom' => $nom, 'telephone' => $tel, 'email' => $email, 'remarque' => $remarque,
            'statut' => 'attente', 'source' => 'site', 'jeton' => jeton(), 'cree_le' => maintenant(), 'langue' => langue(),
        ];
        $st = $pdo->prepare('INSERT INTO reservations (date, service, heure, couverts, nom, telephone, email, remarque, statut, source, jeton, cree_le, langue)
            VALUES (:date, :service, :heure, :couverts, :nom, :telephone, :email, :remarque, :statut, :source, :jeton, :cree_le, :langue)');
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
    if (!empty($q['infos'])) {
        abonner($email, $nom, $tel);
    }
    mail_client($resa, 'recue');
    alerter_equipe($resa, 'nouvelle');
    repondre(['ok' => true, 'reservation' => resa_publique($resa)]);
}

function annulation(array $q, bool $confirmer): never
{
    $jeton = (string) ($q['jeton'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) {
        erreur(t('Lien invalide.', 'Ungültiger Link.'), 404);
    }
    if (!limiter('annul:' . ip_client(), 30, 3600)) {
        erreur(t('Trop de tentatives.', 'Zu viele Versuche.'), 429);
    }
    $st = db()->prepare('SELECT * FROM reservations WHERE jeton = ?');
    $st->execute([$jeton]);
    $resa = $st->fetch();
    if (!$resa) {
        erreur(t('Cette réservation est introuvable.', 'Diese Reservierung wurde nicht gefunden.'), 404);
    }
    if (!isset($q['lang']) && !isset($q['langue'])) {
        langue($resa['langue'] ?? 'fr');
    }
    $passee = strtotime($resa['date'] . ' ' . $resa['heure']) < time();
    $annulable = in_array($resa['statut'], STATUTS_ACTIFS, true) && !$passee;
    if (!$confirmer) {
        repondre(['ok' => true, 'reservation' => resa_publique($resa), 'annulable' => $annulable, 'passee' => $passee]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        erreur(t('Méthode non autorisée.', 'Nicht erlaubt.'), 405);
    }
    if (!$annulable) {
        erreur($resa['statut'] === 'annulee' ? t('Cette réservation est déjà annulée.', 'Diese Reservierung ist bereits storniert.') : t('Cette réservation ne peut plus être annulée en ligne. Appelez-nous au ', 'Diese Reservierung kann nicht mehr online storniert werden. Bitte rufen Sie uns an: ') . tel_affiche() . '.');
    }
    db()->prepare("UPDATE reservations SET statut = 'annulee', modifie_le = ?, traite_par = 'Client (lien e-mail)' WHERE id = ?")
        ->execute([maintenant(), $resa['id']]);
    $resa['statut'] = 'annulee';
    mail_client($resa, 'annulee');
    alerter_equipe($resa, 'annulee');
    repondre(['ok' => true, 'reservation' => resa_publique($resa)]);
}

/** Inscription aux infos du 76 (case facultative cochée par le client). */
function abonner(string $email, string $nom, string $tel): void
{
    db()->prepare("INSERT INTO abonnes (email, nom, telephone, inscrit_le, jeton, langue) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(email) DO UPDATE SET nom = excluded.nom, telephone = excluded.telephone, inscrit_le = excluded.inscrit_le, langue = excluded.langue, desinscrit_le = NULL")
        ->execute([$email, $nom, $tel, maintenant(), jeton(), langue()]);
}

/** Lien « Ne plus recevoir les infos du 76 ». */
function desinscrire(array $q): never
{
    $j = (string) ($q['jeton'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $j) || !limiter('desinscr:' . ip_client(), 30, 3600)) {
        erreur(t('Lien invalide.', 'Ungültiger Link.'), 404);
    }
    $st = db()->prepare('UPDATE abonnes SET desinscrit_le = ? WHERE jeton = ?');
    $st->execute([maintenant(), $j]);
    $st->rowCount() ? repondre(['ok' => true]) : erreur(t('Lien invalide.', 'Ungültiger Link.'), 404);
}

/** Places restantes pour un service, communiquées seulement quand il en reste peu (sinon null). */
function places(array $q): never
{
    $date = (string) ($q['date'] ?? '');
    $service = in_array($q['service'] ?? '', SERVICES, true) ? $q['service'] : 'soir';
    if (!date_valide($date)) {
        erreur(t('Date invalide.', 'Ungültiges Datum.'));
    }
    $e = etat_service($date, $service);
    $seuil = 30;
    repondre(['ok' => true, 'complet' => $e['ouvert'] && $e['restant'] <= 0, 'restant' => ($e['restant'] > 0 && $e['restant'] <= $seuil) ? $e['restant'] : null]);
}

/** Numéro du restaurant, au format international pour les clients allemands. */
function tel_affiche(): string
{
    $tel = reglages()['telephone'];
    return langue() === 'de' && str_starts_with($tel, '0') ? '+33 ' . substr($tel, 1) : $tel;
}

function traduire_raison(string $r): string
{
    if (langue() !== 'de' || $r === '') {
        return $r;
    }
    return match ($r) {
        'Fermé ce midi' => 'Mittags geschlossen',
        'Fermé ce soir' => 'Abends geschlossen',
        'Fermé exceptionnellement' => 'Ausnahmsweise geschlossen',
        default => traduire($r),
    };
}
