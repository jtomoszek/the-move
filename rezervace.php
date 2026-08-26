<?php
/**
 * THE MOVE :: stránka pro odkazy z potvrzovacího e-mailu.
 *
 *   ?k=TOKEN  … konkrétní rezervace (zrušení, přihlášení na pravidelné lekce)
 *   ?p=TOKEN  … trvalá přihláška (zrušení pravidelné docházky)
 *
 * Odkaz z e-mailu jen zobrazí stránku, samotná akce se provede až potvrzením
 * tlačítkem (POST) — antivirové skenery e-mailů odkazy otevírají samy.
 */

declare(strict_types=1);

require __DIR__ . '/inc/rezervace.php';

$pdo = db();
$stav = '';      // hlaska po provedené akci
$chyba = '';
$rezervace = null;
$termin = null;
$prihlaska = null;
$seznam = [];

$token  = trim((string) ($_POST['token'] ?? $_GET['k'] ?? ''));
$tokenP = trim((string) ($_POST['token_p'] ?? $_GET['p'] ?? ''));
$akce   = (string) ($_POST['akce'] ?? $_GET['akce'] ?? '');
$jePost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($token !== '') {
    $s = $pdo->prepare(
        'SELECT r.*, t.datum, t.cas_od, t.cas_do, t.misto, t.adresa, t.typ, t.poznamka
         FROM rezervace r JOIN terminy t ON t.id = r.termin_id
         WHERE r.token = :tok'
    );
    $s->execute([':tok' => $token]);
    $rezervace = $s->fetch() ?: null;

    if ($rezervace) {
        $termin = [
            'datum' => $rezervace['datum'], 'cas_od' => $rezervace['cas_od'],
            'cas_do' => $rezervace['cas_do'], 'misto' => $rezervace['misto'],
            'adresa' => $rezervace['adresa'], 'typ' => $rezervace['typ'],
            'poznamka' => $rezervace['poznamka'],
        ];
    }
} elseif ($tokenP !== '') {
    $s = $pdo->prepare('SELECT * FROM trvale_prihlasky WHERE token = :tok');
    $s->execute([':tok' => $tokenP]);
    $prihlaska = $s->fetch() ?: null;
}

if ($jePost && $akce === 'zrusit' && $rezervace) {
    $d = $pdo->prepare('DELETE FROM rezervace WHERE id = :id');
    $d->execute([':id' => $rezervace['id']]);

    // Ať člověka trvalá přihláška nepřihlásí na stejnou lekci znovu.
    $vypnout = $pdo->prepare('UPDATE trvale_prihlasky SET aktivni = 0 WHERE email = :e COLLATE NOCASE AND aktivni = 1');
    $stav = 'zruseno';
    if (overeny_typ($rezervace['typ']) === 'lekce' && !empty($_POST['zrusit_trvalou'])) {
        $vypnout->execute([':e' => $rezervace['email']]);
        $stav = 'zruseno_vse';
    }
    $rezervace['zruseno'] = true;
} elseif ($jePost && $akce === 'pravidelne' && $rezervace && overeny_typ($rezervace['typ']) === 'lekce') {
    $vysledek = prihlas_natrvalo($pdo, $rezervace['jmeno'], $rezervace['email'], (string) $rezervace['telefon']);
    email_pravidelne($vysledek['prihlaska'], $vysledek['pridane'], (int) $vysledek['plne']);
    $seznam = $vysledek['pridane'];
    $stav = 'pravidelne';
} elseif ($jePost && $akce === 'zrusit_trvalou' && $prihlaska) {
    $u = $pdo->prepare('UPDATE trvale_prihlasky SET aktivni = 0 WHERE id = :id');
    $u->execute([':id' => $prihlaska['id']]);
    $stav = 'trvala_zrusena';

    if (!empty($_POST['zrusit_rezervace'])) {
        $d = $pdo->prepare(
            "DELETE FROM rezervace WHERE email = :e COLLATE NOCASE AND zdroj = 'trvala'
             AND termin_id IN (SELECT id FROM terminy WHERE datum >= :dnes)"
        );
        $d->execute([':e' => $prihlaska['email'], ':dnes' => date('Y-m-d')]);
        $stav = 'trvala_zrusena_vse';
    }
}

if ($stav === '' && $token === '' && $tokenP === '') {
    $chyba = 'Odkaz je neúplný. Otevřete ho prosím znovu z e-mailu s potvrzením.';
} elseif ($stav === '' && !$rezervace && !$prihlaska) {
    $chyba = 'Tuto rezervaci se nepodařilo najít — možná už byla zrušena.';
}

