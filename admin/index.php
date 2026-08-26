<?php
/**
 * THE MOVE :: administrace termínů.
 * Při prvním spuštění si lektorka nastaví heslo, poté spravuje
 * termíny a vidí přihlášené účastníky.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/rezervace.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$pdo = db();

/* ---------- pomocné funkce ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function nastaveni_get(PDO $pdo, string $klic): ?string
{
    $s = $pdo->prepare('SELECT hodnota FROM nastaveni WHERE klic = :k');
    $s->execute([':k' => $klic]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

function nastaveni_set(PDO $pdo, string $klic, string $hodnota): void
{
    $s = $pdo->prepare('INSERT INTO nastaveni (klic, hodnota) VALUES (:k, :v)
                        ON CONFLICT(klic) DO UPDATE SET hodnota = :v');
    $s->execute([':k' => $klic, ':v' => $hodnota]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_over(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Neplatný formulář, vraťte se zpět a zkuste to znovu.');
    }
}

function presmeruj(string $kam = '')
{
    header('Location: index.php' . $kam);
    exit;
}

$adminHash = nastaveni_get($pdo, 'admin_hash');
$prihlasen = !empty($_SESSION['admin']);
$chyba = '';
$zprava = '';

/* ---------- akce ---------- */

$akce = $_POST['akce'] ?? '';

// První spuštění: vytvoření hesla
if ($akce === 'zalozit_heslo' && $adminHash === null) {
    $h1 = (string) ($_POST['heslo'] ?? '');
    $h2 = (string) ($_POST['heslo2'] ?? '');
    if (mb_strlen($h1) < 8) {
        $chyba = 'Heslo musí mít alespoň 8 znaků.';
    } elseif ($h1 !== $h2) {
        $chyba = 'Hesla se neshodují.';
    } else {
        nastaveni_set($pdo, 'admin_hash', password_hash($h1, PASSWORD_DEFAULT));
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        presmeruj();
    }
}

