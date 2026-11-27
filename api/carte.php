<?php
/**
 * Carte du 76 (appli /carte/) : remplie par l'équipe, validée et publiée par un responsable.
 *
 * Mêmes comptes et même session que le portail et l'appli des réservations.
 * Toute l'équipe peut modifier le brouillon ; seuls les responsables valident et publient.
 * La carte publiée est lisible sans connexion (le76.fr/carte/#carte).
 * Les données vivent dans le dossier de données des réservations (hors de public_html).
 */
declare(strict_types=1);

require __DIR__ . '/lib/base.php';
require __DIR__ . '/lib/carte.php';

define('COOKIE', defined('DEMO') ? 'le76_equipe_demo' : 'le76_equipe');

try {
    $q = lire_requete();
    $action = (string) ($q['action'] ?? '');

    // Sans connexion : la carte publiée et les images de décor
    if ($action === 'image') {
        ca_image_envoyer((string) ($q['id'] ?? ''));
    }
    if ($action === 'publiee') {
        repondre(['ok' => true, 'publie' => ca_publiee()]);
    }

    $moi = ca_utilisateur() ?? erreur('Connecte-toi pour continuer.', 401);
    $responsable = (bool) $moi['admin'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        erreur('Méthode non autorisée.', 405);
    }

    switch ($action) {
        case 'charger':
            [$docs, $rev] = ca_tout();
            repondre(['ok' => true, 'moi' => ['id' => (int) $moi['id'], 'nom' => $moi['nom'], 'admin' => $responsable],
                'docs' => $docs, 'rev' => $rev, 'ia' => ca_ia_dispo()]);
        case 'changements':
            repondre(['ok' => true] + ca_changements((int) ($q['depuis'] ?? 0)));
        case 'ecrire':
            repondre(['ok' => true, 'rev' => ca_ecrire(is_array($q['ecritures'] ?? null) ? $q['ecritures'] : [], $responsable)]);
        case 'image_envoyer':
            repondre(['ok' => true] + ca_image_enregistrer((string) ($q['donnees'] ?? '')));
        case 'cm':
            // Données du coût matière utiles à la carte : coûts des recettes et allergènes
            repondre(['ok' => true] + ca_cout_matiere());
        case 'images_publiees':
            // Images des pages de la carte publiée (pour le site et les réseaux sociaux)
            $responsable || erreur('Seul un responsable peut publier la carte.', 403);
            repondre(['ok' => true, 'n' => ca_images_publiees(is_array($q['images'] ?? null) ? $q['images'] : [])]);
        case 'ia':
            // Traduction en allemand : même clé d'IA que le coût matière
            ca_ia_dispo() || erreur("La traduction automatique n'est pas activée : ajoute la clé de l'IA dans les Réglages du coût matière.", 503);
            $prompt = trim((string) ($q['prompt'] ?? ''));
            ($prompt !== '' && strlen($prompt) < 200000) || erreur('Demande vide.');
            try {
                // Images (pages d'une carte déposée) : jusqu'à 4 par demande
                $contenu = [];
                foreach (array_slice(is_array($q['images'] ?? null) ? $q['images'] : [], 0, 4) as $img) {
                    if (is_string($img) && preg_match('/^data:(image\/(?:png|jpeg|webp))/', $img, $m)) {
                        $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $m[1], 'data' => preg_replace('/^data:[^,]+,/', '', $img)]];
                    }
                }
                $contenu[] = ['type' => 'text', 'text' => $prompt];
                repondre(['ok' => true, 'resultat' => cm_ia($contenu, $contenu[0]['type'] === 'image' ? 16000 : 8000)]);
            } catch (RuntimeException $e) {
                erreur($e->getMessage(), 502);
            }
    }
    erreur('Action inconnue.');
} catch (Throwable $e) {
    error_log('[carte] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    erreur('Problème technique : ' . $e->getMessage(), 500);
}

function ca_ia_dispo(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        require_once __DIR__ . '/lib/cout-matiere.php';
        return $ok = cm_options()['ia_cle'] !== '';
    } catch (Throwable $e) {
        return $ok = false;
    }
}

function ca_cout_matiere(): array
{
    try {
        require_once __DIR__ . '/lib/cout-matiere.php';
        [$d] = cm_tout();
    } catch (Throwable $e) {
        return ['dispo' => false];
    }
    $ing = [];
    foreach ((array) $d['ingredients'] as $id => $i) {
        $ing[$id] = ['name' => $i['name'] ?? '', 'price' => $i['price'] ?? 0, 'unit' => $i['unit'] ?? 'kg', 'loss' => $i['loss'] ?? 0,
            'allergens' => $i['allergens'] ?? [], 'pieceWeight' => $i['pieceWeight'] ?? null];
    }
    $fic = [];
    foreach ((array) $d['fiches'] as $id => $f) {
        $fic[$id] = ['name' => $f['name'] ?? '', 'category' => $f['category'] ?? '', 'yield' => $f['yield'] ?? 1,
            'yieldUnit' => $f['yieldUnit'] ?? 'portion', 'lines' => $f['lines'] ?? []];
    }
    $car = [];
    foreach ((array) $d['carte'] as $id => $c) {
        $car[$id] = ['name' => $c['name'] ?? '', 'ttc' => $c['ttc'] ?? 0, 'tva' => $c['tva'] ?? 10, 'fiche' => $c['fiche'] ?? '',
            'qty' => $c['qty'] ?? 1, 'unit' => $c['unit'] ?? 'portion'];
    }
    $set = (array) ($d['set']['kitchen'] ?? []);
    return ['dispo' => true, 'ingredients' => (object) $ing, 'fiches' => (object) $fic, 'carte' => (object) $car,
        'objectif' => (float) ($set['target'] ?? 28)];
}

