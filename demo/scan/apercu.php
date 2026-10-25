<?php
// Démo : aperçu d'un document fictif
declare(strict_types=1);
header('X-Robots-Tag: noindex, nofollow');
$n = (string) ($_GET['n'] ?? 'Document.pdf');
$n = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '', $n) ?? '';
[$d, $f] = array_pad(explode('_', preg_replace('/\.\w+$/', '', $n), 2), 2, 'Document');
$f = str_replace('-', ' ', $f);
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?= $h($n) ?></title>
<style>body{font:15px/1.5 -apple-system,system-ui,sans-serif;background:#e5e5ea;margin:0;padding:20px}.p{max-width:560px;margin:0 auto;background:#fff;padding:32px;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.12);position:relative;overflow:hidden}
.w{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:56px;font-weight:800;color:rgba(90,79,207,.12);transform:rotate(-25deg);pointer-events:none}table{width:100%;border-collapse:collapse;margin:18px 0}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left}.r{text-align:right}</style></head><body>
<div class="p"><div class="w">DOCUMENT FICTIF</div><h2 style="margin:0"><?= $h($f) ?></h2><p style="color:#666;margin:2px 0 18px">Facture n° DEMO-<?= $h(substr(md5($n), 0, 6)) ?> · <?= $h($d) ?></p>
<p>Client : Restaurant (démo)</p><table><tr><th>Article</th><th class="r">Qté</th><th class="r">Montant</th></tr><tr><td>Marchandises</td><td class="r">1</td><td class="r">184,20 €</td></tr><tr><td>Livraison</td><td class="r">1</td><td class="r">12,00 €</td></tr><tr><th>Total TTC</th><th></th><th class="r">196,20 €</th></tr></table>
<p style="color:#666;font-size:13px">Exemple de démonstration. Dans le vrai outil, ce bouton ouvre le PDF dans Google Drive.</p></div></body></html>
