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
$pridane = [];
$odebrane = [];
$plne = 0;

$token  = trim((string) ($_POST['token'] ?? $_GET['k'] ?? ''));
$tokenP = trim((string) ($_POST['token_p'] ?? $_GET['p'] ?? ''));
$akce   = (string) ($_POST['akce'] ?? $_GET['akce'] ?? '');
$jePost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($token !== '') {
    $s = $pdo->prepare(
        'SELECT r.*, t.datum, t.cas_od, t.cas_do, t.misto, t.adresa, t.typ, t.cena,
                t.poznamka_email AS poznamka
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
    $stav = 'zruseno';
    $rezervace['zruseno'] = true;
} elseif ($jePost && $akce === 'vybrat' && ($rezervace || $prihlaska)) {
    // Výběr lekcí zaškrtnutím: srovnáme rezervace podle zaškrtnutí
    // (nové přidáme, odškrtnuté zrušíme) a zapneme odběr novinek.
    $kdo = $rezervace ?: $prihlaska;
    $vybrane = array_map('intval', (array) ($_POST['terminy'] ?? []));
    $vysledek = uprav_vyber($pdo, (string) $kdo['jmeno'], (string) $kdo['email'],
                            (string) ($kdo['telefon'] ?? ''), $vybrane);
    $prihlaska = $vysledek['prihlaska'];
    if ($vysledek['pridane'] || $vysledek['odebrane']) {
        email_pravidelne($vysledek['prihlaska'], $vysledek['aktualni']);
    }
    $seznam = $vysledek['aktualni'];
    $odebrane = $vysledek['odebrane'];
    $pridane = $vysledek['pridane'];
    $plne = (int) $vysledek['plne'];
    $stav = 'vybrano';
}

if ($stav === '' && $token === '' && $tokenP === '') {
    $chyba = 'Odkaz je neúplný. Otevřete ho prosím znovu z e-mailu s potvrzením.';
} elseif ($stav === '' && !$rezervace && !$prihlaska) {
    $chyba = 'Tuto rezervaci se nepodařilo najít — možná už byla zrušena.';
}

// Nabídka lekcí k zaškrtnutí (pro „chodit pravidelně" a pro odběratele novinek).
$vyberLekci = [];
$zobrazitVyber = $stav === ''
    && (($rezervace && $akce === 'pravidelne' && overeny_typ($rezervace['typ']) === 'lekce')
        || $prihlaska);
if ($zobrazitVyber) {
    $email = (string) ($rezervace['email'] ?? $prihlaska['email']);
    $moje = [];
    $m = $pdo->prepare('SELECT termin_id FROM rezervace WHERE email = :e COLLATE NOCASE');
    $m->execute([':e' => $email]);
    foreach ($m->fetchAll() as $r) {
        $moje[(int) $r['termin_id']] = true;
    }
    foreach (budouci_lekce($pdo) as $l) {
        $l['mam'] = isset($moje[(int) $l['id']]);
        $l['plno'] = (int) $l['obsazeno'] >= (int) $l['kapacita'];
        $vyberLekci[] = $l;
    }
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

/** Seznam lekcí se zaškrtávacími poli (společný pro oba vstupy). */
function vypis_vyber_lekci(array $lekce): void
{
    if (!$lekce) {
        echo '<p class="text-grey" style="margin-top:1.5rem">Právě nejsou vypsané žádné skupinové lekce.'
            . ' Jakmile nové vypíšeme, dáme vám vědět e-mailem.</p>';
        return;
    }
    echo '<div class="rez-vyber">';
    foreach ($lekce as $l) {
        $volno = max(0, (int) $l['kapacita'] - (int) $l['obsazeno']);
        if ($l['mam']) {
            echo '<label class="rez-lekce rez-lekce--mam"><input type="checkbox" name="terminy[]" value="' . (int) $l['id'] . '" checked>'
                . '<span>' . h(radek_terminu($l))
                . '<span class="stav">Jste přihlášeni · odškrtnutím se z lekce odhlásíte</span></span></label>';
        } elseif ($l['plno']) {
            echo '<label class="rez-lekce rez-lekce--plno"><input type="checkbox" disabled>'
                . '<span>' . h(radek_terminu($l))
                . '<span class="stav">Obsazeno</span></span></label>';
        } else {
            $cena = trim((string) ($l['cena'] ?? ''));
            echo '<label class="rez-lekce"><input type="checkbox" name="terminy[]" value="' . (int) $l['id'] . '">'
                . '<span>' . h(radek_terminu($l))
                . '<span class="stav">Volno ' . $volno . ' z ' . (int) $l['kapacita']
                . ($cena !== '' ? ' · ' . h($cena) : '') . '</span></span></label>';
        }
    }
    echo '</div>';
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
  <?php // Otisk obsahu: po úpravě stylů si prohlížeč načte novou verzi. ?>
  <link rel="stylesheet" href="css/style.css?v=<?= substr(md5_file(__DIR__ . '/css/style.css') ?: '', 0, 8) ?>">
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
    .rez-vyber { margin: 2rem 0 0; border-top: 2px solid var(--ink); }
    .rez-lekce { display: flex; gap: 0.85rem; align-items: flex-start; padding: 0.9rem 0; border-bottom: 1px solid var(--line); font-size: 0.9375rem; cursor: pointer; }
    .rez-lekce input { margin-top: 0.3rem; width: 1.1rem; height: 1.1rem; accent-color: var(--yellow); flex: none; }
    .rez-lekce--mam input { accent-color: var(--ink); }
    .rez-lekce .stav { display: block; font-size: 0.8125rem; color: var(--grey); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.15rem; }
    .rez-lekce--plno { cursor: default; color: var(--grey); text-decoration: line-through; }
    .rez-lekce--plno .stav { text-decoration: none; }
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

    <?php elseif ($stav === 'zruseno'): ?>
      <h1>Rezervace je zrušená.</h1>
      <p class="text-grey">Místo jsme uvolnili někomu dalšímu. Díky, že jste dali vědět.</p>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Vybrat jiný termín</a></div>

    <?php elseif ($stav === 'vybrano'): ?>
      <h1>Výběr je uložený.</h1>
      <?php if ($pridane || $odebrane): ?>
        <p class="text-grey">
          <?php if ($pridane): ?>Přihlásili jsme vás na <?= count($pridane) ?>
            <?= count($pridane) === 1 ? 'lekci' : (count($pridane) < 5 ? 'lekce' : 'lekcí') ?>.<?php endif; ?>
          <?php if ($odebrane): ?> Odhlásili jsme vás z <?= count($odebrane) ?>
            <?= count($odebrane) === 1 ? 'lekce' : 'lekcí' ?>.<?php endif; ?>
          Souhrn máte v e-mailu.
        </p>
      <?php else: ?>
        <p class="text-grey">Nic se nezměnilo — výběr zůstává, jak byl.</p>
      <?php endif; ?>
      <?php if ($plne > 0): ?>
        <p class="text-grey"><?= $plne === 1 ? 'Jedna z vybraných lekcí už byla mezitím plná.'
            : 'Některé z vybraných lekcí už byly mezitím plné.' ?></p>
      <?php endif; ?>
      <?php if ($seznam): ?>
        <p class="text-grey" style="margin-top:1.5rem">Aktuálně jste přihlášeni na:</p>
        <ul class="rez-seznam">
          <?php foreach ($seznam as $t): ?><li><?= h(radek_terminu($t)) ?></li><?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-grey" style="margin-top:1.5rem">Aktuálně nejste přihlášeni na žádnou lekci.
          Kdykoli vypíšeme nové termíny, dáme vám vědět e-mailem.</p>
      <?php endif; ?>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Zobrazit termíny</a></div>

    <?php elseif ($prihlaska): ?>
      <h1>Vaše lekce.</h1>
      <p class="text-grey">Zaškrtněte termíny, na které chcete přijít — místa vám rezervujeme.
        Odškrtnutím se z lekce odhlásíte. O každé nově vypsané lekci vám dáme vědět e-mailem.</p>
      <form method="post">
        <input type="hidden" name="token_p" value="<?= h($prihlaska['token']) ?>">
        <input type="hidden" name="akce" value="vybrat">
        <?php vypis_vyber_lekci($vyberLekci) ?>
        <div class="rez-akce">
          <button class="button button--solid" type="submit">Uložit můj výběr</button>
        </div>
      </form>

    <?php elseif ($rezervace): ?>
      <?php if ($akce === 'zrusit' && !$minula): ?>
        <h1>Zrušit rezervaci?</h1>
        <p class="text-grey">Místo se hned uvolní někomu dalšímu.</p>
      <?php elseif ($akce === 'pravidelne' && $jeLekce && !$minula): ?>
        <h1>Chodit pravidelně?</h1>
        <p class="text-grey">Zaškrtněte lekce, na které chcete přijít, a místa vám rezervujeme
          najednou. Odškrtnutím se z lekce zase odhlásíte. O každé nově vypsané lekci vám
          pak dáme vědět e-mailem, ať si můžete vybrat další.</p>
        <form method="post">
          <input type="hidden" name="token" value="<?= h($rezervace['token']) ?>">
          <input type="hidden" name="akce" value="vybrat">
          <?php vypis_vyber_lekci($vyberLekci) ?>
          <div class="rez-akce">
            <button class="button button--solid" type="submit">Uložit můj výběr</button>
            <a class="button" href="rezervace.php?k=<?= urlencode($rezervace['token']) ?>">Zpět</a>
          </div>
        </form>
      <?php else: ?>
        <h1>Vaše rezervace</h1>
        <p class="text-grey"><?= $minula ? 'Tento termín už proběhl.' : 'Těšíme se na vás.' ?></p>
      <?php endif; ?>

      <?php $jeVyberova = $akce === 'pravidelne' && $jeLekce && !$minula; ?>
      <?php if (!$jeVyberova): // u výběru lekcí se detail rezervace nevypisuje ?>
      <dl class="rez-radky">
        <div class="rez-radek"><dt>Akce</dt><dd><?= h(nazev_typu($rezervace['typ'])) ?></dd></div>
        <div class="rez-radek"><dt>Kdy</dt><dd><?= h(radek_terminu($termin)) ?></dd></div>
        <?php if (trim((string) $rezervace['adresa']) !== ''): ?>
          <div class="rez-radek"><dt>Adresa</dt><dd><?= h($rezervace['adresa']) ?></dd></div>
        <?php endif; ?>
        <?php if (trim((string) ($rezervace['cena'] ?? '')) !== ''): ?>
          <div class="rez-radek"><dt>Cena</dt><dd><?= h($rezervace['cena']) ?></dd></div>
        <?php endif; ?>
        <?php if (trim((string) $rezervace['poznamka']) !== ''): ?>
          <div class="rez-radek"><dt>Poznámka</dt><dd><?= h($rezervace['poznamka']) ?></dd></div>
        <?php endif; ?>
        <div class="rez-radek"><dt>Na jméno</dt><dd><?= h($rezervace['jmeno']) ?></dd></div>
        <div class="rez-radek"><dt>Rezervace</dt><dd>#R-<?= h(str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT)) ?></dd></div>
      </dl>

      <?php if ($minula): ?>
        <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Vybrat další termín</a></div>

      <?php elseif ($akce === 'zrusit'): ?>
        <form method="post">
          <input type="hidden" name="token" value="<?= h($rezervace['token']) ?>">
          <input type="hidden" name="akce" value="zrusit">
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
