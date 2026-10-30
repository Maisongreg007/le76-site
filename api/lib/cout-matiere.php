<?php
/**
 * Coût matière : base, droits, IA (Anthropic), relève des factures par mail.
 * Ce fichier n'est pas accessible depuis le web (api/lib est bloqué par .htaccess).
 */
declare(strict_types=1);

const CM_COLLECTIONS = ['ingredients', 'fiches', 'carte', 'boissons', 'factures', 'set'];
const CM_MODELES = ['claude-sonnet-5-5', 'claude-sonnet-4-5', 'claude-3-5-sonnet-latest'];

/* ------------------------------------------------------------------ */
/* Compte connecté (même session que le portail)                       */
/* ------------------------------------------------------------------ */

function cm_utilisateur(): ?array
{
    $j = (string) ($_COOKIE[COOKIE] ?? '');
    if (defined('DEMO') && !preg_match('/^[a-f0-9]{64}$/', $j)) {
        return db()->query('SELECT * FROM utilisateurs WHERE admin = 1 AND actif = 1 ORDER BY id LIMIT 1')->fetch() ?: null;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $j)) {
        return null;
    }
    $st = db()->prepare('SELECT u.* FROM sessions s JOIN utilisateurs u ON u.id = s.utilisateur_id WHERE s.jeton = ? AND s.expire > ? AND u.actif = 1');
    $st->execute([hash('sha256', $j), time()]);
    return $st->fetch() ?: null;
}

/* ------------------------------------------------------------------ */
/* Base                                                                */
/* ------------------------------------------------------------------ */

function cm_dossier(string $sous = ''): string
{
    $d = dossier_donnees() . '/cout-matiere' . ($sous !== '' ? '/' . $sous : '');
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

function cm_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . cm_dossier() . '/cout-matiere.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS docs (
        coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT, rev INTEGER NOT NULL, maj TEXT NOT NULL,
        PRIMARY KEY (coll, id))");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_docs_rev ON docs(rev)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS options (cle TEXT PRIMARY KEY, valeur TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS mails_lus (cle TEXT PRIMARY KEY, quand TEXT NOT NULL)');
    return $pdo;
}

function cm_rev(): int
{
    return (int) cm_db()->query('SELECT IFNULL(MAX(rev), 0) FROM docs')->fetchColumn();
}

/** Tous les documents actifs : {coll: {id: data}} et le numéro de révision. */
function cm_tout(): array
{
    $docs = array_fill_keys(CM_COLLECTIONS, []);
    foreach (cm_db()->query('SELECT coll, id, data FROM docs WHERE data IS NOT NULL') as $l) {
        $docs[$l['coll']][$l['id']] = json_decode($l['data'], true);
    }
    foreach ($docs as $k => $v) {
        if (!$v) {
            $docs[$k] = new stdClass(); // objet vide plutôt que liste vide en JSON
        }
    }
    return [$docs, cm_rev()];
}

function cm_changements(int $depuis): array
{
    $st = cm_db()->prepare('SELECT coll, id, data, rev FROM docs WHERE rev > ? ORDER BY rev LIMIT 2000');
    $st->execute([$depuis]);
    $out = [];
    $rev = $depuis;
    foreach ($st as $l) {
        $out[] = ['coll' => $l['coll'], 'id' => $l['id'], 'data' => $l['data'] === null ? null : json_decode($l['data'], true)];
        $rev = max($rev, (int) $l['rev']);
    }
    return ['rev' => $rev, 'changements' => $out];
}

function cm_id_valide(string $id): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_\-.:]{1,80}$/', $id);
}

