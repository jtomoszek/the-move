<?php
/**
 * THE MOVE :: společná logika rezervací a trvalých přihlášek.
 * Používá ji veřejné API, stránka rezervace.php i administrace.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

/**
 * Vytvoří rezervaci na termín. Hlídá kapacitu i dvojí přihlášení.
 *
 * @return array{ok:bool,chyba:string,kod:int,rezervace:?array,termin:?array}
 */
function vytvor_rezervaci(PDO $pdo, int $terminId, string $jmeno, string $email,
                          string $telefon = '', string $zdroj = 'web'): array
{
    $chyba = function (string $zprava, int $kod): array {
        return ['ok' => false, 'chyba' => $zprava, 'kod' => $kod, 'rezervace' => null, 'termin' => null];
    };

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT t.*, (SELECT COUNT(*) FROM rezervace r WHERE r.termin_id = t.id) AS obsazeno
         FROM terminy t
         WHERE t.id = :id AND t.zverejnit = 1 AND t.datum >= :dnes"
    );
    $stmt->execute([':id' => $terminId, ':dnes' => date('Y-m-d')]);
    $termin = $stmt->fetch();

    if (!$termin) {
        $pdo->rollBack();
        return $chyba('Tento termín už není dostupný.', 404);
    }

    if ((int) $termin['obsazeno'] >= (int) $termin['kapacita']) {
        $pdo->rollBack();
        return $chyba('Tento termín je bohužel již obsazený.', 409);
    }

    $dup = $pdo->prepare('SELECT COUNT(*) FROM rezervace WHERE termin_id = :t AND email = :e COLLATE NOCASE');
    $dup->execute([':t' => $terminId, ':e' => $email]);
    if ((int) $dup->fetchColumn() > 0) {
        $pdo->rollBack();
        return $chyba('Na tento termín jste již přihlášeni.', 409);
    }

    // Pozdní přihláška: vlna připomínek pro tento termín už proběhla (nebo
    // neproběhne), takže se připomínka neposílá — praktické informace dostane
    // člověk rovnou v potvrzení.
    $pozdni = $termin['datum'] === date('Y-m-d')
        || ($termin['datum'] === date('Y-m-d', strtotime('+1 day')) && date('H:i') >= PRIPOMINKA_CAS);

    $token = novy_token();
    $ins = $pdo->prepare(
        'INSERT INTO rezervace (termin_id, jmeno, email, telefon, token, zdroj, pripominka)
         VALUES (:t, :j, :e, :tel, :tok, :z, :prip)'
    );
    $ins->execute([':t' => $terminId, ':j' => $jmeno, ':e' => $email, ':tel' => $telefon,
                   ':tok' => $token, ':z' => $zdroj, ':prip' => $pozdni ? 1 : 0]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();

    return [
        'ok'    => true,
        'chyba' => '',
        'kod'   => 200,
        'rezervace' => ['id' => $id, 'jmeno' => $jmeno, 'email' => $email,
                        'telefon' => $telefon, 'token' => $token, 'pozdni' => $pozdni],
        'termin' => $termin,
    ];
}

/** Budoucí zveřejněné pravidelné skupinové lekce, od nejbližší. */
function budouci_lekce(PDO $pdo): array
{
    $s = $pdo->prepare(
        "SELECT t.*, (SELECT COUNT(*) FROM rezervace r WHERE r.termin_id = t.id) AS obsazeno
         FROM terminy t
         WHERE t.zverejnit = 1 AND t.typ = 'lekce' AND t.datum >= :dnes
         ORDER BY t.datum, t.cas_od"
    );
    $s->execute([':dnes' => date('Y-m-d')]);

    return $s->fetchAll();
}

/**
 * Zapíše (nebo probudí) odběratele novinek o skupinových lekcích.
 * Nikam ho nepřihlašuje — jen zajistí, že dostane e-mail o nových termínech.
 */
function zapis_odberatele(PDO $pdo, string $jmeno, string $email, string $telefon = ''): array
{
    $s = $pdo->prepare('SELECT * FROM trvale_prihlasky WHERE email = :e COLLATE NOCASE');
    $s->execute([':e' => $email]);
    $prihlaska = $s->fetch();

    if ($prihlaska) {
        $token = $prihlaska['token'] !== '' ? $prihlaska['token'] : novy_token();
        $u = $pdo->prepare('UPDATE trvale_prihlasky SET jmeno=:j, telefon=:tel, token=:tok, aktivni=1 WHERE id=:id');
        $u->execute([':j' => $jmeno, ':tel' => $telefon, ':tok' => $token, ':id' => $prihlaska['id']]);

        return ['id' => (int) $prihlaska['id'], 'jmeno' => $jmeno, 'email' => $prihlaska['email'],
                'telefon' => $telefon, 'token' => $token, 'aktivni' => 1];
    }

    $token = novy_token();
    $i = $pdo->prepare('INSERT INTO trvale_prihlasky (jmeno, email, telefon, token) VALUES (:j, :e, :tel, :tok)');
    $i->execute([':j' => $jmeno, ':e' => $email, ':tel' => $telefon, ':tok' => $token]);

    return ['id' => (int) $pdo->lastInsertId(), 'jmeno' => $jmeno, 'email' => $email,
            'telefon' => $telefon, 'token' => $token, 'aktivni' => 1];
}

/**
 * Přihlásí člověka na vybrané skupinové lekce (zaškrtnuté na stránce výběru)
 * a zapíše ho mezi odběratele novinek.
 *
 * @param int[] $terminIds
 * @return array{prihlaska:array,pridane:array,plne:int}
 */
