<?php
/**
 * THE MOVE :: odhlášení z novinek (odkaz z patičky newsletteru).
 * Vykresluje se ze stránky rezervace.php, která už má připravené $klient,
 * $odhlasen a $tokenN.
 */

declare(strict_types=1);

if (!isset($klient)) { exit; }

function ho(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Odhlášení z novinek · The Move</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=general-sans@400,500&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=<?= substr(md5_file(__DIR__ . '/../css/style.css') ?: '', 0, 8) ?>">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <style>
    .rez-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 4rem 1.5rem; background: var(--paper-soft); }
    .rez-card { width: 100%; max-width: 38rem; background: var(--paper); border: 1px solid var(--line); padding: 3rem; }
    .rez-card .logo { height: 22px; width: auto; margin-bottom: 2.5rem; }
    .rez-card h1 { font-family: var(--font-head); font-size: 2rem; line-height: 115%; letter-spacing: -1px; margin-bottom: 1rem; }
    .rez-cara { width: 2.75rem; height: 3px; background: var(--yellow); margin-bottom: 1.5rem; }
    .rez-akce { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 2rem; }
    .rez-pata { margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--line); font-size: 0.8125rem; color: var(--grey); }
    @media screen and (max-width: 560px) { .rez-card { padding: 2rem 1.5rem; } }
  </style>
</head>
<body>
<main class="rez-page">
  <div class="rez-card">
    <a href="index.html"><img class="logo" src="assets/img/logo.webp" alt="The Move" width="600" height="108"></a>
    <div class="rez-cara"></div>

    <?php if (!$klient): ?>
      <h1>Odkaz nefunguje.</h1>
      <p class="text-grey">Tento odhlašovací odkaz neznáme. Napište nám prosím na
        <a href="mailto:info@themove.cz" style="text-decoration:underline">info@themove.cz</a>
        a zařídíme to ručně.</p>
      <div class="rez-akce"><a class="button button--solid" href="index.html">Zpět na web</a></div>

    <?php elseif ($odhlasen): ?>
      <h1>Novinky vám posílat nebudeme.</h1>
      <p class="text-grey">Adresu <strong><?= ho($klient['email']) ?></strong> jsme z rozesílání
        vyřadili. Potvrzení rezervací a připomínky lekcí vám chodit budou dál —
        ty se týkají termínů, na které se sami přihlásíte.</p>
      <div class="rez-akce"><a class="button button--solid" href="index.html#terminy">Zobrazit termíny</a></div>

    <?php else: ?>
      <h1>Odhlásit novinky?</h1>
      <p class="text-grey">Na adresu <strong><?= ho($klient['email']) ?></strong> vám přestaneme
        posílat e-maily o nových termínech a akcích.</p>
      <p class="text-grey">Potvrzení rezervací a připomínky lekcí vám chodit budou dál —
        bez nich byste nevěděli, kdy a kam máte přijít.</p>
      <form method="post">
        <input type="hidden" name="token_n" value="<?= ho($tokenN) ?>">
        <input type="hidden" name="akce" value="odhlasit_novinky">
        <div class="rez-akce">
          <button class="button button--solid" type="submit">Odhlásit novinky</button>
          <a class="button" href="index.html">Nechat být</a>
        </div>
      </form>
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