/** Écritures groupées [{coll, id, data|null}] ; null = suppression. */
function cm_ecrire(array $ecritures): int
{
    $pdo = cm_db();
    $pdo->beginTransaction();
    try {
        $rev = cm_rev();
        $st = $pdo->prepare('INSERT INTO docs (coll, id, data, rev, maj) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(coll, id) DO UPDATE SET data = excluded.data, rev = excluded.rev, maj = excluded.maj');
        foreach (array_slice($ecritures, 0, 2000) as $e) {
            $coll = (string) ($e['coll'] ?? '');
            $id = (string) ($e['id'] ?? '');
            if (!in_array($coll, CM_COLLECTIONS, true) || !cm_id_valide($id)) {
                continue;
            }
            $data = $e['data'] ?? null;
            $json = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
            if ($json !== null && strlen($json) > 400000) {
                throw new RuntimeException('Document trop volumineux.');
            }
            $st->execute([$coll, $id, $json, ++$rev, maintenant()]);
        }
        $pdo->commit();
        return $rev;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cm_sauvegarde(): array
{
    [$docs] = cm_tout();
    $set = (array) ($docs['set'] ?? []);
    return [
        'version' => 1,
        'exporte_le' => maintenant(),
        'settings' => $set['kitchen'] ?? new stdClass(),
        'ingredients' => $docs['ingredients'], 'fiches' => $docs['fiches'],
        'carte' => $docs['carte'], 'boissons' => $docs['boissons'],
    ];
}

/** Remplace toutes les données par une sauvegarde (les factures reçues sont gardées). */
function cm_importer(array $s): int
{
    $ecritures = [];
    foreach (['ingredients', 'fiches', 'carte', 'boissons'] as $c) {
        if (!isset($s[$c]) || !is_array($s[$c])) {
            erreur("Ce fichier n'est pas une sauvegarde du coût matière.");
        }
    }
    [$actuel] = cm_tout();
    foreach (['ingredients', 'fiches', 'carte', 'boissons'] as $c) {
        foreach (array_keys((array) $actuel[$c]) as $id) {
            if (!isset($s[$c][$id])) {
                $ecritures[] = ['coll' => $c, 'id' => (string) $id, 'data' => null];
            }
        }
        foreach ($s[$c] as $id => $d) {
            if (is_array($d)) {
                $ecritures[] = ['coll' => $c, 'id' => (string) $id, 'data' => $d];
            }
        }
    }
    $set = is_array($s['settings'] ?? null) ? $s['settings'] : [];
    $set['reset'] = 1;
    $ecritures[] = ['coll' => 'set', 'id' => 'kitchen', 'data' => $set];
    cm_ecrire($ecritures);
    return count($ecritures);
}

/* ------------------------------------------------------------------ */
/* Options (clé IA, boîte mail)                                        */
/* ------------------------------------------------------------------ */

function cm_options(): array
{
    $o = ['ia_cle' => '', 'ia_modele' => '', 'imap_hote' => 'imap.hostinger.com', 'imap_user' => '', 'imap_mdp' => '',
        'mail_actif' => false, 'derniere_releve' => '', 'derniere_releve_msg' => ''];
    foreach (cm_db()->query('SELECT cle, valeur FROM options') as $l) {
        $o[$l['cle']] = json_decode($l['valeur'], true);
    }
    return $o;
}

function cm_option(string $cle, $valeur): void
{
    cm_db()->prepare('INSERT INTO options (cle, valeur) VALUES (?, ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur')
        ->execute([$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
}

function cm_options_publiques(bool $admin): array
{
    $o = cm_options();
    $p = ['ia' => $o['ia_cle'] !== '', 'mail' => (bool) $o['mail_actif'] && $o['imap_user'] !== ''];
    if ($admin) {
        $p += [
            'ia_cle' => $o['ia_cle'] !== '' ? '••••' . substr($o['ia_cle'], -4) : '',
            'ia_modele' => $o['ia_modele'],
            'imap_hote' => $o['imap_hote'], 'imap_user' => $o['imap_user'], 'imap_mdp' => $o['imap_mdp'] !== '',
            'mail_actif' => (bool) $o['mail_actif'],
            'derniere_releve' => $o['derniere_releve'], 'derniere_releve_msg' => $o['derniere_releve_msg'],
            'imap_dispo' => function_exists('imap_open'), 'curl_dispo' => function_exists('curl_init'),
        ];
    }
    return $p;
}

function cm_options_enregistrer(array $n): void
{
    if (isset($n['ia_cle']) && is_string($n['ia_cle']) && !str_starts_with($n['ia_cle'], '••••')) {
        cm_option('ia_cle', trim($n['ia_cle']));
    }
    foreach (['ia_modele', 'imap_hote', 'imap_user'] as $k) {
        if (isset($n[$k]) && is_string($n[$k])) {
            cm_option($k, trim($n[$k]));
        }
    }
    if (isset($n['imap_mdp']) && is_string($n['imap_mdp']) && $n['imap_mdp'] !== '') {
        cm_option('imap_mdp', $n['imap_mdp']);
    }
    if (isset($n['mail_actif'])) {
        cm_option('mail_actif', (bool) $n['mail_actif']);
    }
}

/* ------------------------------------------------------------------ */
/* Fichiers (photos, factures)                                         */
/* ------------------------------------------------------------------ */

function cm_envoyer_fichier(string $type, string $id): never
{
    if (!preg_match('/^[a-f0-9]{16,40}\.(jpg|png|pdf|webp)$/', $id)) {
        http_response_code(404);
        exit;
    }
    $f = cm_dossier($type) . '/' . $id;
    if (!is_file($f)) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'webp' => 'image/webp'];
    header('Content-Type: ' . $types[pathinfo($id, PATHINFO_EXTENSION)]);
    header('Cache-Control: private, max-age=31536000');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

function cm_photo_enregistrer(string $b64): array
{
    $bin = base64_decode(preg_replace('/^data:[^,]+,/', '', $b64), true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 6_000_000) {
        erreur("La photo n'a pas pu être enregistrée.");
    }
    $id = bin2hex(random_bytes(12)) . '.jpg';
    file_put_contents(cm_dossier('photos') . '/' . $id, $bin);
    return ['id' => $id, 'url' => '/api/cout-matiere.php?action=photo&id=' . $id];
}

function cm_fichier_supprimer(string $type, string $id): void
{
    if (preg_match('/^[a-f0-9]{16,40}\.(jpg|png|pdf|webp)$/', $id)) {
        @unlink(cm_dossier($type) . '/' . $id);
    }
}

/* ------------------------------------------------------------------ */
/* IA (Anthropic)                                                      */
/* ------------------------------------------------------------------ */

/** Appel de l'API d'Anthropic. $contenu : blocs de message (texte, image, document). Renvoie le JSON demandé. */
function cm_ia(array $contenu, int $max = 8000): array
{
    $o = cm_options();
    if ($o['ia_cle'] === '') {
        throw new RuntimeException("La lecture automatique n'est pas encore activée : ajoute la clé de l'IA dans Réglages.");
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException("L'hébergement ne permet pas d'appeler l'IA (extension curl absente).");
    }
    $modeles = $o['ia_modele'] !== '' ? [$o['ia_modele']] : CM_MODELES;
    $derniere = '';
    foreach ($modeles as $modele) {
        $corps = json_encode([
            'model' => $modele,
            'max_tokens' => $max,
            'messages' => [['role' => 'user', 'content' => $contenu]],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 170,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . $o['ia_cle']],
            CURLOPT_POSTFIELDS => $corps,
        ]);
        $rep = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($rep === false) {
            throw new RuntimeException("L'IA ne répond pas ($err). Réessaie dans un instant.");
        }
        $d = json_decode((string) $rep, true) ?: [];
        if ($code === 404 && count($modeles) > 1) {
            $derniere = $d['error']['message'] ?? 'modèle introuvable';
            continue; // modèle pas disponible sur ce compte : on essaie le suivant
        }
        if ($code === 401) {
            throw new RuntimeException("La clé de l'IA est refusée : vérifie-la dans Réglages.");
        }
        if ($code === 429 || $code === 529) {
            throw new RuntimeException("L'IA est très demandée en ce moment. Réessaie dans une minute.");
        }
        if ($code >= 400) {
            throw new RuntimeException("L'IA a refusé la demande : " . ($d['error']['message'] ?? "erreur $code"));
        }
        if ($o['ia_modele'] === '') {
            cm_option('ia_modele', $modele); // on garde le premier modèle qui marche
        }
        $texte = '';
        foreach ($d['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'text') {
                $texte .= $b['text'];
            }
        }
        $a = strpos($texte, '{');
        $z = strrpos($texte, '}');
        $json = $a !== false && $z !== false ? json_decode(substr($texte, $a, $z - $a + 1), true) : null;
        if (!is_array($json)) {
            throw new RuntimeException("Le document n'a pas pu être lu correctement. Essaie un fichier plus net.");
        }
        return $json;
    }
    throw new RuntimeException("Aucun modèle d'IA disponible ($derniere).");
}

/** Requête de l'appli : {prompt, images: [base64 jpeg], pdf: base64}. */
function cm_ia_requete(array $q): array
{
    $contenu = [];
    if (!empty($q['pdf']) && is_string($q['pdf'])) {
        $contenu[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => preg_replace('/^data:[^,]+,/', '', $q['pdf'])]];
    }
    foreach (array_slice(is_array($q['images'] ?? null) ? $q['images'] : [], 0, 6) as $img) {
        if (!is_string($img)) {
            continue;
        }
        $type = preg_match('/^data:(image\/(?:png|jpeg|webp|gif))/', $img, $m) ? $m[1] : 'image/jpeg';
        $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $type, 'data' => preg_replace('/^data:[^,]+,/', '', $img)]];
    }
    $prompt = trim((string) ($q['prompt'] ?? ''));
    if ($prompt === '') {
        erreur('Demande vide.');
    }
    $contenu[] = ['type' => 'text', 'text' => $prompt];
    try {
        return cm_ia($contenu);
    } catch (RuntimeException $e) {
        erreur($e->getMessage(), 502);
    }
}