function prihlas_na_vybrane(PDO $pdo, string $jmeno, string $email, string $telefon, array $terminIds): array
{
    $prihlaska = zapis_odberatele($pdo, $jmeno, $email, $telefon);

    // Bereme jen skutečné budoucí skupinové lekce — co přišlo v POSTu, se nepočítá.
    $lekce = [];
    foreach (budouci_lekce($pdo) as $l) {
        $lekce[(int) $l['id']] = $l;
    }

    $pridane = [];
    $plne = 0;
    foreach (array_unique(array_map('intval', $terminIds)) as $id) {
        if (!isset($lekce[$id])) { continue; }
        $vysledek = vytvor_rezervaci($pdo, $id, $jmeno, $email, $telefon, 'trvala');
        if ($vysledek['ok']) {
            $pridane[] = ['token' => $vysledek['rezervace']['token']] + $lekce[$id];
        } elseif ($vysledek['kod'] === 409 && strpos($vysledek['chyba'], 'obsazen') !== false) {
            $plne++;
        }
    }

    return ['prihlaska' => $prihlaska, 'pridane' => $pridane, 'plne' => $plne];
}

/**
 * Termíny skupinových lekcí, o kterých odběratelé ještě nedostali e-mail.
 * Vrací pole termínů a čas posledního přidání (UTC, jako v SQLite).
 */
function cekajici_terminy(PDO $pdo): array
{
    $s = $pdo->prepare(
        "SELECT * FROM terminy
         WHERE oznameno = 0 AND zverejnit = 1 AND typ = 'lekce' AND datum >= :dnes
         ORDER BY datum, cas_od"
    );
    $s->execute([':dnes' => date('Y-m-d')]);
    $terminy = $s->fetchAll();

    $posledni = '';
    foreach ($terminy as $t) {
        if ($t['vytvoreno'] > $posledni) { $posledni = $t['vytvoreno']; }
    }

    return ['terminy' => $terminy, 'posledni' => $posledni];
}

/**
 * Rozešle odběratelům oznámení o nově vypsaných termínech.
 *
 * Čeká se, až od posledního přidaného termínu uplyne PAUZA_SOUHRNU minut —
 * lektorka tak může vypsat deset termínů po sobě a lidem přijde jeden e-mail
 * se všemi. Parametrem $hned se čekání přeskočí (tlačítko v administraci).
 */
function odesli_cekajici_souhrny(PDO $pdo, bool $hned = false): int
{
    // Rychlá zkratka: většinou nic nečeká a nemá smysl skládat celý přehled.
    $ceka = cekajici_terminy($pdo);
    if (!$ceka['terminy']) {
        return 0;
    }

    // SQLite ukládá vytvoreno v UTC, hranici tedy počítáme také v UTC.
    $hranice = gmdate('Y-m-d H:i:s', time() - PAUZA_SOUHRNU * 60);
    if (!$hned && $ceka['posledni'] > $hranice) {
        return 0;
    }

    // Označíme dřív, než odešleme — ať souběžné požadavky nepošlou e-maily dvakrát.
    $idcka = array_map(function (array $t) { return (int) $t['id']; }, $ceka['terminy']);
    $u = $pdo->prepare('UPDATE terminy SET oznameno = 1 WHERE id IN ('
        . implode(',', array_fill(0, count($idcka), '?')) . ') AND oznameno = 0');
    $u->execute($idcka);
    if ($u->rowCount() === 0) {
        return 0;
    }

    $odeslano = 0;
    foreach ($pdo->query('SELECT * FROM trvale_prihlasky WHERE aktivni = 1 ORDER BY vytvoreno') as $p) {
        if (email_nove_terminy($p, $ceka['terminy'])) {
            $odeslano++;
        }
    }

    return $odeslano;
}

/**
 * Rozešle připomínky den před akcí (po PRIPOMINKA_CAS), všem přihlášeným
 * a pro všechny druhy akcí. Kdyby na web den předem nikdo nepřišel, dohoní
 * se připomínka ráno v den akce. Každá rezervace dostane e-mail jen jednou.
 */
function odesli_pripominky(PDO $pdo): int
{
    $zitra = date('Y-m-d', strtotime('+1 day'));
    $dnes = date('Y-m-d');

    // Den předem se čeká na PRIPOMINKA_CAS; v den akce se posílá hned.
    $datumy = [$dnes];
    if (date('H:i') >= PRIPOMINKA_CAS) {
        $datumy[] = $zitra;
    }

    $s = $pdo->prepare(
        "SELECT r.*, t.datum, t.cas_od, t.cas_do, t.misto, t.adresa, t.typ,
                t.poznamka AS termin_poznamka
         FROM rezervace r JOIN terminy t ON t.id = r.termin_id
         WHERE r.pripominka = 0 AND t.zverejnit = 1
           AND t.datum IN (" . implode(',', array_fill(0, count($datumy), '?')) . ')'
    );
    $s->execute($datumy);

    $odeslano = 0;
    foreach ($s->fetchAll() as $r) {
        // Označíme dřív, než odešleme — ať souběžné požadavky neposílají dvakrát.
        $u = $pdo->prepare('UPDATE rezervace SET pripominka = 1 WHERE id = :id AND pripominka = 0');
        $u->execute([':id' => $r['id']]);
        if ($u->rowCount() === 0) { continue; }

        $termin = [
            'datum' => $r['datum'], 'cas_od' => $r['cas_od'], 'cas_do' => $r['cas_do'],
            'misto' => $r['misto'], 'adresa' => $r['adresa'], 'typ' => $r['typ'],
            'poznamka' => $r['termin_poznamka'],
        ];
        if (email_pripominka($r, $termin)) {
            $odeslano++;
        }
    }

    return $odeslano;
}
