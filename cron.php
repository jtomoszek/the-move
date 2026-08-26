<?php
/**
 * THE MOVE :: rozeslání souhrnů o nových termínech.
 *
 * Souhrny se rozesílají i samy při běžné návštěvě webu. Tenhle soubor je pro
 * případ, že by na web dlouho nikdo nepřišel — dá se pověsit na cron u Wedosu:
 *
 *   /usr/bin/php /www/.../cron.php
 *
 * Nic citlivého nevypisuje a spustit se dá opakovaně: odešle jen to, čemu už
 * uplynula čekací doba.
 */

declare(strict_types=1);

require __DIR__ . '/inc/rezervace.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pocet = odesli_cekajici_souhrny(db());
    echo 'Odesláno souhrnů: ' . $pocet . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Rozeslání se nezdařilo.\n";
}
