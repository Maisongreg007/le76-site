<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'kiosk') {
        $e = (string)($_POST['etab'] ?? '');
        if (valid_etab($e)) {
            kiosk_set($e);
            back('admin.php?p=reglages', 'Cet appareil est maintenant la pointeuse de ' . ETABS[$e] . '.');
        }
    }
    if ($act === 'kiosk_off') {
        kiosk_clear();
        back('admin.php?p=reglages', 'Cet appareil n\'est plus une pointeuse.');
    }
    if ($act === 'share_renew') {
        share_token(true);
        back('admin.php?p=reglages', 'Nouveau lien d\'équipe créé. L\'ancien ne marche plus : renvoie le nouveau à l\'équipe.');
    }
    if ($act === 'password') {
        $old = (string)($_POST['old'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify($old, (string)setting('admin_hash'))) {
            back('admin.php?p=reglages', '', 'Ancien mot de passe incorrect.');
        }
        if (mb_strlen($new) < 8) {
            back('admin.php?p=reglages', '', 'Le nouveau mot de passe doit faire au moins 8 caractères.');
        }
        set_setting('admin_hash', password_hash($new, PASSWORD_DEFAULT));
        back('admin.php?p=reglages', 'Mot de passe changé.');
    }
    if ($act === 'notifs') {
        set_setting('notif_ntfy', isset($_POST['ntfy']) ? '1' : '0');
        $mail = trim((string)($_POST['email'] ?? ''));
        set_setting('notif_email', filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '');
        set_setting('notif_retard', (string)max(5, min(120, (int)($_POST['retard'] ?? 15))));
        set_setting('notif_depasse', (string)max(5, min(240, (int)($_POST['depasse'] ?? 30))));
        back('admin.php?p=reglages#notifs', 'Notifications enregistrées.');
    }
    if ($act === 'notif_test') {
        $ok = envoyer_notif('Test Le 76', 'Les notifications du personnel fonctionnent. 👍');
        back('admin.php?p=reglages#notifs', $ok ? 'Notification de test envoyée.' : '', $ok ? '' : 'Envoi impossible : active au moins un canal (ntfy ou e-mail) et enregistre.');
    }
    if ($act === 'compta') {
        $mail = trim((string)($_POST['compta'] ?? ''));
        set_setting('compta_email', filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '');
        $from = trim((string)($_POST['from'] ?? ''));
        set_setting('mail_from', filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '');
        back('admin.php?p=reglages#compta', 'Enregistré.');
    }
    if ($act === 'tolerance') {
        set_setting('tolerance', (string)max(0, min(120, (int)($_POST['tolerance'] ?? 15))));
        back('admin.php?p=reglages', 'Tolérance enregistrée.');
    }
    if ($act === 'presets') {
        $txt = (string)($_POST['presets'] ?? '');
        $ok = [];
        foreach (preg_split('/\R/', $txt) as $line) {
            $p = array_map('trim', explode(';', $line));
            if (count($p) === 3 && $p[0] !== '' && valid_time($p[1]) && valid_time($p[2])) {
                $ok[] = implode(';', $p);
            }
        }
        set_setting('presets', implode("\n", $ok));
        back('admin.php?p=reglages', 'Horaires rapides enregistrés.');
    }
    if ($act === 'backup') {
        $f = db_file();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="sauvegarde-personnel-' . date('Y-m-d') . '.sqlite"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    redirect('admin.php?p=reglages');
}

$ke = kiosk_etab();
page_head('Réglages');
admin_nav('reglages');
flash_show();
echo '<h1>Réglages</h1>';

$th = in_array($_COOKIE['theme'] ?? '', ['light', 'dark'], true) ? $_COOKIE['theme'] : 'system';
echo '<div class="card" style="max-width:640px"><h3>Apparence</h3><p class="muted small" style="margin-top:0">Pour cet appareil. « Système » suit le réglage clair/sombre de l\'iPad ou du Mac.</p><div class="theme-pick" id="theme-pick">';
foreach (['system' => ['Système', 's'], 'light' => ['Clair', 'l'], 'dark' => ['Sombre', 'd']] as $k => [$l, $c]) {
    echo '<button type="button" data-theme="' . $k . '"' . ($th === $k ? ' class="on"' : '') . '><span class="sw ' . $c . '"></span>' . h($l) . '</button>';
}
echo '</div></div>';
?>
<script>
document.getElementById('theme-pick').addEventListener('click', function (e) {
  const b = e.target.closest('button'); if (!b) return;
  const t = b.dataset.theme;
  const path = <?= json_encode(base_path()) ?>;
  document.cookie = 'theme=' + (t === 'system' ? '' : t) + ';path=' + path + ';max-age=' + (t === 'system' ? 0 : 31536000) + ';samesite=lax';
  if (t === 'system') document.documentElement.removeAttribute('data-theme'); else document.documentElement.dataset.theme = t;
  this.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
});
</script>
<?php
echo '<div class="card" style="max-width:640px"><h3>Pointeuse sur cet appareil</h3>';
if ($ke) {
    echo '<p><span class="pill ok">Activée</span> Cet appareil est la pointeuse de <b>' . h(ETABS[$ke]) . '</b>.</p>'
        . '<div class="row"><a class="btn primary" href="pointeuse.php">Ouvrir la pointeuse</a>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="kiosk_off"><button class="danger">Désactiver</button></form></div>';
} else {
    echo '<p class="muted">À faire une seule fois sur l\'iPad qui servira à pointer. Sur un autre appareil (ton téléphone, chez toi), la pointeuse ne fonctionnera pas : personne ne peut pointer à distance.</p><div class="row">';
    foreach (ETABS as $k => $l) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="kiosk"><input type="hidden" name="etab" value="' . h($k) . '"><button class="primary">Pointeuse du ' . h($l) . '</button></form>';
    }
    echo '</div>';
}
echo '<p class="muted small" style="margin-bottom:0">Astuce iPad : une fois la pointeuse ouverte, « Partager » puis « Sur l\'écran d\'accueil » pour l\'avoir en plein écran comme une appli.</p></div>';

