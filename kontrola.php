<?php
/**
 * THE MOVE :: jednorázová kontrola hostingu.
 *
 * Otevřete po nahrání na https://themove.cz/kontrola.php
 * Ověří, že hosting umí vše, co web potřebuje.
 *
 * !! PO KONTROLE TENTO SOUBOR SMAŽTE !!
 */

$testy = [];

// 1) verze PHP
$phpOk = version_compare(PHP_VERSION, '7.4', '>=');
$testy[] = [
    'nazev' => 'Verze PHP',
    'ok'    => $phpOk,
    'stav'  => PHP_VERSION,
    'rada'  => 'Potřeba alespoň PHP 7.4. Verzi lze přepnout v administraci Wedosu u dané domény.',
];

// 2) databáze SQLite
$sqliteOk = extension_loaded('pdo_sqlite');
$testy[] = [
    'nazev' => 'Databáze SQLite (pdo_sqlite)',
    'ok'    => $sqliteOk,
    'stav'  => $sqliteOk ? 'dostupná' : 'CHYBÍ',
    'rada'  => 'Bez tohoto rozšíření nefungují termíny ani rezervace. Zapíná se v nastavení PHP.',
];

// 3) zápis do složky data
$dir = __DIR__ . '/data';
$zapisOk = false;
$zapisStav = 'složka data neexistuje';
if (is_dir($dir)) {
    $test = $dir . '/_zapis_test.tmp';
    $zapisOk = @file_put_contents($test, 'test') !== false;
    if ($zapisOk) { @unlink($test); }
    $zapisStav = $zapisOk ? 'lze zapisovat' : 'NELZE zapisovat';
} elseif (@mkdir($dir, 0775, true)) {
    $zapisOk = true;
    $zapisStav = 'složka data vytvořena';
}
$testy[] = [
    'nazev' => 'Zápis do složky /data',
    'ok'    => $zapisOk,
    'stav'  => $zapisStav,
    'rada'  => 'Sem se ukládá databáze rezervací. Nastavte složce práva 755 nebo 775.',
];

// 4) databáze není veřejně přístupná
$dbUrl = null;
$dbChranena = null;
if (isset($_SERVER['HTTP_HOST'])) {
    $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $zaklad = $schema . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $dbUrl = $zaklad . '/data/themove.sqlite';
}
$testy[] = [
    'nazev' => 'Ochrana databáze',
    'ok'    => null,
    'stav'  => 'ověřte ručně',
    'rada'  => $dbUrl
        ? 'Otevřete ' . htmlspecialchars($dbUrl, ENT_QUOTES) . ' :: musí se zobrazit chyba 403 nebo 404, NIKOLI stažení souboru.'
        : 'Zkuste otevřít /data/themove.sqlite v prohlížeči, musí to skončit chybou.',
];

// 5) odesílání e-mailů
$mailOk = function_exists('mail');
$testy[] = [
    'nazev' => 'Odesílání e-mailů',
    'ok'    => $mailOk,
    'stav'  => $mailOk ? 'funkce mail() dostupná' : 'funkce mail() zakázána',
    'rada'  => 'Používá se jen pro upozornění na novou rezervaci. Web funguje i bez toho.',
];

// 6) přepis adres (kvůli přesměrování na HTTPS)
$rewriteOk = !function_exists('apache_get_modules')
    || in_array('mod_rewrite', apache_get_modules(), true);
$testy[] = [
    'nazev' => 'Přesměrování na HTTPS (mod_rewrite)',
    'ok'    => $rewriteOk,
    'stav'  => $rewriteOk ? 'k dispozici' : 'nedostupné',
    'rada'  => 'Zajišťuje přesměrování z http na https a z www na themove.cz.',
];

$chyby = 0;
foreach ($testy as $t) { if ($t['ok'] === false) { $chyby++; } }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Kontrola hostingu :: The Move</title>
<style>
  body { font-family: ui-monospace, Menlo, Consolas, monospace; background:#fafafa; color:#111;
         margin:0; padding:2.5rem 1.25rem; line-height:1.6; }
  .wrap { max-width:48rem; margin:0 auto; }
  h1 { font-size:1.5rem; margin:0 0 .5rem; }
  .souhrn { padding:1rem 1.25rem; margin:1.5rem 0; border-left:4px solid; background:#fff; }
  .souhrn.dobre { border-color:#2e9e4f; }
  .souhrn.spatne { border-color:#d0342c; }
  .test { background:#fff; border:1px solid #e6e6e6; padding:1rem 1.25rem; margin-bottom:.75rem; }
  .test h2 { font-size:1rem; margin:0 0 .35rem; display:flex; gap:.6rem; align-items:baseline; }
  .znak { font-weight:700; }
  .ok .znak { color:#2e9e4f; }
  .chyba .znak { color:#d0342c; }
  .rucne .znak { color:#f2af0e; }
  .stav { color:#555; font-size:.875rem; }
  .rada { color:#777; font-size:.8125rem; margin-top:.35rem; }
  .smazat { margin-top:2rem; padding:1rem 1.25rem; background:#111; color:#fff; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Kontrola hostingu</h1>
  <p class="stav">Ověřuje, že hosting zvládne vše, co web The Move potřebuje.</p>

  <div class="souhrn <?= $chyby === 0 ? 'dobre' : 'spatne' ?>">
    <?php if ($chyby === 0): ?>
      <strong>Vše v pořádku.</strong> Hosting je připravený. Zbývá jen ověřit ochranu
      databáze (viz níže) a pak tento soubor smazat.
    <?php else: ?>
      <strong>Nalezeno problémů: <?= $chyby ?></strong>. Podrobnosti níže.
    <?php endif; ?>
  </div>

  <?php foreach ($testy as $t):
      $trida = $t['ok'] === true ? 'ok' : ($t['ok'] === false ? 'chyba' : 'rucne');
      $znak  = $t['ok'] === true ? '✓' : ($t['ok'] === false ? '✕' : '!');
  ?>
    <div class="test <?= $trida ?>">
      <h2><span class="znak"><?= $znak ?></span> <?= htmlspecialchars($t['nazev'], ENT_QUOTES) ?></h2>
      <div class="stav"><?= htmlspecialchars($t['stav'], ENT_QUOTES) ?></div>
      <div class="rada"><?= $t['rada'] ?></div>
    </div>
  <?php endforeach; ?>

  <div class="smazat">
    Až bude vše v pořádku, <strong>smažte soubor kontrola.php</strong> z hostingu.
  </div>
</div>
</body>
</html>