/* ------------------------------------------------------------------ */
/* Factures reçues par mail                                            */
/* ------------------------------------------------------------------ */

const CM_PROMPT_FACTURE = <<<TXT
Tu lis une facture ou un bon de livraison d'un fournisseur de restaurant en France.
Extrais chaque produit alimentaire ou boisson acheté. Ignore les frais de livraison, consignes, emballages, remises globales, sous-totaux, totaux et lignes de TVA.
Pour chaque produit, calcule le prix d'achat HT ramené à l'unité de base : par kg, par litre ou par pièce.
Exemples : carton de 6 bouteilles de 1 L à 9,00 € HT → 1,50 €/L ; sac de 10 kg à 12,00 € → 1,20 €/kg ; plateau de 30 œufs à 8,40 € → 0,28 €/pièce ; 2,5 kg facturés 30,00 € → 12,00 €/kg.
Si seul le prix TTC apparaît, convertis-le en HT avec le taux de TVA de la ligne (5,5 % par défaut pour l'alimentaire).
Indique les allergènes réglementaires du produit parmi : gluten, crustaces, oeufs, poissons, arachides, soja, lait, fruits_coque, celeri, moutarde, sesame, sulfites, lupin, mollusques.
Si le document n'est pas une facture ou un bon de livraison de produits (publicité, relevé, avoir sans produits…), réponds {"facture": false}.
Réponds uniquement avec ce JSON :
{"facture": true, "supplier": "nom du fournisseur ou null", "date": "AAAA-MM-JJ ou null", "numero": "numéro de facture ou null", "total_ht": nombre ou null, "lines": [{"name": "nom court et clair du produit en français, sans code article", "unit": "kg" | "L" | "piece", "price": prix HT par unité de base (nombre), "allergens": ["lait"], "detail": "conditionnement et prix d'origine, très court"}]}
TXT;

/** Lit une facture enregistrée et met à jour son document. */
function cm_lire_facture(string $id, array $f): array
{
    $chemin = cm_dossier('factures') . '/' . $f['fichier'];
    $bin = is_file($chemin) ? file_get_contents($chemin) : false;
    if ($bin === false) {
        return $f + ['statut' => 'erreur', 'erreur' => 'Fichier introuvable.'];
    }
    $pdf = str_ends_with($f['fichier'], '.pdf');
    $bloc = $pdf
        ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bin)]]
        : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => str_ends_with($f['fichier'], '.png') ? 'image/png' : 'image/jpeg', 'data' => base64_encode($bin)]];
    try {
        $r = cm_ia([$bloc, ['type' => 'text', 'text' => CM_PROMPT_FACTURE]]);
        if (($r['facture'] ?? true) === false) {
            $f['statut'] = 'ignoree';
            $f['erreur'] = "Ce n'est pas une facture de produits.";
        } else {
            $f['fournisseur'] = (string) ($r['supplier'] ?? '');
            $f['date'] = (string) ($r['date'] ?? '');
            $f['numero'] = (string) ($r['numero'] ?? '');
            $f['total_ht'] = is_numeric($r['total_ht'] ?? null) ? (float) $r['total_ht'] : null;
            $f['lignes'] = array_values(array_filter(is_array($r['lines'] ?? null) ? $r['lines'] : [], fn($l) => is_array($l) && !empty($l['name'])));
            $f['statut'] = $f['lignes'] ? 'a_valider' : 'ignoree';
            $f['erreur'] = $f['lignes'] ? '' : 'Aucun produit trouvé.';
        }
    } catch (RuntimeException $e) {
        $f['statut'] = 'erreur';
        $f['erreur'] = $e->getMessage();
    }
    $f['lu_le'] = maintenant();
    cm_ecrire([['coll' => 'factures', 'id' => $id, 'data' => $f]]);
    return $f;
}