$topic = notif_topic();
echo '<div class="card" style="max-width:640px" id="notifs"><h3>Notifications sur ton téléphone</h3>'
    . '<p class="muted small" style="margin-top:0">Tu es prévenu quand quelqu\'un n\'est pas arrivé à l\'heure ou dépasse son horaire. Les vérifications sont faites par l\'iPad de pointage (qui reste allumé) et quand l\'administration est ouverte.</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="notifs">'
    . '<label class="inline-check" style="margin-bottom:8px"><input type="checkbox" name="ntfy"' . (setting('notif_ntfy', '0') === '1' ? ' checked' : '') . '> Notification instantanée avec l\'appli gratuite <b>ntfy</b></label>'
    . '<ol class="small muted" style="margin:0 0 12px;padding-left:20px;line-height:1.6"><li>Installe <b>ntfy</b> depuis l\'App Store (ou Google Play).</li><li>Dans l\'appli : « + » puis « S\'abonner », et colle ce nom de canal :</li></ol>'
    . '<div class="linkbox" style="margin-bottom:14px">' . h($topic) . '</div>'
    . '<div class="field"><label>Et/ou par e-mail</label><input type="email" name="email" value="' . h((string)setting('notif_email', '')) . '" placeholder="ton@email.fr" style="width:100%"></div>'
    . '<div class="row"><div class="field"><label>Prévenir si pas arrivé après</label><select name="retard">';
