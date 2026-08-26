<?php
/**
 * THE MOVE :: kalendářová položka rezervace (.ics).
 * Odkaz „Přidat do kalendáře" z potvrzovacího e-mailu.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/db.php';

$token = trim((string) ($_GET['k'] ?? ''));

if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Chybí odkaz na rezervaci.');
}

$s = db()->prepare(
    'SELECT r.id, r.jmeno, t.datum, t.cas_od, t.cas_do, t.misto, t.adresa, t.typ, t.poznamka
     FROM rezervace r JOIN terminy t ON t.id = r.termin_id
     WHERE r.token = :tok'
);
$s->execute([':tok' => $token]);
$r = $s->fetch();

if (!$r) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Rezervaci se nepodařilo najít.');
}

/** Escapování hodnot podle RFC 5545. */
function ics(string $text): string
{
    return str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $text);
}

$misto = trim((string) $r['misto']);
if (trim((string) $r['adresa']) !== '') {
    $misto .= ', ' . trim((string) $r['adresa']);
}

$zacatek = new DateTime($r['datum'] . ' ' . $r['cas_od'], new DateTimeZone('Europe/Prague'));
$konec   = new DateTime($r['datum'] . ' ' . $r['cas_do'], new DateTimeZone('Europe/Prague'));
$ted     = new DateTime('now', new DateTimeZone('UTC'));

$popis = 'Rezervace #R-' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT)
    . ' na jméno ' . $r['jmeno'] . '.'
    . (trim((string) $r['poznamka']) !== '' ? ' ' . $r['poznamka'] : '')
    . ' Přijďte prosím o deset minut dřív, v pohodlném oblečení.';

// Časy uvádíme v UTC (přípona Z), aby nebylo potřeba posílat definici pásma.
$zacatek->setTimezone(new DateTimeZone('UTC'));
$konec->setTimezone(new DateTimeZone('UTC'));

$radky = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//The Move//Rezervace//CS',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:rezervace-' . (int) $r['id'] . '@themove.cz',
    'DTSTAMP:' . $ted->format('Ymd\THis\Z'),
    'DTSTART:' . $zacatek->format('Ymd\THis\Z'),
    'DTEND:' . $konec->format('Ymd\THis\Z'),
    'SUMMARY:' . ics(nazev_typu($r['typ']) . ' · The Move'),
    'LOCATION:' . ics($misto),
    'DESCRIPTION:' . ics($popis),
    'BEGIN:VALARM',
    'TRIGGER:-PT2H',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . ics(nazev_typu($r['typ']) . ' · The Move'),
    'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="the-move-lekce.ics"');
header('Cache-Control: no-store');

echo implode("\r\n", $radky) . "\r\n";