function cm_facture_relire(string $id): array
{
    [$docs] = cm_tout();
    $f = ((array) $docs['factures'])[$id] ?? erreur('Facture introuvable.', 404);
    return ['facture' => cm_lire_facture($id, $f)];
}

/** Pièces jointes PDF / images d'un message. */
function cm_pieces($mbox, int $num, $struct, string $prefixe = ''): array
{
    $out = [];
    foreach (($struct->parts ?? []) as $i => $p) {
        $n = $prefixe === '' ? (string) ($i + 1) : $prefixe . '.' . ($i + 1);
        if (!empty($p->parts)) {
            $out = array_merge($out, cm_pieces($mbox, $num, $p, $n));
            continue;
        }
        $nom = '';
        foreach (array_merge($p->dparameters ?? [], $p->parameters ?? []) as $x) {
            if (in_array(strtolower($x->attribute), ['filename', 'name'], true)) {
                $nom = imap_utf8($x->value);
            }
        }
        $sous = strtolower($p->subtype ?? '');
        $ext = str_ends_with(strtolower($nom), '.pdf') || $sous === 'pdf' ? 'pdf'
            : (preg_match('/\.(jpe?g)$/i', $nom) || $sous === 'jpeg' ? 'jpg' : (preg_match('/\.png$/i', $nom) || $sous === 'png' ? 'png' : ''));
        if ($ext === '' || ($nom === '' && $ext !== 'pdf')) {
            continue;
        }
        $brut = imap_fetchbody($mbox, $num, $n, FT_PEEK);
        $bin = (int) ($p->encoding ?? 0) === 3 ? base64_decode($brut) : ((int) ($p->encoding ?? 0) === 4 ? quoted_printable_decode($brut) : $brut);
        if (strlen($bin) < 2000 || strlen($bin) > 15_000_000) {
            continue; // logos, signatures, fichiers énormes
        }
        $out[] = ['nom' => $nom ?: "facture.$ext", 'ext' => $ext, 'bin' => $bin];
    }
    return $out;
}