// Přihlášení
if ($akce === 'prihlasit' && $adminHash !== null) {
    if (password_verify((string) ($_POST['heslo'] ?? ''), $adminHash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        presmeruj();
    }
    sleep(1); // brzda proti zkoušení hesel
    $chyba = 'Nesprávné heslo.';
}

// Odhlášení
if ($akce === 'odhlasit' && $prihlasen) {
    csrf_over();
    session_destroy();
    presmeruj();
}

// Akce vyžadující přihlášení
if ($prihlasen && in_array($akce, ['pridat', 'upravit', 'smazat', 'smazat_rezervaci', 'pridat_rezervaci', 'zmenit_heslo', 'zrusit_trvalou', 'odeslat_souhrny'], true)) {
    csrf_over();

    if ($akce === 'odeslat_souhrny') {
        $poslano = odesli_cekajici_souhrny($pdo, true);
        presmeruj('?ok=souhrny&pocet=' . $poslano . '#pravidelni');
    }

    if ($akce === 'pridat' || $akce === 'upravit') {
        $datum    = (string) ($_POST['datum'] ?? '');
        $casOd    = (string) ($_POST['cas_od'] ?? '');
        $casDo    = (string) ($_POST['cas_do'] ?? '');
        $misto    = trim((string) ($_POST['misto'] ?? ''));
        $adresa   = trim((string) ($_POST['adresa'] ?? ''));
        $typ      = overeny_typ($_POST['typ'] ?? null);
        $kapacita = max(1, min(100, (int) ($_POST['kapacita'] ?? 8)));
        $poznamka = trim((string) ($_POST['poznamka'] ?? ''));
        $zverejnit = isset($_POST['zverejnit']) ? 1 : 0;

        $okDatum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) === 1;
        $okCas = preg_match('/^\d{2}:\d{2}$/', $casOd) === 1 && preg_match('/^\d{2}:\d{2}$/', $casDo) === 1;

        if (!$okDatum || !$okCas || $misto === '') {
            $chyba = 'Vyplňte prosím datum, časy a místo konání.';
        } elseif ($akce === 'pridat') {
            $s = $pdo->prepare('INSERT INTO terminy (datum, cas_od, cas_do, misto, adresa, typ, kapacita, poznamka, zverejnit)
                                VALUES (:d, :od, :do, :m, :a, :typ, :k, :p, :z)');
            $s->execute([':d' => $datum, ':od' => $casOd, ':do' => $casDo, ':m' => $misto, ':a' => $adresa,
                         ':typ' => $typ, ':k' => $kapacita, ':p' => $poznamka, ':z' => $zverejnit]);

            // Na novou skupinovou lekci rovnou přihlásíme ty, kdo chodí pravidelně.
            $pridano = doplnit_trvale_na_termin($pdo, (int) $pdo->lastInsertId());
            presmeruj('?ok=pridano' . ($pridano > 0 ? '&trvale=' . $pridano : ''));
        } else {
            $s = $pdo->prepare('UPDATE terminy SET datum=:d, cas_od=:od, cas_do=:do, misto=:m, adresa=:a,
                                typ=:typ, kapacita=:k, poznamka=:p, zverejnit=:z WHERE id=:id');
            $s->execute([':d' => $datum, ':od' => $casOd, ':do' => $casDo, ':m' => $misto, ':a' => $adresa,
                         ':typ' => $typ, ':k' => $kapacita, ':p' => $poznamka, ':z' => $zverejnit,
                         ':id' => (int) ($_POST['id'] ?? 0)]);
            presmeruj('?ok=upraveno');
        }
    }

    if ($akce === 'zrusit_trvalou') {
        $s = $pdo->prepare('UPDATE trvale_prihlasky SET aktivni = 0 WHERE id = :id');
        $s->execute([':id' => (int) ($_POST['id'] ?? 0)]);
        presmeruj('?ok=trvala_zrusena#pravidelni');
    }

    if ($akce === 'smazat') {
        $s = $pdo->prepare('DELETE FROM terminy WHERE id = :id');
        $s->execute([':id' => (int) ($_POST['id'] ?? 0)]);
        presmeruj('?ok=smazano');
    }

    if ($akce === 'smazat_rezervaci') {
        $s = $pdo->prepare('DELETE FROM rezervace WHERE id = :id');
        $s->execute([':id' => (int) ($_POST['id'] ?? 0)]);
        presmeruj('?ok=rezervace_smazana#t' . (int) ($_POST['termin_id'] ?? 0));
    }

    if ($akce === 'pridat_rezervaci') {
        $jmeno = trim((string) ($_POST['jmeno'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $telefon = trim((string) ($_POST['telefon'] ?? ''));
        $terminId = (int) ($_POST['termin_id'] ?? 0);
        if ($jmeno !== '' && $terminId > 0) {
            $s = $pdo->prepare('INSERT INTO rezervace (termin_id, jmeno, email, telefon, token, zdroj)
                                VALUES (:t, :j, :e, :tel, :tok, \'rucne\')');
            $s->execute([':t' => $terminId, ':j' => $jmeno, ':e' => $email,
                         ':tel' => $telefon, ':tok' => novy_token()]);
        }
        presmeruj('?ok=rezervace_pridana#t' . $terminId);
    }

    if ($akce === 'zmenit_heslo') {
        $stare = (string) ($_POST['stare'] ?? '');
        $h1 = (string) ($_POST['heslo'] ?? '');
        $h2 = (string) ($_POST['heslo2'] ?? '');
        if (!password_verify($stare, $adminHash)) {
            $chyba = 'Současné heslo není správné.';
        } elseif (mb_strlen($h1) < 8) {
            $chyba = 'Nové heslo musí mít alespoň 8 znaků.';
        } elseif ($h1 !== $h2) {
            $chyba = 'Nová hesla se neshodují.';
        } else {
            nastaveni_set($pdo, 'admin_hash', password_hash($h1, PASSWORD_DEFAULT));
            $zprava = 'Heslo bylo změněno.';
        }
    }
}

/* ---------- data pro výpis ---------- */

$editTermin = null;
if ($prihlasen && isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM terminy WHERE id = :id');
    $s->execute([':id' => (int) $_GET['edit']]);
    $editTermin = $s->fetch() ?: null;
}

$terminy = [];
if ($prihlasen) {
    $terminy = $pdo->query(
        "SELECT t.*, (SELECT COUNT(*) FROM rezervace r WHERE r.termin_id = t.id) AS obsazeno
         FROM terminy t
         ORDER BY (t.datum < date('now')) ASC, t.datum ASC, t.cas_od ASC"
    )->fetchAll();

    $rezervaceMap = [];
    $vsechny = $pdo->query('SELECT * FROM rezervace ORDER BY vytvoreno ASC')->fetchAll();
    foreach ($vsechny as $r) {
        $rezervaceMap[(int) $r['termin_id']][] = $r;
    }

    $pravidelni = $pdo->query(
        'SELECT * FROM trvale_prihlasky WHERE aktivni = 1 ORDER BY jmeno COLLATE NOCASE'
    )->fetchAll();

    // Souhrny o nových termínech čekají, než lektorka dovypisuje ostatní.
    $cekajici = cekajici_souhrny($pdo);
    $odejdeV = '';
    foreach ($cekajici as $s) {
        $cas = strtotime($s['posledni'] . ' UTC') + PAUZA_SOUHRNU * 60;
        if ($odejdeV === '' || $cas < strtotime($odejdeV)) {
            $odejdeV = date('Y-m-d H:i:s', $cas);
        }
    }
}

$okZpravy = [
    'pridano' => 'Termín byl přidán.',
    'upraveno' => 'Termín byl upraven.',
    'smazano' => 'Termín byl smazán včetně rezervací.',
    'rezervace_smazana' => 'Rezervace byla odstraněna.',
    'rezervace_pridana' => 'Rezervace byla přidána.',
    'trvala_zrusena' => 'Pravidelná docházka byla vypnuta.',
    'souhrny' => 'Souhrny byly rozeslány.',
];
if (isset($_GET['ok'], $okZpravy[$_GET['ok']])) {
    $zprava = $okZpravy[$_GET['ok']];
}
if (isset($_GET['trvale']) && (int) $_GET['trvale'] > 0) {
    $pocet = (int) $_GET['trvale'];
    $zprava .= ' Automaticky jsme přihlásili ' . $pocet
        . ($pocet === 1 ? ' pravidelného účastníka' : ($pocet < 5 ? ' pravidelné účastníky' : ' pravidelných účastníků'))
        . '; souhrn jim odejde, až dovypisujete zbylé termíny.';
}
if (isset($_GET['pocet'])) {
    $zprava = 'Odeslali jsme ' . (int) $_GET['pocet']
        . ((int) $_GET['pocet'] === 1 ? ' souhrn.' : ((int) $_GET['pocet'] < 5 ? ' souhrny.' : ' souhrnů.'));
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Administrace · The Move</title>
<link href="https://api.fontshare.com/v2/css?f[]=general-sans@400,500&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root { --ink:#111; --paper:#fff; --soft:#fafafa; --grey:#999; --line:#e6e6e6; --yellow:#f2af0e; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:"Roboto Mono",monospace; font-size:.9375rem; line-height:160%; color:var(--ink); background:var(--soft); }
h1,h2,h3 { font-family:"General Sans",sans-serif; font-weight:400; letter-spacing:1px; text-transform:uppercase; line-height:120%; }
a { color:inherit; }
.wrap { max-width:64rem; margin:0 auto; padding:2rem 1.25rem 5rem; }
.topbar { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1.25rem 0 2rem; flex-wrap:wrap; }
.logo { height:24px; }
.card { background:var(--paper); border:1px solid var(--line); padding:2rem; margin-bottom:2rem; }
.card h2 { margin-bottom:1.5rem; padding-top:.75rem; position:relative; }
.card h2::before { content:""; position:absolute; top:0; left:0; width:2.25rem; height:2px; background:var(--yellow); }
label { display:block; font-size:12px; letter-spacing:.5px; text-transform:uppercase; margin-bottom:.35rem; }
input[type=text],input[type=password],input[type=email],input[type=date],input[type=time],input[type=number],select {
  width:100%; font-family:inherit; font-size:.9375rem; padding:.6rem .75rem;
  border:1px solid var(--line); background:var(--paper); border-radius:0; }
input:focus,select:focus { outline:none; border-color:var(--yellow); }
.grid { display:grid; gap:1.25rem; grid-template-columns:repeat(auto-fit,minmax(9rem,1fr)); margin-bottom:1.25rem; }
.pole { min-width:0; }
.btn { display:inline-flex; align-items:center; gap:.5rem; font-family:"Roboto Mono",monospace; font-size:13px;
  letter-spacing:.5px; text-transform:uppercase; border:1px solid var(--ink); border-radius:500px;
  background:var(--ink); color:var(--paper); padding:.65rem 1.15rem; cursor:pointer; transition:.2s; text-decoration:none; }
.btn:hover { background:var(--yellow); border-color:var(--yellow); color:var(--ink); }
.btn--ghost { background:transparent; color:var(--ink); }
.btn--ghost:hover { background:var(--ink); color:var(--paper); }
.btn--mini { padding:.35rem .75rem; font-size:11px; }
.btn--potvrdit { background:#d0342c; border-color:#d0342c; color:var(--paper); }
.btn--potvrdit:hover { background:#b02b24; border-color:#b02b24; color:var(--paper); }
.hlaska { padding:1rem 1.25rem; margin-bottom:1.5rem; border-left:3px solid var(--yellow); background:var(--paper); }
.hlaska--chyba { border-left-color:#d0342c; }
.termin { background:var(--paper); border:1px solid var(--line); margin-bottom:1rem; }
.termin[data-minuly="1"] { opacity:.55; }
.termin-hlava { display:flex; flex-wrap:wrap; gap:.75rem 1.5rem; align-items:center; padding:1.15rem 1.5rem; }
.termin-kdy { font-family:"General Sans",sans-serif; text-transform:uppercase; letter-spacing:1px; font-size:1.05rem; }
.termin-misto { color:var(--grey); font-size:.8125rem; text-transform:uppercase; letter-spacing:.5px; }
.badge { font-size:12px; letter-spacing:.5px; text-transform:uppercase; padding:.25rem .7rem; border:1px solid var(--line); border-radius:500px; }
.badge--volno { border-color:var(--yellow); }
.badge--plno { background:var(--ink); color:var(--paper); border-color:var(--ink); }
.badge--skryty { border-style:dashed; color:var(--grey); }
.badge--typ { background:var(--yellow); border-color:var(--yellow); color:var(--ink); }
.text-grey { color:var(--grey); font-size:.875rem; line-height:170%; }
.termin-akce { margin-left:auto; display:flex; gap:.5rem; flex-wrap:wrap; }
details { border-top:1px solid var(--line); }
summary { cursor:pointer; padding:.85rem 1.5rem; font-size:12px; letter-spacing:.5px; text-transform:uppercase; color:var(--grey); list-style:none; }
summary::before { content:"+ "; color:var(--yellow); }
details[open] summary::before { content:"− "; }
.ucastnici { padding:0 1.5rem 1.25rem; }
.ucastnik { display:flex; flex-wrap:wrap; gap:.5rem 1.5rem; align-items:center; padding:.6rem 0; border-bottom:1px dashed var(--line); font-size:.8125rem; }
.ucastnik span:first-child { font-weight:500; min-width:12rem; }
.ucastnik .mail, .ucastnik .tel { color:var(--grey); }
.ucastnik form { margin-left:auto; }
.mini-form { display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end; padding-top:1rem; }
.mini-form .pole { flex:1 1 10rem; }
.zapati { color:var(--grey); font-size:12px; margin-top:3rem; }
.login { max-width:26rem; margin:14vh auto 0; }
.prepinac { display:flex; align-items:center; gap:.6rem; font-size:13px; text-transform:uppercase; letter-spacing:.5px; }
.prepinac input { width:auto; }
@media (max-width:600px){ .card{padding:1.25rem} .termin-hlava{padding:1rem} .ucastnici{padding:0 1rem 1rem} }
</style>
</head>
<body>
<div class="wrap">

<?php if (!$prihlasen): ?>

  <div class="login">
    <div class="card">
      <img class="logo" src="../assets/img/logo.webp" alt="The Move" style="margin-bottom:1.5rem">
      <?php if ($adminHash === null): ?>
        <h2>Vítejte! Nastavte si heslo</h2>
        <?php if ($chyba): ?><div class="hlaska hlaska--chyba"><?= e($chyba) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="akce" value="zalozit_heslo">
          <div style="margin-bottom:1.25rem">
            <label for="h1">Nové heslo (min. 8 znaků)</label>
            <input type="password" id="h1" name="heslo" required minlength="8" autocomplete="new-password">
          </div>
          <div style="margin-bottom:1.5rem">
            <label for="h2">Heslo znovu</label>
            <input type="password" id="h2" name="heslo2" required minlength="8" autocomplete="new-password">
          </div>
          <button class="btn" type="submit">Vytvořit a přihlásit se</button>
        </form>
      <?php else: ?>
        <h2>Přihlášení</h2>
        <?php if ($chyba): ?><div class="hlaska hlaska--chyba"><?= e($chyba) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="akce" value="prihlasit">
          <div style="margin-bottom:1.5rem">
            <label for="h">Heslo</label>
            <input type="password" id="h" name="heslo" required autocomplete="current-password" autofocus>
          </div>
          <button class="btn" type="submit">Přihlásit se</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <div class="topbar">
    <img class="logo" src="../assets/img/logo.webp" alt="The Move">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <a class="btn btn--ghost btn--mini" href="../index.html#terminy">Zobrazit web</a>
      <a class="btn btn--ghost btn--mini" href="?heslo=1">Změna hesla</a>
      <form method="post" style="display:inline">
        <input type="hidden" name="akce" value="odhlasit">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--mini" type="submit">Odhlásit</button>
      </form>
    </div>
  </div>

  <?php if ($zprava): ?><div class="hlaska"><?= e($zprava) ?></div><?php endif; ?>
  <?php if ($chyba): ?><div class="hlaska hlaska--chyba"><?= e($chyba) ?></div><?php endif; ?>

  <?php if (isset($_GET['heslo'])): ?>
    <div class="card">
      <h2>Změna hesla</h2>
      <form method="post">
        <input type="hidden" name="akce" value="zmenit_heslo">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="grid">
          <div class="pole"><label>Současné heslo</label><input type="password" name="stare" required autocomplete="current-password"></div>
          <div class="pole"><label>Nové heslo</label><input type="password" name="heslo" required minlength="8" autocomplete="new-password"></div>
          <div class="pole"><label>Nové heslo znovu</label><input type="password" name="heslo2" required minlength="8" autocomplete="new-password"></div>
        </div>
        <button class="btn" type="submit">Změnit heslo</button>
        <a class="btn btn--ghost" href="index.php">Zpět</a>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= $editTermin ? 'Upravit termín' : 'Přidat nový termín' ?></h2>
    <form method="post">
      <input type="hidden" name="akce" value="<?= $editTermin ? 'upravit' : 'pridat' ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?php if ($editTermin): ?><input type="hidden" name="id" value="<?= (int) $editTermin['id'] ?>"><?php endif; ?>
      <div class="grid">
        <div class="pole"><label>Datum</label>
          <input type="date" name="datum" required value="<?= e($editTermin['datum'] ?? '') ?>"></div>
        <div class="pole"><label>Od</label>
          <input type="time" name="cas_od" required value="<?= e($editTermin['cas_od'] ?? '17:30') ?>"></div>
        <div class="pole"><label>Do</label>
          <input type="time" name="cas_do" required value="<?= e($editTermin['cas_do'] ?? '18:45') ?>"></div>
        <div class="pole"><label>Kapacita</label>
          <input type="number" name="kapacita" min="1" max="100" required value="<?= e((string) ($editTermin['kapacita'] ?? 8)) ?>"></div>
      </div>
      <div class="grid">
        <div class="pole" style="grid-column:span 2"><label>Druh události</label>
          <select name="typ" required>
            <?php $vybrany = overeny_typ($editTermin['typ'] ?? null); ?>
            <?php foreach (typy_udalosti() as $klic => $nazev): ?>
              <option value="<?= e($klic) ?>" <?= $vybrany === $klic ? 'selected' : '' ?>><?= e($nazev) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="pole" style="grid-column:span 2"><label>Místo konání</label>
          <input type="text" name="misto" required value="<?= e($editTermin['misto'] ?? 'Ostrava-Poruba · Poklad') ?>"></div>
      </div>
      <div class="grid">
        <div class="pole" style="grid-column:span 2"><label>Adresa (nepovinné, jde do e-mailu)</label>
          <input type="text" name="adresa" value="<?= e($editTermin['adresa'] ?? '') ?>"></div>
        <div class="pole" style="grid-column:span 2"><label>Poznámka (nepovinné)</label>
          <input type="text" name="poznamka" value="<?= e($editTermin['poznamka'] ?? '') ?>"></div>
      </div>
      <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
        <label class="prepinac" style="margin:0">
          <input type="checkbox" name="zverejnit" <?= ($editTermin['zverejnit'] ?? 1) ? 'checked' : '' ?>>
          Zobrazit na webu
        </label>
        <button class="btn" type="submit"><?= $editTermin ? 'Uložit změny' : 'Přidat termín' ?></button>
        <?php if ($editTermin): ?><a class="btn btn--ghost" href="index.php">Zrušit</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" id="pravidelni">
    <h2>Chodí pravidelně</h2>
    <?php if (!$pravidelni): ?>
      <p class="text-grey" style="margin:0">Zatím nikdo. Kdo si v potvrzovacím e-mailu klikne na
        „Chodit na lekce pravidelně“, objeví se tady a na každou novou skupinovou lekci
        ho systém přihlásí sám.</p>
    <?php else: ?>
      <p class="text-grey" style="margin:0 0 1.25rem">Tito lidé se automaticky přihlašují na každou
        nově vypsanou skupinovou lekci. Souhrn s novými termíny jim odejde
        <?= (int) PAUZA_SOUHRNU ?> minut po posledním vypsaném termínu, aby dostali jeden e-mail
        místo deseti.</p>

      <?php if ($cekajici): ?>
        <div class="hlaska" style="margin-bottom:1.25rem">
          Čeká na rozeslání: <strong><?= count($cekajici) ?></strong>
          <?= count($cekajici) === 1 ? 'souhrn' : (count($cekajici) < 5 ? 'souhrny' : 'souhrnů') ?>
          <?php if ($odejdeV !== ''): ?>· odejde v <?= e(date('H:i', strtotime($odejdeV))) ?><?php endif; ?>
          <form method="post" style="display:inline;margin-left:.75rem">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="akce" value="odeslat_souhrny">
            <button class="btn btn--mini" type="submit">Odeslat hned</button>
          </form>
        </div>
      <?php endif; ?>
      <?php foreach ($pravidelni as $p): ?>
        <div class="termin-hlava" style="padding:1rem 0;border-top:1px solid var(--line)">
          <div>
            <div class="termin-kdy"><?= e($p['jmeno']) ?></div>
            <div class="termin-misto"><?= e($p['email']) ?><?= $p['telefon'] !== '' ? ' · ' . e($p['telefon']) : '' ?></div>
          </div>
          <div class="termin-akce">
            <form method="post" style="display:inline" data-potvrdit="Opravdu vypnout?">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="akce" value="zrusit_trvalou">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn btn--ghost btn--mini" type="submit">Vypnout</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <h2 style="margin:2.5rem 0 1.25rem">Termíny</h2>

  <?php if (!$terminy): ?>
    <div class="card">Zatím tu nejsou žádné termíny, přidejte první výše.</div>
  <?php endif; ?>

  <?php foreach ($terminy as $t):
      $minuly = $t['datum'] < date('Y-m-d');
      $obsazeno = (int) $t['obsazeno'];
      $kapacita = (int) $t['kapacita'];
      $volno = max(0, $kapacita - $obsazeno);
      $lide = $rezervaceMap[(int) $t['id']] ?? [];
  ?>
  <div class="termin" id="t<?= (int) $t['id'] ?>" data-minuly="<?= $minuly ? 1 : 0 ?>">
    <div class="termin-hlava">
      <div>
        <div class="termin-kdy"><?= e(cesky_den($t['datum'])) ?> <?= e(ceske_datum($t['datum'])) ?> · <?= e($t['cas_od']) ?> do <?= e($t['cas_do']) ?></div>
        <div class="termin-misto"><?= e($t['misto']) ?><?= $t['poznamka'] !== '' ? ' · ' . e($t['poznamka']) : '' ?></div>
      </div>
      <span class="badge badge--typ"><?= e(nazev_typu($t['typ'] ?? null)) ?></span>
      <span class="badge <?= $volno === 0 ? 'badge--plno' : 'badge--volno' ?>">
        <?= $minuly ? 'Proběhlo · ' : '' ?>Obsazeno <?= $obsazeno ?> z <?= $kapacita ?>
      </span>
      <?php if (!(int) $t['zverejnit']): ?><span class="badge badge--skryty">Skrytý</span><?php endif; ?>
      <div class="termin-akce">
        <a class="btn btn--ghost btn--mini" href="?edit=<?= (int) $t['id'] ?>">Upravit</a>
        <form method="post" data-potvrdit="Opravdu smazat?"
              title="Smaže termín<?= $obsazeno > 0 ? ' včetně ' . $obsazeno . ' rezervací' : '' ?>">
          <input type="hidden" name="akce" value="smazat">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button class="btn btn--ghost btn--mini" type="submit">Smazat</button>
        </form>
      </div>
    </div>
    <details <?= count($lide) > 0 && !$minuly ? '' : '' ?>>
      <summary>Přihlášení účastníci (<?= count($lide) ?>)</summary>
      <div class="ucastnici">
        <?php if (!$lide): ?>
          <p style="color:var(--grey);font-size:.8125rem;padding:.5rem 0">Zatím nikdo.</p>
        <?php endif; ?>
        <?php foreach ($lide as $r): ?>
        <div class="ucastnik">
          <span><?= e($r['jmeno']) ?></span>
          <span class="mail"><?= e($r['email']) ?></span>
          <?php if ($r['telefon'] !== ''): ?><span class="tel"><?= e($r['telefon']) ?></span><?php endif; ?>
          <form method="post" data-potvrdit="Opravdu odstranit?" title="Odstraní rezervaci účastníka">
            <input type="hidden" name="akce" value="smazat_rezervaci">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="termin_id" value="<?= (int) $t['id'] ?>">
            <button class="btn btn--ghost btn--mini" type="submit">Odstranit</button>
          </form>
        </div>
        <?php endforeach; ?>

        <form method="post" class="mini-form">
          <input type="hidden" name="akce" value="pridat_rezervaci">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="termin_id" value="<?= (int) $t['id'] ?>">
          <div class="pole"><label>Jméno</label><input type="text" name="jmeno" required></div>
          <div class="pole"><label>E-mail</label><input type="email" name="email"></div>
          <div class="pole"><label>Telefon</label><input type="text" name="telefon"></div>
          <button class="btn btn--mini" type="submit">Přidat ručně</button>
        </form>
      </div>
    </details>
  </div>
  <?php endforeach; ?>

  <p class="zapati">The Move :: administrace termínů. Smazáním termínu se odstraní i jeho rezervace.</p>

<?php endif; ?>

</div>

<script>
/* Dvoukrokové potvrzení místo systémového dialogu: první klik tlačítko
   „nabije" (zčervená), druhý klik akci provede. Po 5 s se samo vrátí zpět.
   Nezávisí na window.confirm, takže funguje i tam, kde jsou dialogy blokované. */
document.querySelectorAll('form[data-potvrdit]').forEach(function (form) {
  var btn = form.querySelector('button[type=submit]');
  var puvodni = btn.textContent;
  var nabito = false;
  var casovac;

  function reset() {
    nabito = false;
    btn.textContent = puvodni;
    btn.classList.remove('btn--potvrdit');
  }

  form.addEventListener('submit', function (e) {
    if (nabito) { return; }          // druhý klik: odešli
    e.preventDefault();              // první klik: jen se zeptej
    nabito = true;
    btn.textContent = form.dataset.potvrdit;
    btn.classList.add('btn--potvrdit');
    clearTimeout(casovac);
    casovac = setTimeout(reset, 5000);
  });

  btn.addEventListener('blur', function () { if (nabito) { setTimeout(reset, 200); } });
});
</script>
</body>
</html>
