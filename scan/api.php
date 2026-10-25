<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!is_logged()) {
    json_out(['ok' => false, 'error' => 'Session expirée, recharge la page.'], 401);
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'scan') {
    json_out(['ok' => false, 'error' => 'Requête refusée.'], 400);
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'list') {
        json_out(['ok' => true, 'files' => inbox_list()]);
    }

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Le fichier arrive encodé en base64 dans le corps de la requête (le filtre de
        // sécurité de l'hébergeur bloquait certains PDF envoyés tels quels).
        $cat = (string) ($_GET['category'] ?? '');
        $toInbox = ($cat === 'inbox');
        if (!$toInbox && !isset(CATEGORIES[$cat])) {
            json_out(['ok' => false, 'error' => 'Catégorie inconnue.'], 400);
        }
        $raw = (string) file_get_contents('php://input');
        $data = base64_decode(preg_replace('/\s+/', '', $raw), true);
        if ($data === false || $data === '') {
            json_out(['ok' => false, 'error' => 'Le fichier n\'est pas arrivé.'], 400);
        }
        if (strlen($data) > 25 * 1024 * 1024) {
            json_out(['ok' => false, 'error' => 'Fichier trop gros (25 Mo maximum).'], 400);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (!$ext) {
            json_out(['ok' => false, 'error' => 'Seuls les PDF, JPEG et PNG sont acceptés.'], 400);
        }

        $date = (string) ($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $label = clean_label((string) ($_GET['label'] ?? ''));
        $name = $date . '_' . ($label !== '' ? str_replace(' ', '-', $label) : 'Document') . '.' . $ext;

        $res = drive_upload_to(
            $data,
            $name,
            $mime,
            fn() => $toInbox ? folder_inbox() : folder_category($cat, substr($date, 0, 4))
        );
        json_out([
            'ok'     => true,
            'name'   => $res['name'] ?? $name,
            'where'  => $toInbox ? INBOX_NAME : CATEGORIES[$cat],
        ]);
    }

    if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cat = (string) ($_POST['category'] ?? '');
        $id = (string) ($_POST['id'] ?? '');
        if (!isset(CATEGORIES[$cat]) || !preg_match('/^[A-Za-z0-9_-]{10,}$/', $id)) {
            json_out(['ok' => false, 'error' => 'Demande invalide.'], 400);
        }
        drive_move($id, $cat);
        json_out(['ok' => true, 'where' => CATEGORIES[$cat]]);
    }

    json_out(['ok' => false, 'error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    error_log('scan api: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 500);
}
