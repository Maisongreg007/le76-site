<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$A = defined('SCAN_DEMO') ? '/scan/' : '';   // chemin des images (la démo est servie depuis /demo/scan/)

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (try_login((string) $_POST['password'])) {
        header('Location: ./');
        exit;
    }
    $error = login_blocked()
        ? 'Trop d\'essais. Réessaie dans un quart d\'heure.'
        : 'Mot de passe incorrect.';
}
$logged = is_logged();
$cats = CATEGORIES;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Scan 76">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<meta name="theme-color" content="#f2f2f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<?php if (!$A): ?><link rel="manifest" href="manifest.webmanifest"><?php endif; ?>
<link rel="apple-touch-icon" href="<?= $A ?>apple-touch-icon.png?v=2">
<link rel="icon" type="image/png" href="<?= $A ?>icon-192.png?v=2">
<title>Scan 76</title>
<style>
  :root {
    --bg:#f2f2f7; --card:#ffffff; --ink:#000; --mute:#8a8a8e; --sep:rgba(60,60,67,.18);
    --accent:#8e2434; --accent-press:#701b28; --tint:rgba(142,36,52,.10);
    --ok:#248a3d; --ko:#d70015; --fill:rgba(120,120,128,.12);
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#000; --card:#1c1c1e; --ink:#fff; --mute:#98989f; --sep:rgba(84,84,88,.55);
            --accent:#e0566a; --accent-press:#c9475a; --tint:rgba(224,86,106,.16); --ok:#30d158; --ko:#ff453a; --fill:rgba(120,120,128,.24); }
  }
  * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
  html { -webkit-text-size-adjust:100%; }
  body { margin:0; background:var(--bg); color:var(--ink);
         font:17px/1.35 -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", system-ui, sans-serif;
         -webkit-font-smoothing:antialiased; overscroll-behavior-y:none; }
  main { max-width:560px; margin:0 auto; padding:calc(env(safe-area-inset-top) + 12px) 16px calc(env(safe-area-inset-bottom) + 40px); }
  header { padding:14px 4px 10px; }
  h1 { font:700 34px/1.1 -apple-system, "SF Pro Display", system-ui, sans-serif; letter-spacing:.2px; margin:0; }
  .sub { color:var(--mute); font-size:15px; margin-top:4px; }

  .group-title { font-size:13px; text-transform:uppercase; letter-spacing:.2px; color:var(--mute); margin:26px 16px 7px; }
  .group { background:var(--card); border-radius:12px; overflow:hidden; }
  .pad { padding:16px; }
  .row { display:flex; align-items:center; gap:12px; padding:0 16px; min-height:48px; position:relative; }
  .row + .row::before { content:""; position:absolute; top:0; left:16px; right:0; height:.5px; background:var(--sep); }
  .row label { flex:0 0 auto; margin:0; }
  .row input { flex:1; min-width:0; border:0; background:transparent; color:var(--ink); font:inherit; text-align:right; padding:12px 0; outline:none; }
  .row input::placeholder { color:var(--mute); opacity:.8; }
  input[type=date] { min-height:24px; }

  button { font:inherit; font-weight:600; border:0; border-radius:12px; min-height:50px; width:100%; padding:0 16px; cursor:pointer; color:inherit; background:none; touch-action:manipulation; }
  .btn-main { background:var(--accent); color:#fff; }
  .btn-main:active { background:var(--accent-press); }
  .btn-soft { background:var(--tint); color:var(--accent); }
  .btn-soft:active { filter:brightness(.92); }
  .btns { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  button:disabled { opacity:.35; }

  .thumbs { display:flex; gap:10px; overflow-x:auto; padding:14px 16px 4px; -webkit-overflow-scrolling:touch; }
  .thumbs:empty { display:none; }
  .thumb { position:relative; flex:0 0 auto; width:76px; height:100px; border-radius:8px; background:var(--fill); display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; color:var(--mute); }
  .thumb img { width:100%; height:100%; object-fit:cover; border-radius:8px; }
  .thumb b { position:absolute; top:-6px; right:-6px; background:#3a3a3c; color:#fff; width:22px; height:22px; border-radius:11px; font-size:15px; line-height:21px; text-align:center; font-weight:400; box-shadow:0 0 0 2px var(--card); }
  .hint { color:var(--mute); font-size:13px; margin:0; padding:10px 16px 14px; }

  .cat { display:flex; align-items:center; gap:14px; width:100%; min-height:52px; padding:0 16px; border-radius:0; font-weight:400; text-align:left; position:relative; }
  .cat + .cat::before { content:""; position:absolute; top:0; left:58px; right:0; height:.5px; background:var(--sep); }
  .cat:active:not(:disabled) { background:var(--fill); }
  .cat .ico { width:26px; height:26px; fill:var(--accent); flex:0 0 auto; }
  .cat span { flex:1; }
  .cat::after { content:"›"; color:var(--mute); font-size:24px; line-height:1; opacity:.6; }
  .later { margin-top:12px; }

  .item { padding:12px 16px; position:relative; }
  .item + .item::before { content:""; position:absolute; top:0; left:16px; right:0; height:.5px; background:var(--sep); }
  .item .top { display:flex; justify-content:space-between; gap:12px; align-items:baseline; }
  .item .nm { overflow-wrap:anywhere; font-size:15px; }
  .item a { color:var(--accent); font-size:15px; text-decoration:none; flex:0 0 auto; }
  .item .mv { display:flex; gap:8px; margin-top:10px; }
  .item select { flex:1; min-width:0; font:inherit; font-size:16px; padding:10px; border:0; border-radius:10px; background:var(--fill); color:var(--ink); }
  .item .mv button { width:auto; min-height:42px; padding:0 18px; }

  #toast { position:fixed; left:12px; right:12px; top:calc(env(safe-area-inset-top) + 10px); max-width:536px; margin:0 auto; padding:14px 18px;
           border-radius:16px; font-size:15px; font-weight:600; color:#fff; background:rgba(36,138,61,.92);
           -webkit-backdrop-filter:blur(20px); backdrop-filter:blur(20px); box-shadow:0 8px 30px rgba(0,0,0,.18);
           opacity:0; transform:translateY(-14px); transition:opacity .25s, transform .25s; pointer-events:none; z-index:20; text-align:center; }
  #toast.show { opacity:1; transform:none; }
  #toast.err { background:rgba(215,0,21,.93); }

  .login { min-height:100vh; display:flex; flex-direction:column; justify-content:center; padding-bottom:12vh; }
  .login .app-icon { width:96px; height:96px; border-radius:22px; display:block; margin:0 auto 18px; box-shadow:0 6px 20px rgba(0,0,0,.18); }
  .login h1 { text-align:center; font-size:28px; }
  .login .sub { text-align:center; margin-bottom:26px; }
  .login .row input { text-align:left; }
  .err-txt { color:var(--ko); font-size:14px; text-align:center; margin:12px 0 0; }
  input[type=file] { display:none; }
</style>
</head>
<body>
<?php if (defined('SCAN_DEMO')) { demo_bandeau(); } ?>
<main>
<?php if (!$logged): ?>
  <form class="login" method="post" autocomplete="on">
    <img class="app-icon" src="<?= $A ?>apple-touch-icon.png?v=2" alt="">
    <h1>Documents du 76</h1>
    <div class="sub">Entre le mot de passe commun</div>
    <div class="group">
      <div class="row"><input id="pw" name="password" type="password" placeholder="Mot de passe" autocomplete="current-password" required autofocus></div>
    </div>
    <?php if ($error): ?><p class="err-txt"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <div style="height:16px"></div>
    <button class="btn-main" type="submit">Entrer</button>
  </form>
<?php else: ?>
  <header>
    <h1>Scanner</h1>
    <div class="sub">Documents du 76</div>
  </header>

  <div class="group-title">1 · Le document</div>
  <section class="group">
    <div class="pad">
      <div class="btns">
        <button class="btn-main" type="button" id="btnShoot">Photographier</button>
        <button class="btn-soft" type="button" id="btnPick">Fichier</button>
      </div>
    </div>
    <input type="file" id="inShoot" accept="image/*" capture="environment">
    <input type="file" id="inPick" accept="application/pdf,image/*" multiple>
    <div class="thumbs" id="thumbs"></div>
    <p class="hint" id="hint">Photographie chaque page, l'une après l'autre : elles seront réunies en un seul PDF.</p>
  </section>

  <div class="group-title">2 · Précisions (facultatif)</div>
  <section class="group">
    <div class="row"><label for="label">Fournisseur</label><input type="text" id="label" maxlength="60" placeholder="Metro, Groupama…" autocomplete="off"></div>
    <div class="row"><label for="date">Date</label><input type="date" id="date"></div>
  </section>

  <div class="group-title">3 · Ranger dans</div>
  <section class="group" id="cats">
    <?php foreach ($cats as $key => $name): ?>
      <button type="button" class="cat" data-cat="<?= htmlspecialchars($key) ?>" disabled><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h3.6c.5 0 1 .2 1.3.6L11.6 7H18.5A2.5 2.5 0 0 1 21 9.5v7A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z"/></svg><span><?= htmlspecialchars($name) ?></span></button>
    <?php endforeach; ?>
  </section>
  <button class="btn-soft later" type="button" id="btnLater" disabled>Je classerai plus tard</button>

  <section id="inboxCard" hidden>
    <div class="group-title">À classer <span id="inboxCount"></span></div>
    <div class="group" id="inbox"></div>
  </section>

  <div id="toast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
const CATS = <?= json_encode($cats, JSON_UNESCAPED_UNICODE) ?>;
const $ = (id) => document.getElementById(id);
const pages = [];            // {kind:'img', dataUrl, w, h}  ou  {kind:'pdf', file}
let busy = false;

$('date').value = new Date().toLocaleDateString('sv-SE');   // AAAA-MM-JJ

function toast(msg, isErr) {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'show' + (isErr ? ' err' : '');
  clearTimeout(toast.t);
  toast.t = setTimeout(() => t.className = '', isErr ? 6000 : 3500);
}

async function api(action, body, query) {
  const q = query ? '&' + new URLSearchParams(query).toString() : '';
  const opts = {method: body ? 'POST' : 'GET', headers: {'X-Requested-With': 'scan'}, credentials: 'same-origin'};
  if (typeof body === 'string') { opts.body = body; opts.headers['Content-Type'] = 'application/octet-stream'; }
  else if (body) { opts.body = body; }
  const r = await fetch('api.php?action=' + action + q, opts);
  let j;
  try { j = await r.json(); }
  catch (e) { throw new Error(r.status === 403 ? 'Envoi bloqué par l\'hébergeur. Réessaie, ou prends le document en photo.' : 'Réponse inattendue du serveur.'); }
  if (r.status === 401) { location.reload(); }
  if (!j.ok) throw new Error(j.error || 'Erreur');
  return j;
}

function toBase64(blob) {
  return new Promise((res, rej) => {
    const fr = new FileReader();
    fr.onload = () => res(String(fr.result).split(',')[1] || '');
    fr.onerror = () => rej(new Error('Lecture du fichier impossible.'));
    fr.readAsDataURL(blob);
  });
}

/* ---- Lecture d'une image : réduite et mise à l'endroit ---- */
async function loadImage(file) {
  let bmp;
  try { bmp = await createImageBitmap(file, {imageOrientation: 'from-image'}); }
  catch (e) {
    bmp = await new Promise((res, rej) => {
      const im = new Image();
      im.onload = () => res(im); im.onerror = rej;
      im.src = URL.createObjectURL(file);
    });
  }
  const w0 = bmp.width, h0 = bmp.height, max = 2000;
  const k = Math.min(1, max / Math.max(w0, h0));
  const c = document.createElement('canvas');
  c.width = Math.round(w0 * k); c.height = Math.round(h0 * k);
  c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
  return {kind: 'img', dataUrl: c.toDataURL('image/jpeg', 0.82), w: c.width, h: c.height};
}

async function addFiles(files) {
  for (const f of files) {
    if (f.type === 'application/pdf') {
      if (pages.length) { toast('Un PDF se range seul : envoie d\'abord ce qui est déjà ajouté.', true); continue; }
      pages.push({kind: 'pdf', file: f});
    } else if (f.type.startsWith('image/')) {
      if (pages.some(p => p.kind === 'pdf')) { toast('Un PDF se range seul : envoie-le d\'abord.', true); continue; }
      try { pages.push(await loadImage(f)); }
      catch (e) { toast('Impossible de lire cette photo.', true); }
    } else {
      toast('Seuls les photos et les PDF sont acceptés.', true);
    }
  }
  render();
}

function render() {
  const box = $('thumbs'); box.innerHTML = '';
  pages.forEach((p, i) => {
    const d = document.createElement('div'); d.className = 'thumb';
    if (p.kind === 'img') { const im = new Image(); im.src = p.dataUrl; d.appendChild(im); }
    else d.textContent = 'PDF';
    const x = document.createElement('b'); x.textContent = '×';
    x.onclick = () => { pages.splice(i, 1); render(); };
    d.appendChild(x); box.appendChild(d);
  });
  const has = pages.length > 0;
  document.querySelectorAll('#cats button, #btnLater').forEach(b => b.disabled = !has || busy);
  $('btnShoot').textContent = has && pages[0].kind === 'img' ? 'Page suivante' : 'Photographier';
  $('hint').textContent = has
    ? (pages[0].kind === 'pdf' ? 'PDF prêt. Choisis où le ranger.' : pages.length + ' page' + (pages.length > 1 ? 's' : '') + '. Ajoute-en une autre, ou choisis où ranger.')
    : 'Photographie chaque page, l\'une après l\'autre : elles seront réunies en un seul PDF.';
}

/* ---- Fabrication du PDF ---- */
async function buildFile() {
  if (pages[0].kind === 'pdf') return pages[0].file;
  const {jsPDF} = window.jspdf;
  const doc = new jsPDF({unit: 'mm', format: 'a4', compress: true});
  pages.forEach((p, i) => {
    const land = p.w > p.h;
    if (i > 0) doc.addPage('a4', land ? 'l' : 'p');
    else if (land) { doc.deletePage(1); doc.addPage('a4', 'l'); }
    const W = doc.internal.pageSize.getWidth() - 10, H = doc.internal.pageSize.getHeight() - 10;
    const k = Math.min(W / p.w, H / p.h);
    const w = p.w * k, h = p.h * k;
    doc.addImage(p.dataUrl, 'JPEG', (doc.internal.pageSize.getWidth() - w) / 2, (doc.internal.pageSize.getHeight() - h) / 2, w, h);
  });
  return new File([doc.output('blob')], 'scan.pdf', {type: 'application/pdf'});
}

async function send(category) {
  if (busy || !pages.length) return;
  busy = true; render();
  toast('Envoi en cours…');
  try {
    const file = await buildFile();
    const r = await api('upload', await toBase64(file), {category, label: $('label').value, date: $('date').value});
    pages.length = 0; $('label').value = '';
    toast('Rangé dans : ' + r.where);
    loadInbox();
  } catch (e) {
    toast(e.message || 'L\'envoi a échoué. Réessaie.', true);
  }
  busy = false; render();
}

/* ---- À classer ---- */
async function loadInbox() {
  try {
    const r = await api('list');
    const box = $('inbox'); box.innerHTML = '';
    $('inboxCard').hidden = r.files.length === 0;
    $('inboxCount').textContent = r.files.length ? '(' + r.files.length + ')' : '';
    r.files.forEach(f => {
      const row = document.createElement('div'); row.className = 'item';
      const nm = document.createElement('div'); nm.className = 'nm'; nm.textContent = f.name;
      const a = document.createElement('a'); a.textContent = 'Voir'; a.target = '_blank'; a.rel = 'noopener'; a.href = f.webViewLink || '#';
      const mv = document.createElement('div'); mv.className = 'mv';
      const sel = document.createElement('select');
      sel.innerHTML = '<option value="">Ranger dans…</option>' + Object.entries(CATS).map(([k, v]) => '<option value="' + k + '">' + v + '</option>').join('');
      const ok = document.createElement('button'); ok.className = 'btn-main'; ok.textContent = 'Ranger';
      ok.onclick = async () => {
        if (!sel.value) return toast('Choisis une catégorie.', true);
        ok.disabled = true;
        const fd = new FormData(); fd.append('id', f.id); fd.append('category', sel.value);
        try { const r = await api('move', fd); toast('Rangé dans : ' + r.where); loadInbox(); }
        catch (e) { toast(e.message, true); ok.disabled = false; }
      };
      mv.append(sel, ok);
      const top = document.createElement('div'); top.className = 'top'; top.append(nm, a);
      row.append(top, mv); box.appendChild(row);
    });
  } catch (e) { /* l'étape "à classer" est secondaire : on n'embête pas avec une erreur */ }
}

$('btnShoot').onclick = () => $('inShoot').click();
$('btnPick').onclick = () => $('inPick').click();
$('inShoot').onchange = async (e) => { await addFiles([...e.target.files]); e.target.value = ''; };
$('inPick').onchange = async (e) => { await addFiles([...e.target.files]); e.target.value = ''; };
document.querySelectorAll('#cats button').forEach(b => b.onclick = () => send(b.dataset.cat));
$('btnLater').onclick = () => send('inbox');

render();
loadInbox();
</script>
<?php endif; ?>
</main>
</body>
</html>
