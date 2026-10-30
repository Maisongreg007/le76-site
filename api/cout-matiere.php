<?php
/**
 * Coût matière du 76 (appli /cout-matiere/) : stockage partagé, lecture des factures par l'IA,
 * relève automatique de la boîte facturation@le76.fr.
 *
 * Mêmes comptes et même session que le portail et l'appli des réservations.
 * Responsables : lecture et écriture. Reste de l'équipe : lecture seule.
 * Les données vivent dans le dossier de données des réservations (hors de public_html).
 */
declare(strict_types=1);

require __DIR__ . '/lib/base.php';
require __DIR__ . '/lib/cout-matiere.php';

define('COOKIE', defined('DEMO') ? 'le76_equipe_demo' : 'le76_equipe');

try {
    // Envoi d'un fichier par le Mac (formulaire multipart) : dossier des factures surveillé
    if (!empty($_FILES['fichier']) && ($_POST['action'] ?? '') === 'facture_deposer') {
        cm_cle_depot_ok((string) ($_POST['cle'] ?? '')) || erreur('Clé de dépôt refusée : relance l\'installation depuis les Réglages.', 403);
        $tmp = (string) ($_FILES['fichier']['tmp_name'] ?? '');
        is_uploaded_file($tmp) || erreur("Fichier non reçu.");
        repondre(['ok' => true] + cm_facture_deposer((string) ($_FILES['fichier']['name'] ?? ''), base64_encode((string) file_get_contents($tmp))));
    }
    $q = lire_requete();
    // Installateur pour le Mac : curl … | bash depuis le Terminal
    if (($q['action'] ?? '') === 'installeur') {
        cm_cle_depot_ok((string) ($q['cle'] ?? '')) || erreur('Clé refusée.', 403);
        header('Content-Type: text/plain; charset=utf-8');
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'le76.fr') . '/api/cout-matiere.php';
        echo str_replace(['__CLE__', '__URL__'], [(string) $q['cle'], $url], (string) file_get_contents(__DIR__ . '/lib/cout-matiere-mac.sh'));
        exit;
    }
    $action = (string) ($q['action'] ?? '');
    // Dépôt automatique depuis le Mac (dossier des factures) : clé de dépôt au lieu de la session
    if ($action === 'facture_deposer' && !empty($q['cle']) && cm_cle_depot_ok((string) $q['cle'])) {
        repondre(['ok' => true] + cm_facture_deposer((string) ($q['nom'] ?? ''), (string) ($q['donnees'] ?? '')));
    }
    $moi = cm_utilisateur() ?? erreur('Connecte-toi pour continuer.', 401);
    $admin = (bool) $moi['admin'];
    $public = ['id' => (int) $moi['id'], 'nom' => $moi['nom'], 'admin' => $admin];

    // Fichiers (GET) : photos des fiches et factures reçues
    if ($action === 'photo') {
        cm_envoyer_fichier('photos', (string) ($q['id'] ?? ''));
    }
    if ($action === 'facture_fichier') {
        cm_envoyer_fichier('factures', (string) ($q['id'] ?? ''));
    }
    if ($action === 'exporter') {
        $admin || erreur('Réservé au responsable.', 403);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="cout-matiere-le76-' . date('Y-m-d') . '.json"');
        echo json_encode(cm_sauvegarde(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        erreur('Méthode non autorisée.', 405);
    }

    switch ($action) {
        case 'charger':
            [$docs, $rev] = cm_tout();
            repondre(['ok' => true, 'moi' => $public, 'docs' => $docs, 'rev' => $rev, 'options' => cm_options_publiques($admin)]);
        case 'changements':
            repondre(['ok' => true] + cm_changements((int) ($q['depuis'] ?? 0)));
    }

    $admin || erreur('Réservé au responsable : demande-lui de faire la modification.', 403);

    switch ($action) {
        case 'ecrire':
            $rev = cm_ecrire(is_array($q['ecritures'] ?? null) ? $q['ecritures'] : []);
            repondre(['ok' => true, 'rev' => $rev]);
        case 'ia':
            repondre(['ok' => true, 'resultat' => cm_ia_requete($q)]);
        case 'photo_envoyer':
            repondre(['ok' => true] + cm_photo_enregistrer((string) ($q['donnees'] ?? '')));
        case 'photo_supprimer':
            cm_fichier_supprimer('photos', (string) ($q['id'] ?? ''));
            repondre(['ok' => true]);
        case 'options':
            repondre(['ok' => true, 'options' => cm_options_publiques(true)]);
        case 'options_ecrire':
            cm_options_enregistrer(is_array($q['options'] ?? null) ? $q['options'] : []);
            repondre(['ok' => true, 'options' => cm_options_publiques(true)]);
        case 'importer':
            $n = cm_importer(is_array($q['sauvegarde'] ?? null) ? $q['sauvegarde'] : []);
            repondre(['ok' => true, 'importes' => $n]);
        case 'factures_relever':
            repondre(['ok' => true] + cm_relever_factures(!empty($q['forcer'])));
        case 'facture_deposer':
            repondre(['ok' => true] + cm_facture_deposer((string) ($q['nom'] ?? ''), (string) ($q['donnees'] ?? '')));
        case 'cle_depot':
            repondre(['ok' => true, 'cle' => cm_cle_depot(!empty($q['nouvelle']))]);
        case 'facture_relire':
            repondre(['ok' => true] + cm_facture_relire((string) ($q['id'] ?? '')));
    }
    erreur('Action inconnue.');
} catch (Throwable $e) {
    error_log('[cout-matiere] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur('Problème technique : ' . $e->getMessage(), 500);
}