foreach ([5, 10, 15, 20, 30, 45, 60] as $m) echo '<option value="' . $m . '"' . ((int)setting('notif_retard', '15') === $m ? ' selected' : '') . '>' . $m . ' min</option>';
echo '</select></div><div class="field"><label>Prévenir si dépasse de plus de</label><select name="depasse">';
foreach ([15, 30, 45, 60, 90, 120] as $m) echo '<option value="' . $m . '"' . ((int)setting('notif_depasse', '30') === $m ? ' selected' : '') . '>' . $m . ' min</option>';
echo '</select></div></div><div class="row"><button class="primary">Enregistrer</button></div></form>'
    . '<form method="post" style="margin-top:10px">' . csrf_field() . '<input type="hidden" name="action" value="notif_test"><button class="small">Envoyer une notification de test</button></form>'
    . '<p class="muted small" style="margin-bottom:0">Le nom du canal est secret : ne le partage pas. Les messages passent par le service ntfy.sh (prénom + heure).</p></div>';

echo '<div class="card" style="max-width:640px" id="compta"><h3>Comptable</h3><p class="muted small" style="margin-top:0">Quand tu clôtures un mois (page Heures), le fichier des heures peut partir directement à cette adresse.</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="compta">'
    . '<div class="field"><label>E-mail du comptable</label><input type="email" name="compta" value="' . h((string)setting('compta_email', '')) . '" placeholder="cabinet@exemple.fr" style="width:100%"></div>'
    . '<div class="field"><label>Adresse d\'envoi (une boîte de ton domaine)</label><input type="email" name="from" value="' . h((string)setting('mail_from', '')) . '" placeholder="' . h(expediteur()) . '" style="width:100%"></div>'
    . '<button class="primary">Enregistrer</button></form></div>';

echo '<div class="card" style="max-width:640px"><h3>Tolérance prévu / réel</h3><p class="muted small" style="margin-top:0">En dessous de cet écart, on considère que l\'horaire est respecté (pas d\'alerte).</p>'
    . '<form method="post" class="row">' . csrf_field() . '<input type="hidden" name="action" value="tolerance"><select name="tolerance">';
foreach ([0, 5, 10, 15, 20, 30, 45, 60] as $t) {
    echo '<option value="' . $t . '"' . ($t === tolerance() ? ' selected' : '') . '>' . ($t ? $t . ' minutes' : 'aucune') . '</option>';
}
echo '</select><button class="primary">Enregistrer</button></form></div>';

echo '<div class="card" style="max-width:640px"><h3>Horaires rapides du planning</h3><p class="muted small">Un par ligne : <code>Nom;début;fin</code>. Ils apparaissent comme boutons quand tu ajoutes un horaire.</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="presets"><textarea name="presets" rows="5" style="width:100%">'
    . h(implode("\n", array_map(fn($p) => implode(';', $p), presets()))) . '</textarea><p><button class="primary">Enregistrer</button></p></form></div>';

echo '<div class="card" style="max-width:640px"><h3>Lien du planning d\'équipe</h3><p class="muted small">Si le lien partagé a circulé là où il ne fallait pas (ancien employé…), crée-en un nouveau. L\'ancien cessera de marcher.</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="share_renew"><button data-confirm="Sûr ? L\'ancien lien ne marchera plus">Créer un nouveau lien d\'équipe</button></form></div>';

echo '<div class="card" style="max-width:640px"><h3>Sauvegarde</h3><p class="muted small">Télécharge une copie de toutes tes données (équipe, planning, pointages). À faire de temps en temps, par exemple en fin de mois.</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="backup"><button>Télécharger une sauvegarde</button></form></div>';

echo '<div class="card" style="max-width:640px"><h3>Mot de passe</h3><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="password">'
    . '<div class="field"><label>Ancien</label><input type="password" name="old" required autocomplete="current-password"></div>'
    . '<div class="field"><label>Nouveau (8 caractères minimum)</label><input type="password" name="new" required minlength="8" autocomplete="new-password"></div>'
    . '<button>Changer</button></form></div>';

echo '<p><a href="admin.php?logout=1">Se déconnecter</a></p>';
admin_end();