/**
 * Relève la boîte des factures : nouveaux mails des 30 derniers jours avec PDF ou photo,
 * enregistrés puis lus par l'IA. Au plus toutes les 15 minutes, sauf demande explicite.
 * Les messages ne sont pas marqués comme lus dans la boîte mail.
 */
function cm_relever_factures(bool $forcer = false): array
{
    $o = cm_options();
    if (!$o['mail_actif'] || $o['imap_user'] === '' || $o['imap_mdp'] === '') {
        return ['releve' => false, 'message' => 'Relève des factures par mail désactivée.'];
    }
    if (!$forcer && $o['derniere_releve'] !== '' && strtotime($o['derniere_releve']) > time() - 900) {
        return ['releve' => false, 'message' => 'Déjà relevée il y a moins de 15 minutes.'];
    }
    if (!function_exists('imap_open')) {
        return ['releve' => false, 'message' => "L'hébergement ne permet pas de lire les mails (extension IMAP absente)."];
    }
    cm_option('derniere_releve', maintenant());
    @set_time_limit(300);
    ignore_user_abort(true);
    $mbox = @imap_open('{' . ($o['imap_hote'] ?: 'imap.hostinger.com') . ':993/imap/ssl}INBOX', $o['imap_user'], $o['imap_mdp'], OP_READONLY, 1);
    if (!$mbox) {
        $msg = 'Connexion à la boîte mail impossible : ' . (imap_last_error() ?: 'identifiants refusés');
        cm_option('derniere_releve_msg', $msg);
        imap_errors();
        return ['releve' => false, 'message' => $msg];
    }
    $nums = imap_search($mbox, 'SINCE "' . date('d-M-Y', strtotime('-30 days')) . '"') ?: [];
    $lus = cm_db()->prepare('SELECT 1 FROM mails_lus WHERE cle = ?');
    $marquer = cm_db()->prepare('INSERT OR IGNORE INTO mails_lus (cle, quand) VALUES (?, ?)');
    $nouvelles = 0;
    $lues = 0;
    foreach (array_reverse($nums) as $num) {
        if ($lues >= 4) {
            break; // le reste à la prochaine relève, pour ne pas dépasser le temps permis
        }
        $h = imap_headerinfo($mbox, $num);
        $cle = sha1(($h->message_id ?? '') . '|' . ($h->date ?? '') . '|' . ($h->subject ?? ''));
        $lus->execute([$cle]);
        if ($lus->fetchColumn()) {
            continue;
        }
        $marquer->execute([$cle, maintenant()]);
        $pieces = cm_pieces($mbox, $num, imap_fetchstructure($mbox, $num));
        $de = isset($h->from[0]) ? trim(($h->from[0]->personal ?? '') . ' <' . ($h->from[0]->mailbox ?? '') . '@' . ($h->from[0]->host ?? '') . '>') : '';
        foreach ($pieces as $p) {
            $id = bin2hex(random_bytes(10));
            $fichier = $id . '.' . $p['ext'];
            file_put_contents(cm_dossier('factures') . '/' . $fichier, $p['bin']);
            $f = ['fichier' => $fichier, 'nom' => $p['nom'], 'expediteur' => imap_utf8($de), 'sujet' => imap_utf8((string) ($h->subject ?? '')),
                'recu_le' => date('Y-m-d H:i:s', strtotime((string) ($h->date ?? 'now')) ?: time()), 'statut' => 'lecture', 'lignes' => []];
            cm_ecrire([['coll' => 'factures', 'id' => $id, 'data' => $f]]);
            cm_lire_facture($id, $f);
            $nouvelles++;
            $lues++;
        }
    }
    imap_close($mbox);
    imap_errors();
    $msg = $nouvelles ? "$nouvelles facture" . ($nouvelles > 1 ? 's' : '') . ' reçue' . ($nouvelles > 1 ? 's' : '') . '.' : 'Aucune nouvelle facture.';
    cm_option('derniere_releve_msg', $msg);
    return ['releve' => true, 'nouvelles' => $nouvelles, 'message' => $msg];
}

