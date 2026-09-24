<?php
/**
 * THE MOVE :: kartotéka klientů a docházka.
 */

declare(strict_types=1);

$nadpisStranky = 'Klienti';
$aktivniZalozka = 'klienti';
require __DIR__ . '/hlava.php';

/* ---------- akce ---------- */

$zprava = '';
$akce = (string) ($_POST['akce'] ?? '');

if ($akce !== '') {
    csrf_over();

    if ($akce === 'poznamka') {
        $s = $pdo->prepare('UPDATE klienti SET poznamka = :p WHERE id = :id');
        $s->execute([':p' => trim((string) ($_POST['poznamka'] ?? '')), ':id' => (int) ($_POST['id'] ?? 0)]);
        header('Location: klienti.php?klient=' . (int) ($_POST['id'] ?? 0) . '&ok=poznamka');
        exit;
    }

    if ($akce === 'novinky_zap' || $akce === 'novinky_vyp') {
        $s = $pdo->prepare(
            $akce === 'novinky_zap'
                ? "UPDATE klienti SET novinky = 1, odhlaseno = '' WHERE id = :id"
                : "UPDATE klienti SET novinky = 0, odhlaseno = datetime('now') WHERE id = :id"
        );
        $s->execute([':id' => (int) ($_POST['id'] ?? 0)]);
        header('Location: klienti.php?klient=' . (int) ($_POST['id'] ?? 0) . '&ok=novinky');
        exit;
    }
}

if (isset($_GET['ok'])) {
    $zprava = $_GET['ok'] === 'poznamka' ? 'Poznámka byla uložena.' : 'Odběr novinek byl změněn.';
}

/* ---------- data ---------- */

// Export do CSV pro hromadné rozesílání jinde.
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="klienti-the-move-' . date('Y-m-d') . '.csv"');
    $vystup = fopen('php://output', 'w');
    fprintf($vystup, chr(0xEF) . chr(0xBB) . chr(0xBF));   // BOM kvůli Excelu
    fputcsv($vystup, ['Jméno', 'E-mail', 'Telefon', 'Absolvoval', 'Přihlášen',
                      'První návštěva', 'Poslední návštěva', 'Novinky', 'Poznámka'], ';');
    foreach (klienti_prehled($pdo, '', 'jmeno') as $k) {
        fputcsv($vystup, [
            $k['jmeno'], $k['email'], $k['telefon'],
            $k['absolvoval'], $k['prihlasen'],
            $k['prvni_navsteva'] ? ceske_datum($k['prvni_navsteva']) : '',
            $k['posledni_navsteva'] ? ceske_datum($k['posledni_navsteva']) : '',
            (int) $k['novinky'] === 1 ? 'ano' : 'ne',
            $k['poznamka'],
        ], ';');
    }
    fclose($vystup);
    exit;
}

$hledat = trim((string) ($_GET['hledat'] ?? ''));
$razeni = (string) ($_GET['razeni'] ?? 'posledni');
$klientId = (int) ($_GET['klient'] ?? 0);

