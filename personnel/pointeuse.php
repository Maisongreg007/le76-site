<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$etab = kiosk_etab();

/* ---------- Actions (JSON) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = static function (array $d, int $code = 200): never {
        http_response_code($code);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if ($etab === null) {
        $out(['ok' => false, 'error' => "Cet appareil n'est pas activé."], 403);
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'ping') {
        // L'iPad de pointage reste allumé : il sert aussi à déclencher les alertes (retards, dépassements)
        try { verifier_alertes(); } catch (Throwable $t) { }
        $out(['ok' => true]);
    }

    if ($action === 'pin') {
        if (too_many('pin', 6, 120)) {
            $out(['ok' => false, 'error' => 'Trop d\'essais, patiente une minute.'], 429);
        }
        $pin = (string)($_POST['pin'] ?? '');
        $st = db()->prepare('SELECT * FROM employes WHERE pin = ? AND actif = 1');
        $st->execute([$pin]);
        $e = $st->fetch();
        if (!$e || !in_array($etab, emp_etabs($e), true)) {
            note_fail('pin');
            $out(['ok' => false, 'error' => 'Code inconnu'], 200);
        }
        $now = date('Y-m-d H:i:s');
        $cur = pointage_en_cours((int)$e['id']);
        if ($cur) {
            db()->prepare('UPDATE pointages SET fin = ? WHERE id = ?')->execute([$now, $cur['id']]);
            $out([
                'ok' => true, 'action' => 'depart', 'id' => (int)$cur['id'],
                'prenom' => emp_court($e), 'heure' => date('H:i'),
                'duree' => fmt_min(minutes_between($cur['debut'], $now)),
            ]);
        }
        db()->prepare('INSERT INTO pointages (employe_id, etab, debut) VALUES (?, ?, ?)')->execute([$e['id'], $etab, $now]);
        $out([
            'ok' => true, 'action' => 'arrivee', 'id' => (int)db()->lastInsertId(),
            'prenom' => emp_court($e), 'heure' => date('H:i'),
        ]);
    }

    if ($action === 'undo') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM pointages WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            $out(['ok' => false, 'error' => 'Introuvable']);
        }
        $now = time();
        if ($p['fin'] !== null && $now - strtotime($p['fin']) <= 180) {
            db()->prepare('UPDATE pointages SET fin = NULL WHERE id = ?')->execute([$id]);
            $out(['ok' => true]);
        }
        if ($p['fin'] === null && $now - strtotime($p['debut']) <= 180) {
            db()->prepare('DELETE FROM pointages WHERE id = ?')->execute([$id]);
            $out(['ok' => true]);
        }
        $out(['ok' => false, 'error' => 'Trop tard pour annuler']);
    }
    $out(['ok' => false, 'error' => 'Action inconnue'], 400);
}

/* ---------- Page ---------- */
page_head('Pointeuse', 'manifest-pointeuse.json', 'kiosk');

if ($etab === null) {
    echo '<div class="off"><h1>Appareil non activé</h1><p class="muted">Pour utiliser cet appareil comme pointeuse : connecte-toi à l\'administration, '
        . 'va dans <b>Réglages</b> puis « Utiliser cet appareil comme pointeuse ».</p>'
        . '<p><a class="btn primary" href="admin.php?p=reglages">Ouvrir l\'administration</a></p></div></body></html>';
    exit;
}
?>
<div class="etab"><?= h(ETABS[$etab]) ?></div>
<div class="clock" id="clock">--:--</div>
<div class="date" id="date"></div>
<div class="dots" id="dots"><i></i><i></i><i></i><i></i></div>
<div class="pad" id="pad">
    <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?><button type="button" data-k="<?= $n ?>"><?= $n ?></button><?php endforeach; ?>
    <button type="button" class="ghost" data-k="C">Effacer</button>
    <button type="button" data-k="0">0</button>
    <button type="button" class="ghost" data-k="B">⌫</button>
</div>
<div class="msg" id="msg"></div>

<div class="result" id="result">
    <div class="big" id="r-big"></div>
    <div class="sub" id="r-sub"></div>
    <button type="button" class="undo" id="undo">Ce n'est pas ça — annuler</button>
</div>

<script>
(function () {
  const JOURS = <?= json_encode(JOURS_LONG) ?>, MOIS = <?= json_encode(MOIS) ?>;
  const $ = id => document.getElementById(id);
  let pin = '', busy = false, lastId = null, hideT = null;

  function tick() {
    const d = new Date();
    $('clock').textContent = d.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris'});
    const p = new Date(d.toLocaleString('en-US', {timeZone: 'Europe/Paris'}));
    $('date').textContent = JOURS[(p.getDay() + 6) % 7] + ' ' + p.getDate() + ' ' + MOIS[p.getMonth()];
  }
  tick(); setInterval(tick, 1000);

  function dots() {
    [...$('dots').children].forEach((el, i) => el.classList.toggle('on', i < pin.length));
  }
  function msg(t) { $('msg').textContent = t || ''; }

  async function post(data) {
    const r = await fetch('pointeuse.php', {method: 'POST', body: new URLSearchParams(data), cache: 'no-store'});
    return r.json();
  }

  async function submit() {
    busy = true;
    try {
      const r = await post({action: 'pin', pin});
      if (r.ok) {
        lastId = r.id;
        const res = $('result');
        res.className = 'result show ' + (r.action === 'arrivee' ? 'in' : 'out');
        $('r-big').textContent = (r.action === 'arrivee' ? 'Bonjour ' : 'À bientôt ') + r.prenom;
        $('r-sub').textContent = r.action === 'arrivee'
          ? 'Arrivée enregistrée à ' + r.heure
          : 'Départ à ' + r.heure + ' — ' + r.duree + ' travaillées';
        clearTimeout(hideT); hideT = setTimeout(hideResult, 6000);
      } else {
        const d = $('dots'); d.classList.remove('shake'); void d.offsetWidth; d.classList.add('shake');
        msg(r.error || 'Erreur');
        setTimeout(() => msg(''), 2500);
      }
    } catch (e) {
      msg('Pas de connexion, réessaie');
      setTimeout(() => msg(''), 2500);
    }
    pin = ''; dots(); busy = false;
  }

  function hideResult() { $('result').className = 'result'; lastId = null; }

  $('result').addEventListener('click', e => { if (e.target.id !== 'undo') hideResult(); });
  $('undo').addEventListener('click', async () => {
    if (lastId === null) return;
    const r = await post({action: 'undo', id: lastId});
    hideResult();
    msg(r.ok ? 'Annulé' : (r.error || 'Impossible d\'annuler'));
    setTimeout(() => msg(''), 2500);
  });

  $('pad').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || busy) return;
    const k = b.dataset.k;
    if (k === 'C') pin = '';
    else if (k === 'B') pin = pin.slice(0, -1);
    else if (pin.length < 4) pin += k;
    dots(); msg('');
    if (pin.length === 4) submit();
  });

  document.addEventListener('keydown', e => {
    if (busy) return;
    if (/^\d$/.test(e.key) && pin.length < 4) { pin += e.key; dots(); if (pin.length === 4) submit(); }
    else if (e.key === 'Backspace') { pin = pin.slice(0, -1); dots(); }
  });

  // Toutes les 2 minutes : vérifie les retards / dépassements (notifications)
  setInterval(() => { post({action: 'ping'}).catch(() => {}); }, 120000);
  post({action: 'ping'}).catch(() => {});
  // Recharge la page chaque nuit pour rester à jour
  setTimeout(() => location.reload(), 6 * 3600 * 1000);
})();
</script>
</body></html>
