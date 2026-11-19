<?php
/**
 * THE MOVE :: uživatelé administrace a přihlášená zařízení.
 */

declare(strict_types=1);

$nadpisStranky = 'Uživatelé';
$aktivniZalozka = 'uzivatele';
require __DIR__ . '/hlava.php';

$zprava = '';
$chyba  = '';
$akce   = (string) ($_POST['akce'] ?? '');

if ($akce !== '') {
    csrf_over();

    if ($akce === 'pridat') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $jmeno = trim((string) ($_POST['jmeno'] ?? ''));
        $h1 = (string) ($_POST['heslo'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $chyba = 'Vyplňte prosím platný e-mail.';
        } elseif (mb_strlen($h1) < 8) {
            $chyba = 'Heslo musí mít alespoň 8 znaků.';
        } else {
            $uz = $pdo->prepare('SELECT COUNT(*) FROM uzivatele WHERE email = :e COLLATE NOCASE');
            $uz->execute([':e' => $email]);
            if ((int) $uz->fetchColumn() > 0) {
                $chyba = 'Účet s tímto e-mailem už existuje.';
            } else {
                $i = $pdo->prepare('INSERT INTO uzivatele (email, jmeno, heslo) VALUES (:e, :j, :h)');
                $i->execute([':e' => $email, ':j' => $jmeno, ':h' => password_hash($h1, PASSWORD_DEFAULT)]);
                header('Location: uzivatele.php?ok=pridan');
                exit;
            }
        }
    }

    if ($akce === 'vypnout' || $akce === 'zapnout') {
        $id = (int) ($_POST['id'] ?? 0);
        // Sám sebe vypnout nelze, jinak by se nikdo nedostal dovnitř.
        if ($id === (int) $uzivatel['id']) {
            $chyba = 'Vlastní účet vypnout nejde.';
        } else {
            $u = $pdo->prepare('UPDATE uzivatele SET aktivni = :a WHERE id = :id');
            $u->execute([':a' => $akce === 'zapnout' ? 1 : 0, ':id' => $id]);
            if ($akce === 'vypnout') {
                $pdo->prepare('DELETE FROM prihlaseni WHERE uzivatel_id = :u')->execute([':u' => $id]);
            }
            header('Location: uzivatele.php?ok=' . ($akce === 'zapnout' ? 'zapnut' : 'vypnut'));
            exit;
        }
    }

    if ($akce === 'odhlasit_zarizeni') {
        $pdo->prepare('DELETE FROM prihlaseni WHERE id = :id AND uzivatel_id = :u')
            ->execute([':id' => (int) ($_POST['id'] ?? 0), ':u' => $uzivatel['id']]);
        header('Location: uzivatele.php?ok=zarizeni');
        exit;
    }

    if ($akce === 'nove_heslo') {
        $id = (int) ($_POST['id'] ?? 0);
        $h1 = (string) ($_POST['heslo'] ?? '');
        if (mb_strlen($h1) < 8) {
            $chyba = 'Heslo musí mít alespoň 8 znaků.';
        } else {
            $u = $pdo->prepare('UPDATE uzivatele SET heslo = :h WHERE id = :id');
            $u->execute([':h' => password_hash($h1, PASSWORD_DEFAULT), ':id' => $id]);
            $pdo->prepare('DELETE FROM prihlaseni WHERE uzivatel_id = :u')->execute([':u' => $id]);
            header('Location: uzivatele.php?ok=heslo');
            exit;
        }
    }
}

$zpravy = [
    'pridan'   => 'Uživatel byl přidán.',
    'vypnut'   => 'Přístup byl odebrán.',
    'zapnut'   => 'Přístup byl obnoven.',
    'heslo'    => 'Heslo bylo změněno, uživatel se přihlásí znovu.',
    'zarizeni' => 'Zařízení bylo odhlášeno.',
];
if (isset($_GET['ok'], $zpravy[$_GET['ok']])) { $zprava = $zpravy[$_GET['ok']]; }

$uzivatele = $pdo->query(
    'SELECT * FROM uzivatele ORDER BY aktivni DESC, jmeno COLLATE NOCASE, email'
)->fetchAll();