$statistiky = klienti_statistiky($pdo);
$klienti = klienti_prehled($pdo, $hledat, $razeni);
$detail = $klientId > 0 ? klient_detail($pdo, $klientId) : null;
$historie = $detail ? klient_rezervace($pdo, (string) $detail['email']) : [];
?>

  <?php if ($zprava !== ''): ?><div class="hlaska"><?= e($zprava) ?></div><?php endif; ?>

  <?php if ($detail): ?>
    <!-- ===== detail klienta ===== -->
    <div class="card">
      <a class="btn btn--ghost btn--mini" href="klienti.php" style="float:right">Zpět na seznam</a>
      <h2><?= e($detail['jmeno'] !== '' ? $detail['jmeno'] : $detail['email']) ?></h2>

      <div class="grid" style="margin-bottom:1.5rem">
        <div class="pole"><label>E-mail</label>
          <a href="mailto:<?= e($detail['email']) ?>"><?= e($detail['email']) ?></a></div>
        <div class="pole"><label>Telefon</label>
          <?= $detail['telefon'] !== '' ? '<a href="tel:' . e($detail['telefon']) . '">' . e($detail['telefon']) . '</a>' : '<span class="text-grey">neuveden</span>' ?></div>
        <div class="pole"><label>V evidenci od</label>
          <?= e(ceske_datum(substr((string) $detail['vytvoreno'], 0, 10))) ?></div>
        <div class="pole"><label>Novinky</label>
          <?php if ((int) $detail['novinky'] === 1): ?>
            odebírá
          <?php else: ?>
            <span class="text-grey">odhlášen<?= $detail['odhlaseno'] !== '' ? ' ' . e(ceske_datum(substr((string) $detail['odhlaseno'], 0, 10))) : '' ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="dlazdice">
        <div class="dlazdice-kus"><span class="cislo"><?= (int) $detail['absolvoval'] ?></span>
          <span class="popis">absolvovaných lekcí</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= (int) $detail['prihlasen'] ?></span>
          <span class="popis">nadcházejících</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $detail['prvni_navsteva'] ? e(ceske_datum($detail['prvni_navsteva'])) : '—' ?></span>
          <span class="popis">poprvé</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $detail['posledni_navsteva'] ? e(ceske_datum($detail['posledni_navsteva'])) : '—' ?></span>
          <span class="popis">naposledy</span></div>
      </div>

      <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:1.5rem">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
          <input type="hidden" name="akce" value="<?= (int) $detail['novinky'] === 1 ? 'novinky_vyp' : 'novinky_zap' ?>">
          <button class="btn btn--ghost btn--mini" type="submit">
            <?= (int) $detail['novinky'] === 1 ? 'Vyřadit z novinek' : 'Zařadit zpět do novinek' ?>
          </button>
        </form>
        <?php if ((int) $detail['pravidelny'] === 1): ?>
          <span class="badge badge--volno">Chodí pravidelně</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2>Poznámka</h2>
      <p class="text-grey" style="margin-bottom:1rem">Vidíte ji jen vy, klientovi se nikde nezobrazuje.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="akce" value="poznamka">
        <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
        <textarea name="poznamka" rows="3" style="margin-bottom:1rem"><?= e($detail['poznamka']) ?></textarea>
        <button class="btn" type="submit">Uložit poznámku</button>
      </form>
    </div>

    <div class="card">
      <h2>Historie (<?= count($historie) ?>)</h2>
      <?php if (!$historie): ?>
        <p class="text-grey">Zatím žádná rezervace.</p>
      <?php else: ?>
        <?php foreach ($historie as $r):
            $minuly = $r['datum'] < date('Y-m-d'); ?>
          <div class="radek" data-minuly="<?= $minuly ? 1 : 0 ?>">
            <div>
              <div class="radek-hlavni"><?= e(cesky_den($r['datum'])) ?> <?= e(ceske_datum($r['datum'])) ?>
                · <?= e($r['cas_od']) ?> do <?= e($r['cas_do']) ?></div>
              <div class="radek-vedlejsi"><?= e(nazev_typu($r['typ'])) ?> · <?= e($r['misto']) ?><?= trim((string) $r['cena']) !== '' ? ' · ' . e($r['cena']) : '' ?></div>
            </div>
            <span class="badge <?= $minuly ? '' : 'badge--volno' ?>"><?= $minuly ? 'proběhlo' : 'přihlášen' ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ===== souhrn ===== -->
    <div class="card">
      <h2>Přehled</h2>
      <div class="dlazdice">
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['celkem'] ?></span>
          <span class="popis">klientů celkem</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['aktivni'] ?></span>
          <span class="popis">aktivních (3 měsíce)</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['novi30'] ?></span>
          <span class="popis">nových za 30 dní</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['pravidelni'] ?></span>
          <span class="popis">chodí pravidelně</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['absolvovanych'] ?></span>
          <span class="popis">absolvovaných lekcí</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['nadchazejicich'] ?></span>
          <span class="popis">nadcházejících rezervací</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['odebiraji'] ?></span>
          <span class="popis">odebírá novinky</span></div>
        <div class="dlazdice-kus"><span class="cislo"><?= $statistiky['odhlaseni'] ?></span>
          <span class="popis">odhlášeno z novinek</span></div>
      </div>
      <p class="text-grey" style="margin-top:1.25rem;font-size:.8125rem">
        „Absolvoval“ znamená rezervaci na termín, který už proběhl — fyzickou
        přítomnost systém neeviduje.
      </p>
    </div>

    <!-- ===== seznam ===== -->
    <div class="card">
      <h2>Kartotéka (<?= count($klienti) ?>)</h2>

      <form method="get" class="filtr">
        <input type="text" name="hledat" value="<?= e($hledat) ?>" placeholder="Jméno, e-mail nebo telefon">
        <select name="razeni">
          <?php foreach (['posledni' => 'Podle poslední návštěvy', 'nejvic' => 'Podle počtu lekcí',
                          'jmeno' => 'Podle jména', 'prvni' => 'Podle data registrace'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $razeni === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--mini" type="submit">Zobrazit</button>
        <?php if ($hledat !== ''): ?><a class="btn btn--ghost btn--mini" href="klienti.php">Zrušit hledání</a><?php endif; ?>
        <a class="btn btn--ghost btn--mini" href="klienti.php?export=1" style="margin-left:auto">Stáhnout CSV</a>
      </form>

      <?php if (!$klienti): ?>
        <p class="text-grey"><?= $hledat !== '' ? 'Nikdo takový tu není.' : 'Zatím tu nikdo není. Klienti se objeví sami, jakmile přijde první rezervace.' ?></p>
      <?php else: ?>
        <div class="tabulka-obal">
          <table class="tabulka">
            <thead>
              <tr>
                <th>Jméno</th><th>Kontakt</th>
                <th class="cislo-sloupec">Byl(a)</th>
                <th class="cislo-sloupec">Příště</th>
                <th>Naposledy</th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($klienti as $k): ?>
                <tr>
                  <td>
                    <a href="klienti.php?klient=<?= (int) $k['id'] ?>"><strong><?= e($k['jmeno'] !== '' ? $k['jmeno'] : '(bez jména)') ?></strong></a>
                    <?php if ((int) $k['pravidelny'] === 1): ?><span class="znacka">pravidelně</span><?php endif; ?>
                    <?php if ((int) $k['novinky'] === 0): ?><span class="znacka znacka--tlumena">bez novinek</span><?php endif; ?>
                  </td>
                  <td class="text-grey" style="font-size:.8125rem">
                    <?= e($k['email']) ?><?= $k['telefon'] !== '' ? '<br>' . e($k['telefon']) : '' ?>
                  </td>
                  <td class="cislo-sloupec"><?= (int) $k['absolvoval'] ?></td>
                  <td class="cislo-sloupec"><?= (int) $k['prihlasen'] > 0 ? (int) $k['prihlasen'] : '—' ?></td>
                  <td class="text-grey" style="font-size:.8125rem">
                    <?= $k['posledni_navsteva'] ? e(ceske_datum($k['posledni_navsteva'])) : '—' ?>
                  </td>
                  <td><a class="btn btn--ghost btn--mini" href="klienti.php?klient=<?= (int) $k['id'] ?>">Detail</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