/* ------------------------------------------------------------------ */
/* Dépôt de factures (bouton de l'appli ou dossier du Mac)             */
/* ------------------------------------------------------------------ */

function cm_cle_depot(bool $nouvelle = false): string
{
    $o = cm_options();
    $c = (string) ($o['depot_cle'] ?? '');
    if ($c === '' || $nouvelle) {
        $c = bin2hex(random_bytes(20));
        cm_option('depot_cle', $c);
    }
    return $c;
}

function cm_cle_depot_ok(string $cle): bool
{
    $c = (string) (cm_options()['depot_cle'] ?? '');
    return $c !== '' && hash_equals($c, $cle);
}

/** Enregistre un PDF ou une photo de facture, puis la fait lire par l'IA. Un même fichier n'est pris qu'une fois. */
function cm_facture_deposer(string $nom, string $b64): array
{
    $bin = base64_decode(preg_replace('/^data:[^,]+,/', '', $b64), true);
    if ($bin === false || strlen($bin) < 500 || strlen($bin) > 20_000_000) {
        erreur("Ce fichier n'a pas pu être reçu.");
    }
    $ext = str_starts_with($bin, '%PDF') ? 'pdf' : (str_starts_with($bin, "\x89PNG") ? 'png' : (str_starts_with($bin, "\xFF\xD8") ? 'jpg' : ''));
    if ($ext === '') {
        erreur('Seuls les PDF et les photos (JPEG, PNG) sont acceptés.');
    }
    $cle = 'fichier:' . sha1($bin);
    $deja = cm_db()->prepare('SELECT 1 FROM mails_lus WHERE cle = ?');
    $deja->execute([$cle]);
    if ($deja->fetchColumn()) {
        return ['doublon' => true, 'message' => 'Facture déjà reçue.'];
    }
    @set_time_limit(240);
    $id = bin2hex(random_bytes(10));
    $fichier = $id . '.' . $ext;
    file_put_contents(cm_dossier('factures') . '/' . $fichier, $bin);
    cm_db()->prepare('INSERT OR IGNORE INTO mails_lus (cle, quand) VALUES (?, ?)')->execute([$cle, maintenant()]);
    $f = ['fichier' => $fichier, 'nom' => mb_substr($nom !== '' ? $nom : "facture.$ext", 0, 120), 'expediteur' => 'Dépôt', 'sujet' => '',
        'recu_le' => maintenant(), 'statut' => 'lecture', 'lignes' => []];
    cm_ecrire([['coll' => 'factures', 'id' => $id, 'data' => $f]]);
    $f = cm_lire_facture($id, $f);
    return ['doublon' => false, 'id' => $id, 'facture' => $f];
}
