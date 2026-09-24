<?php
/**
 * THE MOVE :: rozesílání novinek klientům.
 *
 * Posílá se po dávkách — sdílený hosting neunese stovky e-mailů v jednom
 * požadavku. Stránka se po každé dávce sama obnoví, dokud nedojde na konec.
 */

declare(strict_types=1);

$nadpisStranky = 'Novinky';
$aktivniZalozka = 'novinky';
require __DIR__ . '/hlava.php';

/** Kolik e-mailů se odešle v jednom načtení stránky. */
const DAVKA = 20;

$zprava = '';
$chyba = '';
$akce = (string) ($_POST['akce'] ?? '');

$prijemci = prijemci_novinek($pdo);

/* ---------- akce ---------- */

if ($akce !== '') {
    csrf_over();

    $predmet = trim((string) ($_POST['predmet'] ?? ''));
    $text    = trim((string) ($_POST['text'] ?? ''));

    if ($akce === 'test') {
        if ($predmet === '' || $text === '') {
            $chyba = 'Vyplňte předmět i text.';
        } else {
            // Zkušební e-mail jde lektorce; token bereme od prvního klienta,
            // ať odhlašovací odkaz v náhledu na něco ukazuje.
            $vzor = ['jmeno' => 'Zkouška', 'email' => MAIL_ODESILATEL,
                     'token' => $prijemci[0]['token'] ?? 'zkouska'];
            $zprava = email_novinky($vzor, $predmet, $text)
                ? 'Zkušební e-mail jsme poslali na ' . MAIL_ODESILATEL . '.'
                : 'Zkušební e-mail se nepodařilo odeslat.';
        }
    }

    if ($akce === 'zalozit') {
        if ($predmet === '' || $text === '') {
            $chyba = 'Vyplňte předmět i text.';
        } elseif (!$prijemci) {
            $chyba = 'Nemáte komu poslat — nikdo neodebírá novinky.';
        } else {
            $s = $pdo->prepare(
                "INSERT INTO novinky (predmet, text, prijemcu, stav) VALUES (:p, :t, :n, 'odesila')"
            );
            $s->execute([':p' => $predmet, ':t' => $text, ':n' => count($prijemci)]);
            header('Location: novinky.php?odesilam=' . (int) $pdo->lastInsertId());
            exit;
        }
    }
}

/* ---------- rozesílání po dávkách ---------- */

$rozesila = null;
$hotovo = 0;
$zbyva = 0;

if (isset($_GET['odesilam'])) {
    $s = $pdo->prepare('SELECT * FROM novinky WHERE id = :id');
    $s->execute([':id' => (int) $_GET['odesilam']]);
    $rozesila = $s->fetch() ?: null;
}

if ($rozesila && $rozesila['stav'] === 'odesila') {
    // Bereme jen ty, kterým ještě neodešlo — podle pořadí a už odeslaného počtu.
    $hotovo = (int) $rozesila['odeslano'];
    $davka = array_slice($prijemci, $hotovo, DAVKA);

    foreach ($davka as $klient) {
        if (email_novinky($klient, (string) $rozesila['predmet'], (string) $rozesila['text'])) {
            $hotovo++;
        } else {
            // Neúspěch přeskočíme, ať se rozesílání nezacyklí.
            $hotovo++;
        }
    }

    $konec = $hotovo >= count($prijemci);
    $u = $pdo->prepare('UPDATE novinky SET odeslano = :o, stav = :s WHERE id = :id');
    $u->execute([
        ':o' => $hotovo,
        ':s' => $konec ? 'hotovo' : 'odesila',
        ':id' => $rozesila['id'],
    ]);

    $zbyva = max(0, count($prijemci) - $hotovo);
    $rozesila['odeslano'] = $hotovo;
    $rozesila['stav'] = $konec ? 'hotovo' : 'odesila';
}

