<?php
/**
 * THE MOVE :: veřejný seznam termínů (JSON).
 * Vrací jen budoucí zveřejněné termíny bez osobních údajů.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/rezervace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Při návštěvě webu se cestou rozešlou oznámení o nových termínech (po
// uplynutí čekací doby) a připomínky den před akcí. Případná chyba nesmí
// shodit výpis termínů.
try {
    odesli_cekajici_souhrny(db());
    odesli_pripominky(db());
} catch (Throwable $e) {
    // ticho, e-maily se doručí při příštím načtení
}

try {
    $stmt = db()->prepare(
        "SELECT t.id, t.datum, t.cas_od, t.cas_do, t.misto, t.typ, t.kapacita, t.poznamka,
                (SELECT COUNT(*) FROM rezervace r WHERE r.termin_id = t.id) AS obsazeno
         FROM terminy t
         WHERE t.zverejnit = 1 AND t.datum >= :dnes
         ORDER BY t.datum, t.cas_od
         LIMIT 100"
    );
    $stmt->execute([':dnes' => date('Y-m-d')]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'id'       => (int) $row['id'],
            'den'      => cesky_den($row['datum']),
            'datum'    => ceske_datum($row['datum']),
            'cas_od'   => $row['cas_od'],
            'cas_do'   => $row['cas_do'],
            'misto'    => $row['misto'],
            'typ'      => overeny_typ($row['typ'] ?? null),
            'typ_nazev' => nazev_typu($row['typ'] ?? null),
            'poznamka' => $row['poznamka'],
            'kapacita' => (int) $row['kapacita'],
            'volno'    => max(0, (int) $row['kapacita'] - (int) $row['obsazeno']),
        ];
    }

    echo json_encode(['ok' => true, 'terminy' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'chyba' => 'Termíny se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
}