$zarizeni = $pdo->prepare(
    'SELECT * FROM prihlaseni WHERE uzivatel_id = :u ORDER BY pouzito DESC'
);
$zarizeni->execute([':u' => $uzivatel['id']]);
$mojeZarizeni = $zarizeni->fetchAll();
?>

  <?php if ($zprava !== ''): ?><div class="hlaska"><?= e($zprava) ?></div><?php endif; ?>
  <?php if ($chyba !== ''): ?><div class="hlaska hlaska--chyba"><?= e($chyba) ?></div><?php endif; ?>

  <div class="card">
    <h2>Kdo má přístup</h2>
    <p class="text-grey" style="margin-bottom:1.5rem">
      Každý se přihlašuje vlastním e-mailem a heslem. Odebrání přístupu uživatele
      okamžitě odhlásí na všech zařízeních.
    </p>

    <?php foreach ($uzivatele as $u): $ja = (int) $u['id'] === (int) $uzivatel['id']; ?>
      <div class="radek" data-minuly="<?= (int) $u['aktivni'] === 1 ? 0 : 1 ?>">
        <div>
          <div class="radek-hlavni">
            <?= e($u['jmeno'] !== '' ? $u['jmeno'] : $u['email']) ?>
            <?php if ($ja): ?><span class="znacka">to jste vy</span><?php endif; ?>
            <?php if ((int) $u['aktivni'] === 0): ?><span class="znacka znacka--tlumena">bez přístupu</span><?php endif; ?>
          </div>
          <div class="radek-vedlejsi">
            <?= e($u['email']) ?>
            <?php if ($u['prihlasen'] !== ''): ?>
              · naposledy <?= e(ceske_datum(substr((string) $u['prihlasen'], 0, 10))) ?>
            <?php else: ?>
              · zatím nepřihlášen
            <?php endif; ?>
          </div>
        </div>
        <div class="termin-akce">
          <?php if (!$ja): ?>
            <form method="post" style="display:inline" data-potvrdit="Opravdu?">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="akce" value="<?= (int) $u['aktivni'] === 1 ? 'vypnout' : 'zapnout' ?>">
              <button class="btn btn--ghost btn--mini" type="submit">
                <?= (int) $u['aktivni'] === 1 ? 'Odebrat přístup' : 'Obnovit přístup' ?>
              </button>
            </form>
          <?php endif; ?>
          <details style="display:inline-block">
            <summary class="btn btn--ghost btn--mini" style="list-style:none">Nové heslo</summary>
            <form method="post" style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="hidden" name="akce" value="nove_heslo">
              <input type="password" name="heslo" required minlength="8"
                     placeholder="Nové heslo" autocomplete="new-password" style="width:auto">
              <button class="btn btn--mini" type="submit">Uložit</button>
            </form>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>Přidat uživatele</h2>
    <p class="text-grey" style="margin-bottom:1.5rem">
      Heslo zvolte vy a předejte mu ho osobně — po prvním přihlášení si ho může
      změnit v Nastavení hesla.
    </p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="akce" value="pridat">
      <div class="grid">
        <div class="pole" style="grid-column:span 2"><label>Jméno</label>
          <input type="text" name="jmeno" autocomplete="off"></div>
        <div class="pole" style="grid-column:span 2"><label>E-mail</label>
          <input type="email" name="email" required autocomplete="off"></div>
      </div>
      <div class="grid">
        <div class="pole" style="grid-column:span 2"><label>Heslo (min. 8 znaků)</label>
          <input type="password" name="heslo" required minlength="8" autocomplete="new-password"></div>
      </div>
      <button class="btn" type="submit">Přidat uživatele</button>
    </form>
  </div>

  <div class="card">
    <h2>Moje přihlášená zařízení</h2>
    <?php if (!$mojeZarizeni): ?>
      <p class="text-grey">Žádné zařízení si vás nepamatuje — po zavření prohlížeče
        se budete přihlašovat znovu.</p>
    <?php else: ?>
      <p class="text-grey" style="margin-bottom:1.25rem">
        Na těchto zařízeních zůstáváte přihlášen/a bez zadávání hesla.
        Když některé ztratíte, odhlaste ho tady.
      </p>
      <?php foreach ($mojeZarizeni as $z): ?>
        <div class="radek">
          <div>
            <div class="radek-hlavni"><?= e($z['zarizeni'] !== '' ? $z['zarizeni'] : 'Neznámé zařízení') ?></div>
            <div class="radek-vedlejsi">
              naposledy <?= e(ceske_datum(substr((string) $z['pouzito'], 0, 10))) ?>
              · platí do <?= e(ceske_datum(substr((string) $z['platnost'], 0, 10))) ?>
            </div>
          </div>
          <form method="post" style="display:inline" data-potvrdit="Odhlásit?">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
            <input type="hidden" name="akce" value="odhlasit_zarizeni">
            <button class="btn btn--ghost btn--mini" type="submit">Odhlásit</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<script>
/* Dvoukrokové potvrzení — stejné jako u termínů. */
document.querySelectorAll('form[data-potvrdit]').forEach(function (form) {
  var btn = form.querySelector('button[type=submit]');
  var puvodni = btn.textContent, nabito = false, casovac;
  function reset() { nabito = false; btn.textContent = puvodni; btn.classList.remove('btn--potvrdit'); }
  form.addEventListener('submit', function (e) {
    if (nabito) { return; }
    e.preventDefault();
    nabito = true;
    btn.textContent = form.dataset.potvrdit;
    btn.classList.add('btn--potvrdit');
    clearTimeout(casovac);
    casovac = setTimeout(reset, 5000);
  });
  btn.addEventListener('blur', function () { if (nabito) { setTimeout(reset, 200); } });
});
</script>

</div>
</body>
</html>
