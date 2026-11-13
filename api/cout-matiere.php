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
    cm_resto((string) ($_POST['r'] ?? $_GET['r'] ?? 'le76'));
    if (!empty($_FILES['fichier']) && ($_POST['action'] ?? '') === 'facture_deposer') {
        cm_cle_depot_ok((string) ($_POST['cle'] ?? '')) || erreur('Clé de dépôt refusée : relance l\'installation depuis les Réglages.', 403);
        $tmp = (string) ($_FILES['fichier']['tmp_name'] ?? '');
        is_uploaded_file($tmp) || erreur("Fichier non reçu.");
        repondre(['ok' => true] + cm_facture_deposer((string) ($_FILES['fichier']['name'] ?? ''), base64_encode((string) file_get_contents($tmp))));
    }
    $q = lire_requete();
    if (isset($q['r']) && is_string($q['r'])) {
        cm_resto($q['r']);
    }
    // Installateur pour le Mac : curl … | bash depuis le Terminal
    if (($q['action'] ?? '') === 'installeur') {
        cm_cle_depot_ok((string) ($q['cle'] ?? '')) || erreur('Clé refusée.', 403);
        header('Content-Type: text/plain; charset=utf-8');
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'le76.fr') . '/api/cout-matiere.php' . (cm_resto() === 'le76' ? '' : '?r=' . cm_resto());
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
    $role = cm_role($moi);
    $public = ['id' => (int) $moi['id'], 'nom' => $moi['nom'], 'admin' => $admin, 'role' => $role];

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
            if (!$admin) {
                foreach (['ventes', 'factures', 'alertes', 'liens', 'carte', 'boissons'] as $k) {
                    $docs[$k] = new stdClass();
                }
            }
            repondre(['ok' => true, 'moi' => $public, 'docs' => $docs, 'rev' => $rev, 'options' => cm_options_publiques($admin),
                'resto' => cm_resto(), 'restos' => cm_restaurants()]);
        case 'changements':
            repondre(['ok' => true] + cm_changements((int) ($q['depuis'] ?? 0)));
    }

    // Cuisine : peut modifier ingrédients, fiches et menus, ajouter des photos et utiliser l'IA pour les fiches
    if (!$admin && $role === 'cuisine') {
        switch ($action) {
            case 'ecrire':
                $e = array_values(array_filter(is_array($q['ecritures'] ?? null) ? $q['ecritures'] : [], fn($x) => in_array($x['coll'] ?? '', CM_COLLS_CUISINE, true)));
                repondre(['ok' => true, 'rev' => $e ? cm_ecrire($e) : cm_rev()]);
            case 'ia':
                repondre(['ok' => true, 'resultat' => cm_ia_requete($q)]);
            case 'photo_envoyer':
                repondre(['ok' => true] + cm_photo_enregistrer((string) ($q['donnees'] ?? '')));
            case 'photo_supprimer':
                cm_fichier_supprimer('photos', (string) ($q['id'] ?? ''));
                repondre(['ok' => true]);
        }
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
        case 'ventes_relever':
            repondre(['ok' => true] + cm_relever_ventes(!empty($q['forcer'])));
        case 'ventes_importer':
            repondre(['ok' => true, 'importes' => cm_ventes_importer(is_array($q['ventes'] ?? null) ? $q['ventes'] : [])]);
        case 'copier_depuis':
            repondre(['ok' => true, 'copies' => cm_copier_depuis((string) ($q['source'] ?? ''), is_array($q['colls'] ?? null) ? $q['colls'] : [])]);
        case 'modele_camping':
            // Carte et fiches techniques du restaurant du camping 2027 (prix d'achat estimés, à vérifier)
            $id = '';
            foreach (cm_restaurants() as $r) {
                if ($r['id'] === 'camping-2027') {
                    $id = $r['id'];
                }
            }
            $restos = cm_restaurants();
            if ($id === '') {
                ['id' => $id, 'restos' => $restos] = cm_resto_creer('Camping 2027');
            }
            cm_resto($id);
            [$docs] = cm_tout();
            !count((array) ($docs['fiches'] ?? [])) || erreur('« Camping 2027 » a déjà des fiches : rien n\'a été remplacé.');
            $s = json_decode((string) file_get_contents(__DIR__ . '/lib/cout-matiere-camping-2027.json'), true);
            is_array($s) || erreur('Modèle du camping introuvable.');
            repondre(['ok' => true, 'id' => $id, 'restos' => $restos, 'importes' => cm_importer($s)]);
        case 'roles_ecrire':
            repondre(['ok' => true, 'equipe' => cm_roles_ecrire(is_array($q['roles'] ?? null) ? $q['roles'] : [])]);
        case 'resto_creer':
            repondre(['ok' => true] + cm_resto_creer((string) ($q['nom'] ?? '')));
        case 'facture_relire':
            repondre(['ok' => true] + cm_facture_relire((string) ($q['id'] ?? '')));
    }
    erreur('Action inconnue.');
} catch (Throwable $e) {
    error_log('[cout-matiere] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur('Problème technique : ' . $e->getMessage(), 500);
}
