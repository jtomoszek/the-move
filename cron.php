<?php
/**
 * THE MOVE :: rozeslání čekajících e-mailů.
 *
 *   1) oznámení o nově vypsaných termínech (hodinu po posledním přidaném)
 *   2) připomínky den před akcí (po 11:05)
 *
 * Oboje se rozesílá i samo při běžné návštěvě webu. Tenhle soubor je pojistka
 * pro případ, že by na web dlouho nikdo nepřišel — u Wedosu se dá pověsit na
 * cron (ideálně denně v 11:05, klidně i častěji):
 *
 *   /usr/bin/php /www/.../cron.php
 *
 * Nic citlivého nevypisuje a spustit se dá opakovaně: každý e-mail se odešle
 * jen jednou.
 */

declare(strict_types=1);

require __DIR__ . '/inc/rezervace.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    $souhrny = odesli_cekajici_souhrny(db());
    $pripominky = odesli_pripominky(db());
    echo 'Odesláno oznámení o nových termínech: ' . $souhrny . "\n";
    echo 'Odesláno připomínek: ' . $pripominky . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Rozeslání se nezdařilo.\n";
}