/** Termín čitelně na jeden řádek. */
function radek_terminu(array $t): string
{
    return cesky_den($t['datum']) . ' ' . ceske_datum($t['datum'])
        . ' · ' . $t['cas_od'] . ' do ' . $t['cas_do'] . ' · ' . $t['misto'];
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$jeLekce = $rezervace && overeny_typ($rezervace['typ']) === 'lekce';
$minula  = $rezervace && $rezervace['datum'] < date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Vaše rezervace · The Move</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=general-sans@400,500&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <style>
    .rez-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 4rem 1.5rem; background: var(--paper-soft); }
    .rez-card { width: 100%; max-width: 40rem; background: var(--paper); border: 1px solid var(--line); padding: 3rem; }
    .rez-card .logo { height: 22px; width: auto; margin-bottom: 2.5rem; }
    .rez-card h1 { font-family: var(--font-head); font-size: 2.25rem; line-height: 110%; letter-spacing: -1px; margin-bottom: 1rem; }
    .rez-radky { margin: 2rem 0; border-top: 2px solid var(--ink); }
    .rez-radek { display: grid; grid-template-columns: 7rem 1fr; gap: 1rem; padding: 0.9rem 0; border-bottom: 1px solid var(--line); font-size: 0.9375rem; }
    .rez-radek dt { color: var(--grey); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .rez-akce { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 2rem; }
    .rez-volba { display: flex; gap: 0.6rem; align-items: flex-start; margin-top: 1.5rem; font-size: 0.875rem; color: var(--grey); }
    .rez-seznam { list-style: none; margin: 1.5rem 0 0; padding: 0; }
    .rez-seznam li { padding: 0.7rem 0; border-bottom: 1px solid var(--line); font-size: 0.9375rem; }
    .rez-pata { margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--line); font-size: 0.8125rem; color: var(--grey); }
    .rez-cara { width: 2.75rem; height: 3px; background: var(--yellow); margin-bottom: 1.5rem; }
    @media screen and (max-width: 560px) {
      .rez-card { padding: 2rem 1.5rem; }
      .rez-radek { grid-template-columns: 1fr; gap: 0.2rem; }
    }
  </style>
