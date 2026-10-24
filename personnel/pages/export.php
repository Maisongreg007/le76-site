<?php
declare(strict_types=1);

$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
$etabF = valid_etab($_GET['etab'] ?? null) ? (string)$_GET['etab'] : null;
$type = ($_GET['t'] ?? 'resume') === 'detail' ? 'detail' : 'resume';

$nomFichier = 'heures-' . $ym . ($etabF ? '-' . $etabF : '') . '-' . $type . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 pour Excel
$num = static fn(float $x) => number_format($x, 2, ',', '');
$put = static function (array $row) use ($out): void {
    // Neutralise les formules Excel
    $row = array_map(static fn($c) => is_string($c) && preg_match('/^[=+\-@]/', $c) ? "'" . $c : $c, $row);
    fputcsv($out, $row, ';', '"', '\\');
};

if ($type === 'resume') {
    $put(['Nom', 'Poste', 'Contrat', 'Restaurant(s)', 'Heures', 'Heures (décimal)', 'Taux horaire net', 'À payer (net)', 'Remarque']);
    foreach (rapport_mois($ym, $etabF) as $r) {
        $e = $r['e'];
        $put([
            emp_nom($e),
            implode(' + ', array_map(fn($p) => POSTES[$p], emp_postes($e))),
            $e['type'] === 'fixe' ? 'Fixe' : 'Extra',
            implode(' + ', array_map(fn($k) => ETABS[$k], emp_etabs($e))),
            fmt_min($r['minutes']),
            $num($r['minutes'] / 60),
            $e['type'] === 'extra' ? $num((float)$e['taux']) : '',
            $num($r['cout']),
            trim($r['note'] . ($r['ouverts'] ? ' ' . $r['ouverts'] . ' pointage(s) sans départ' : '')),
        ]);
    }
} else {
    $a = $ym . '-01 00:00:00';
    $b = date('Y-m-d H:i:s', strtotime($a . ' +1 month'));
    $sql = 'SELECT p.*, e.prenom, e.nom, e.postes FROM pointages p JOIN employes e ON e.id = p.employe_id WHERE p.debut >= ? AND p.debut < ?'
        . ($etabF ? ' AND p.etab = ?' : '') . ' ORDER BY e.prenom COLLATE NOCASE, e.nom COLLATE NOCASE, p.debut';
    $st = db()->prepare($sql);
    $st->execute($etabF ? [$a, $b, $etabF] : [$a, $b]);
    $put(['Nom', 'Poste', 'Restaurant', 'Date', 'Arrivée', 'Départ', 'Durée', 'Durée (décimal)', 'Modifié à la main']);
    foreach ($st->fetchAll() as $p) {
        $m = $p['fin'] ? minutes_between($p['debut'], $p['fin']) : null;
        $put([
            trim($p['prenom'] . ' ' . $p['nom']),
            implode(' + ', array_map(fn($x) => POSTES[$x], emp_postes($p))),
            ETABS[$p['etab']],
            date('d/m/Y', strtotime($p['debut'])),
            date('H:i', strtotime($p['debut'])),
            $p['fin'] ? date('H:i', strtotime($p['fin'])) : '',
            $m !== null ? fmt_min($m) : 'sans départ',
            $m !== null ? $num($m / 60) : '',
            $p['manuel'] ? 'oui' : '',
        ]);
    }
}
fclose($out);
exit;
