<?php
/**
 * Impression sur imprimante Epson (TM-m30II et compatibles) en « Server Direct Print ».
 *
 * Le site est hébergé sur Internet, les imprimantes sont sur le réseau local du restaurant :
 * le serveur ne peut pas les joindre. C'est donc l'imprimante qui vient chercher ses tickets,
 * toutes les quelques secondes, sur une adresse secrète du site (réglée une fois dans sa page
 * de configuration). Elle renvoie ensuite le résultat de chaque impression.
 *
 * Ce fichier ne fait que fabriquer et lire le XML Epson (ePOS-Print) ; la file d'attente des
 * tickets est gérée par chaque application (pain, camping…).
 */
declare(strict_types=1);

function epos_echap(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Texte imprimable sur n'importe quelle page de code : accents retirés (Léa → Lea, Müller → Muller),
 * € → EUR, guillemets et apostrophes simplifiés. Mieux vaut « Lea » que « L?a » sur un bon.
 */
function epos_texte(string $s): string
{
    $s = strtr($s, [
        '€' => 'EUR', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '«' => '"', '»' => '"',
        '·' => '-', '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ',
        'œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE', 'ß' => 'ss', 'ø' => 'o', 'Ø' => 'O', 'ł' => 'l', 'Ł' => 'L',
    ]);
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) {
            $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
        }
    } else {
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
}

/**
 * Corps ePOS-Print d'un ticket.
 * Ligne : ['text' => '…', 'align' => 'left'|'center'|'right', 'bold' => bool, 'width' => 1|2, 'height' => 1|2]
 * ou ['feed' => N] (N lignes vides) ou ['cut' => true] (coupe du papier).
 */
function epos_corps(array $lignes): string
{
    $xml = '';
    foreach ($lignes as $l) {
        if (!empty($l['cut'])) {
            $xml .= '<cut type="feed"/>';
            continue;
        }
        if (!empty($l['feed'])) {
            $xml .= '<feed line="' . max(1, min(10, (int) $l['feed'])) . '"/>';
            continue;
        }
        $align = in_array($l['align'] ?? '', ['left', 'center', 'right'], true) ? $l['align'] : 'left';
        $w = max(1, min(2, (int) ($l['width'] ?? 1)));
        $h = max(1, min(2, (int) ($l['height'] ?? 1)));
        // Chaque ligne remet tous les styles, pour qu'aucun gras ou grand format ne « déborde » sur la suivante.
        $xml .= '<text align="' . $align . '"/>'
            . '<text em="' . (!empty($l['bold']) ? 'true' : 'false') . '" width="' . $w . '" height="' . $h . '"/>'
            . '<text>' . epos_echap(epos_texte((string) ($l['text'] ?? ''))) . '&#10;</text>';
    }
    return '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">' . $xml . '</epos-print>';
}

/** Réponse à une demande de l'imprimante : les tickets à imprimer (vide s'il n'y en a pas). */
function sdp_travaux(array $travaux): string
{
    if (!$travaux) {
        return '';
    }
    $xml = '<?xml version="1.0" encoding="utf-8"?><PrintRequestInfo Version="2.00">';
    foreach ($travaux as $t) {
        $xml .= '<ePOSPrint><Parameter><devid>local_printer</devid><timeout>10000</timeout>'
            . '<printjobid>' . epos_echap((string) $t['job']) . '</printjobid></Parameter>'
            . '<PrintData>' . epos_corps($t['lignes']) . '</PrintData></ePOSPrint>';
    }
    return $xml . '</PrintRequestInfo>';
}

/** Résultats renvoyés par l'imprimante : [identifiant du ticket => [imprimé ?, code d'erreur]]. */
function sdp_resultats(string $xml): array
{
    $xml = trim($xml);
    if ($xml === '' || strlen($xml) > 200000) {
        return [];
    }
    $doc = new DOMDocument();
    if (!@$doc->loadXML($xml, LIBXML_NONET)) {
        return [];
    }
    $out = [];
    foreach ($doc->getElementsByTagNameNS('*', 'ePOSPrint') as $e) {
        $id = $e->getElementsByTagNameNS('*', 'printjobid')->item(0);
        $rep = $e->getElementsByTagNameNS('*', 'response')->item(0);
        if ($id && $rep instanceof DOMElement) {
            $out[trim($id->textContent)] = [$rep->getAttribute('success') === 'true', $rep->getAttribute('code')];
        }
    }
    return $out;
}

/** Ticket de test. */
function epos_ticket_test(string $titre): array
{
    return [
        ['text' => 'TEST', 'align' => 'center', 'bold' => true, 'width' => 2, 'height' => 2],
        ['text' => $titre, 'align' => 'center'],
        ['text' => 'L’imprimante est bien reliée au site.', 'align' => 'center'],
        ['text' => date('d/m/Y H:i'), 'align' => 'center'],
        ['feed' => 3],
        ['cut' => true],
    ];
}
