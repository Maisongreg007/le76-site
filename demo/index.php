<?php
// Page d'accueil de la démo (données fictives)
declare(strict_types=1);
define('DEMO', true);
require __DIR__ . '/../personnel/lib.php';
$emps = employes();
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Démo · Gestion du restaurant</title>
<link rel="icon" href="../personnel/assets/icons/admin-32.png?v=2">
<link rel="stylesheet" href="../personnel/assets/style.css?v=<?= filemtime(__DIR__ . '/../personnel/assets/style.css') ?>">
<style>
.hero{padding:40px 0 10px}.hero h1{font-size:2.4rem;margin-bottom:.3rem}.hero p{font-size:1.1rem;color:var(--muted);max-width:640px}
.apps{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin:24px 0}
.app{display:flex;gap:14px;align-items:flex-start;background:var(--card);border-radius:18px;padding:18px;color:var(--ink);text-decoration:none;transition:transform .15s}
.app:hover{transform:translateY(-2px);opacity:1}
.app img{width:58px;height:58px;border-radius:14px;flex:none}
.app b{display:block;font-size:1.1rem;margin-bottom:3px}.app span{color:var(--muted);font-size:.9rem;line-height:1.4}
.codes{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px}
.codes a{display:flex;justify-content:space-between;background:var(--card2);border-radius:10px;padding:8px 12px;color:var(--ink);text-decoration:none;font-size:.92rem}
ol li{margin-bottom:6px}
</style></head><body>
<div class="demo-bar"><span><b>Démo</b> · données fictives, tout est cliquable, rien n'est réel</span></div>
<main class="wrap" style="max-width:960px">
<div class="hero"><h1>Gestion du restaurant</h1><p>Outil maison d'un restaurant (Le 76) et d'un restaurant de camping : planning, pointeuse, heures, coûts, congés et registre hygiène (HACCP). Version de démonstration avec une équipe fictive.</p></div>

<div class="apps">
  <a class="app" href="personnel/admin.php"><img src="../personnel/assets/icons/admin-192.png?v=2" alt=""><span><b>Personnel · admin</b>Planning, heures (convenu / prévu / effectué), coûts jour·semaine·mois, équipe, congés, réglages.</span></a>
  <a class="app" href="personnel/pointeuse.php?site=le76"><img src="../personnel/assets/icons/pointeuse-le76-192.png?v=2" alt=""><span><b>Pointeuse · Le 76</b>Écran iPad : code à 4 chiffres pour l'arrivée et le départ. Les codes sont affichés à droite.</span></a>
  <a class="app" href="personnel/pointeuse.php?site=camping"><img src="../personnel/assets/icons/pointeuse-camping-192.png?v=2" alt=""><span><b>Pointeuse · Camping</b>Même chose pour le second établissement.</span></a>
  <a class="app" href="hygiene/"><img src="/hygiene/icone-192.png" alt=""><span><b>Registre hygiène</b>Températures, ménage, réceptions, étiquettes DLC, huiles, refroidissement, documents.</span></a>
</div>

<div class="card"><h3>Espace employé (lien perso, sans mot de passe)</h3><p class="muted small" style="margin-top:0">Chaque employé reçoit un lien : ses horaires, ses jours « pas dispo », ses demandes de congé.</p>
<div class="codes">
<?php foreach ($emps as $e): ?><a href="personnel/mon-planning.php?t=<?= h($e['token']) ?>"><span><?= h(emp_nom($e)) ?></span><span class="muted">code <?= h($e['pin']) ?></span></a><?php endforeach; ?>
</div></div>

<div class="card"><h3>Idées de parcours (5 minutes)</h3><ol class="small" style="padding-left:20px;line-height:1.5">
<li><b>Pointeuse Le 76</b> : tape le code d'une personne prévue aujourd'hui, puis regarde <b>Admin → Aujourd'hui</b> se mettre à jour (≈ 10 s).</li>
<li><b>Planning</b> : touche un horaire passé → « Ce qui s'est passé » (oubli de pointage, malade, retard justifié…). Essaie le copier-coller et « Horaires habituels ».</li>
<li><b>Heures</b> : comparaison convenu / prévu / effectué, détail par personne, clôture du mois, export.</li>
<li><b>Coûts</b> : jour par jour, par semaine, par mois, extras compris.</li>
<li><b>Espace employé</b> : demande un congé avec Emma, puis accepte-la dans Aujourd'hui.</li>
<li><b>Registre hygiène</b> : relève une température hors plage, coche le ménage, imprime une étiquette.</li>
</ol>
<p class="muted small" style="margin-bottom:0">Le bouton « Réinitialiser » (bandeau violet) remet des données d'exemple propres. Les notifications et e-mails sont désactivés dans la démo.</p></div>
</main></body></html>
