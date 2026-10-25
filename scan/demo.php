<?php
/**
 * Démo du scan (le76.fr/demo/scan) : pas de Google Drive, pas de mot de passe.
 * Les documents envoyés ne sont pas conservés : seule une liste fictive « À classer »
 * est gardée le temps de la visite (session du navigateur).
 */
declare(strict_types=1);

function demo_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('le76_scan_demo');
        session_set_cookie_params(['path' => '/demo/scan/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
    if (!isset($_SESSION['inbox']) || isset($_GET['reinit'])) {
        $j = fn(int $n) => date('Y-m-d', strtotime("-$n day"));
        $_SESSION['inbox'] = [];
        foreach ([['Grossiste du Rhin', 1], ['Brasserie de l Ill', 2], ['Boucherie Kern', 3], ['Assurances du Ried', 6]] as $i => [$f, $n]) {
            $_SESSION['inbox'][] = ['id' => 'demo' . str_repeat('x', 8) . $i, 'name' => $j($n) . '_' . str_replace(' ', '-', $f) . '.pdf', 'createdTime' => $j($n) . 'T09:00:00Z'];
        }
        $_SESSION['n'] = 10;
    }
}

function demo_vue(array $f): array
{
    return $f + ['webViewLink' => 'apercu.php?n=' . rawurlencode($f['name'])];
}

function demo_api(string $action): never
{
    demo_session();
    if ($action === 'list') {
        json_out(['ok' => true, 'files' => array_map('demo_vue', $_SESSION['inbox'])]);
    }
    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cat = (string) ($_GET['category'] ?? '');
        $toInbox = $cat === 'inbox';
        if (!$toInbox && !isset(CATEGORIES[$cat])) {
            json_out(['ok' => false, 'error' => 'Catégorie inconnue.'], 400);
        }
        // Le fichier est lu puis oublié : rien n'est enregistré dans la démo.
        $taille = strlen((string) file_get_contents('php://input'));
        if ($taille < 10) {
            json_out(['ok' => false, 'error' => 'Le fichier n\'est pas arrivé.'], 400);
        }
        $date = (string) ($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $label = clean_label((string) ($_GET['label'] ?? ''));
        $name = $date . '_' . ($label !== '' ? str_replace(' ', '-', $label) : 'Document') . '.pdf';
        if ($toInbox) {
            array_unshift($_SESSION['inbox'], ['id' => 'demo' . str_repeat('x', 8) . ($_SESSION['n']++), 'name' => $name, 'createdTime' => date('c')]);
        }
        json_out(['ok' => true, 'name' => $name, 'where' => ($toInbox ? INBOX_NAME : CATEGORIES[$cat]) . ' (démo : rien n\'est enregistré)']);
    }
    if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cat = (string) ($_POST['category'] ?? '');
        $id = (string) ($_POST['id'] ?? '');
        if (!isset(CATEGORIES[$cat])) {
            json_out(['ok' => false, 'error' => 'Demande invalide.'], 400);
        }
        $_SESSION['inbox'] = array_values(array_filter($_SESSION['inbox'], fn($f) => $f['id'] !== $id));
        json_out(['ok' => true, 'where' => CATEGORIES[$cat] . ' (démo)']);
    }
    json_out(['ok' => false, 'error' => 'Action inconnue.'], 400);
}

function demo_bandeau(): void
{
    $b = 'color:#fff;background:rgba(255,255,255,.18);border-radius:99px;padding:4px 11px;text-decoration:none';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 14px;background:#5a4fcf;color:#fff;font:600 13px/1.3 -apple-system,BlinkMacSystemFont,sans-serif">'
        . '<span>Démo · scan des documents · rien n\'est envoyé ni conservé (le vrai outil range tout dans Google Drive)</span>'
        . '<span style="display:flex;gap:6px"><a href="/demo/" style="' . $b . '">Accueil démo</a><a href="./?reinit=1" style="' . $b . '">Réinitialiser</a></span></div>';
}