</head>
<body>
<main class="rez-page">
  <div class="rez-card">
    <a href="index.html"><img class="logo" src="assets/img/logo.webp" alt="The Move" width="600" height="108"></a>
    <div class="rez-cara"></div>

    <?php if ($chyba !== ''): ?>
      <h1>Odkaz nefunguje.</h1>
      <p class="text-grey"><?= h($chyba) ?></p>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Zobrazit termíny</a></div>

    <?php elseif ($stav === 'zruseno' || $stav === 'zruseno_vse'): ?>
      <h1>Rezervace je zrušená.</h1>
      <p class="text-grey">Místo jsme uvolnili někomu dalšímu. Díky, že jste dali vědět.</p>
      <?php if ($stav === 'zruseno_vse'): ?>
        <p class="text-grey">Zároveň jsme vypnuli pravidelnou docházku — na další lekce vás už automaticky přihlašovat nebudeme.</p>
      <?php endif; ?>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Vybrat jiný termín</a></div>

    <?php elseif ($stav === 'pravidelne'): ?>
      <h1>Chodíte pravidelně.</h1>
      <p class="text-grey">Přihlásili jsme vás na všechny vypsané skupinové lekce a každou další,
        kterou vypíšeme, vám přidáme automaticky. Potvrzení máte v e-mailu.</p>
      <?php if ($seznam): ?>
        <ul class="rez-seznam">
          <?php foreach ($seznam as $t): ?><li><?= h(radek_terminu($t)) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Zobrazit termíny</a></div>

    <?php elseif ($stav === 'trvala_zrusena' || $stav === 'trvala_zrusena_vse'): ?>
      <h1>Pravidelná docházka je zrušená.</h1>
      <p class="text-grey">Na nové lekce vás už automaticky přihlašovat nebudeme.</p>
      <?php if ($stav === 'trvala_zrusena_vse'): ?>
        <p class="text-grey">Zrušili jsme i vaše budoucí rezervace, které z pravidelné docházky vznikly.</p>
      <?php else: ?>
        <p class="text-grey">Rezervace, které už máte, platí dál — zrušit je můžete odkazem v potvrzovacím e-mailu.</p>
      <?php endif; ?>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Zobrazit termíny</a></div>

    <?php elseif ($prihlaska): ?>
      <h1>Pravidelná docházka</h1>
      <?php if ((int) $prihlaska['aktivni'] === 0): ?>
        <p class="text-grey">Pravidelnou docházku už máte vypnutou. Na nové lekce se můžete
          kdykoli přihlásit přes web.</p>
        <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Zobrazit termíny</a></div>
      <?php else: ?>
        <p class="text-grey">Na každou nově vypsanou skupinovou lekci vás přihlašujeme automaticky.
          Tady to můžete vypnout.</p>
        <form method="post">
          <input type="hidden" name="token_p" value="<?= h($prihlaska['token']) ?>">
          <input type="hidden" name="akce" value="zrusit_trvalou">
          <label class="rez-volba">
            <input type="checkbox" name="zrusit_rezervace" value="1">
            <span>Zrušit i budoucí rezervace, které z pravidelné docházky vznikly.</span>
          </label>
          <div class="rez-akce">
            <button class="button button--solid" type="submit">Zrušit pravidelnou docházku</button>
            <a class="button" href="index.html">Nechat být</a>
          </div>
        </form>
      <?php endif; ?>

    <?php elseif ($rezervace): ?>
      <?php if ($akce === 'zrusit' && !$minula): ?>
        <h1>Zrušit rezervaci?</h1>
        <p class="text-grey">Místo se hned uvolní někomu dalšímu.</p>
      <?php elseif ($akce === 'pravidelne' && $jeLekce && !$minula): ?>
        <h1>Chodit pravidelně?</h1>
        <p class="text-grey">Přihlásíme vás na všechny vypsané skupinové lekce a každou další,
          kterou vypíšeme, vám přidáme automaticky. Kdykoli to můžete zrušit.</p>
      <?php else: ?>
        <h1>Vaše rezervace</h1>
        <p class="text-grey"><?= $minula ? 'Tento termín už proběhl.' : 'Těšíme se na vás.' ?></p>
      <?php endif; ?>

      <dl class="rez-radky">
        <div class="rez-radek"><dt>Akce</dt><dd><?= h(nazev_typu($rezervace['typ'])) ?></dd></div>
        <div class="rez-radek"><dt>Kdy</dt><dd><?= h(radek_terminu($termin)) ?></dd></div>
        <?php if (trim((string) $rezervace['adresa']) !== ''): ?>
          <div class="rez-radek"><dt>Adresa</dt><dd><?= h($rezervace['adresa']) ?></dd></div>
        <?php endif; ?>
        <?php if (trim((string) $rezervace['poznamka']) !== ''): ?>
          <div class="rez-radek"><dt>Poznámka</dt><dd><?= h($rezervace['poznamka']) ?></dd></div>
        <?php endif; ?>
        <div class="rez-radek"><dt>Na jméno</dt><dd><?= h($rezervace['jmeno']) ?></dd></div>
        <div class="rez-radek"><dt>Rezervace</dt><dd>#R-<?= h(str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT)) ?></dd></div>
      </dl>

      <?php if ($minula): ?>
        <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Vybrat další termín</a></div>

      <?php elseif ($akce === 'pravidelne' && $jeLekce): ?>
        <form method="post">
          <input type="hidden" name="token" value="<?= h($rezervace['token']) ?>">
          <input type="hidden" name="akce" value="pravidelne">
          <div class="rez-akce">
            <button class="button button--solid" type="submit">Ano, chci chodit pravidelně</button>
            <a class="button" href="rezervace.php?k=<?= urlencode($rezervace['token']) ?>">Zpět</a>
          </div>
        </form>

      <?php elseif ($akce === 'zrusit'): ?>
        <form method="post">
          <input type="hidden" name="token" value="<?= h($rezervace['token']) ?>">
          <input type="hidden" name="akce" value="zrusit">
          <?php if ($jeLekce): ?>
            <label class="rez-volba">
              <input type="checkbox" name="zrusit_trvalou" value="1">
              <span>Zrušit i pravidelnou docházku, pokud ji mám zapnutou.</span>
            </label>
          <?php endif; ?>
          <div class="rez-akce">
            <button class="button button--solid" type="submit">Zrušit rezervaci</button>
            <a class="button" href="rezervace.php?k=<?= urlencode($rezervace['token']) ?>">Nechat rezervaci</a>
          </div>
        </form>

      <?php else: ?>
        <div class="rez-akce">
          <a class="button button--solid" href="api/kalendar.php?k=<?= urlencode($rezervace['token']) ?>">Přidat do kalendáře</a>
          <?php if ($jeLekce): ?>
            <a class="button" href="rezervace.php?k=<?= urlencode($rezervace['token']) ?>&amp;akce=pravidelne">Chodit pravidelně</a>
          <?php endif; ?>
          <a class="button" href="rezervace.php?k=<?= urlencode($rezervace['token']) ?>&amp;akce=zrusit">Zrušit rezervaci</a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <p class="rez-pata">
      Dotazy: <a href="mailto:info@themove.cz">info@themove.cz</a> ·
      <a href="tel:+420604819067">+420 604 819 067</a><br>
      The Move s.r.o. · <a href="ochrana-osobnich-udaju.html">Ochrana osobních údajů</a>
    </p>
  </div>
</main>
</body>
</html>