$historie = $pdo->query('SELECT * FROM novinky ORDER BY id DESC LIMIT 10')->fetchAll();
?>

  <?php if ($zprava !== ''): ?><div class="hlaska"><?= e($zprava) ?></div><?php endif; ?>
  <?php if ($chyba !== ''): ?><div class="hlaska hlaska--chyba"><?= e($chyba) ?></div><?php endif; ?>

  <?php if ($rozesila && $rozesila['stav'] === 'odesila'): ?>
    <!-- Rozesílání běží: stránka se sama obnoví na další dávku. -->
    <meta http-equiv="refresh" content="2;url=novinky.php?odesilam=<?= (int) $rozesila['id'] ?>">
    <div class="card">
      <h2>Rozesíláme…</h2>
      <p>Odesláno <strong><?= $hotovo ?></strong> z <?= count($prijemci) ?>.
        Zbývá <?= $zbyva ?>. Nechte prosím stránku otevřenou, sama se posouvá dál.</p>
      <div class="pruh"><div class="pruh-vypln" style="width:<?= count($prijemci) > 0 ? round($hotovo / count($prijemci) * 100) : 100 ?>%"></div></div>
    </div>

  <?php elseif ($rozesila && $rozesila['stav'] === 'hotovo'): ?>
    <div class="card">
      <h2>Hotovo</h2>
      <p>Novinku „<?= e($rozesila['predmet']) ?>“ jsme rozeslali
        <strong><?= (int) $rozesila['odeslano'] ?></strong> klientům.</p>
      <div style="margin-top:1.5rem"><a class="btn" href="novinky.php">Napsat další</a></div>
    </div>

  <?php else: ?>
    <div class="card">
      <h2>Napsat novinku</h2>
      <p class="text-grey" style="margin-bottom:1.5rem">
        Odejde <strong><?= count($prijemci) ?></strong>
        <?= count($prijemci) === 1 ? 'klientovi' : 'klientům' ?>, kteří odebírají novinky.
        V patičce každého e-mailu je odhlašovací odkaz.
      </p>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="grid">
          <div class="pole" style="grid-column:span 4"><label>Předmět (zobrazí se i jako nadpis)</label>
            <input type="text" name="predmet" required maxlength="120"
                   value="<?= e((string) ($_POST['predmet'] ?? '')) ?>"
                   placeholder="Například: Nové termíny na říjen"></div>
        </div>
        <div class="grid">
          <div class="pole" style="grid-column:span 4"><label>Text (odstavce oddělte prázdným řádkem)</label>
            <textarea name="text" rows="10" required placeholder="Dobrý den,&#10;&#10;vypsali jsme nové termíny…"><?= e((string) ($_POST['text'] ?? '')) ?></textarea></div>
        </div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
          <button class="btn btn--ghost" type="submit" name="akce" value="test">Poslat zkušebně sobě</button>
          <button class="btn" type="submit" name="akce" value="zalozit"
                  <?= $prijemci ? '' : 'disabled' ?>>Rozeslat <?= count($prijemci) ?> klientům</button>
        </div>
        <p class="text-grey" style="margin-top:1rem;font-size:.8125rem">
          Doporučujeme si nejdřív poslat zkušební e-mail na <?= e(MAIL_ODESILATEL) ?>
          a podívat se, jak vypadá.
        </p>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($historie): ?>
    <div class="card">
      <h2>Odeslané novinky</h2>
      <?php foreach ($historie as $h): ?>
        <div class="radek">
          <div>
            <div class="radek-hlavni"><?= e($h['predmet']) ?></div>
            <div class="radek-vedlejsi">
              <?= e(ceske_datum(substr((string) $h['vytvoreno'], 0, 10))) ?>
              · <?= (int) $h['odeslano'] ?> z <?= (int) $h['prijemcu'] ?> příjemců
            </div>
          </div>
          <span class="badge <?= $h['stav'] === 'hotovo' ? '' : 'badge--volno' ?>">
            <?= $h['stav'] === 'hotovo' ? 'odesláno' : 'rozesílá se' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
