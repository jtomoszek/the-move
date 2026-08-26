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
                          string $telefon = '', string $zdroj = 'web', int $oznameno = 1): array
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

    $token = novy_token();
    $ins = $pdo->prepare(
        'INSERT INTO rezervace (termin_id, jmeno, email, telefon, token, zdroj, oznameno)
         VALUES (:t, :j, :e, :tel, :tok, :z, :o)'
    );
    $ins->execute([':t' => $terminId, ':j' => $jmeno, ':e' => $email, ':tel' => $telefon,
                   ':tok' => $token, ':z' => $zdroj, ':o' => $oznameno]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();

    return [
        'ok'    => true,
        'chyba' => '',
        'kod'   => 200,
        'rezervace' => ['id' => $id, 'jmeno' => $jmeno, 'email' => $email,
                        'telefon' => $telefon, 'token' => $token],
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
 * Zapne trvalou přihlášku a přihlásí člověka na všechny vypsané skupinové lekce.
 *
 * @return array{prihlaska:array,pridane:array,jiz_prihlasen:int}
 */
function prihlas_natrvalo(PDO $pdo, string $jmeno, string $email, string $telefon = ''): array
{
    $s = $pdo->prepare('SELECT * FROM trvale_prihlasky WHERE email = :e COLLATE NOCASE');
    $s->execute([':e' => $email]);
    $prihlaska = $s->fetch();

    if ($prihlaska) {
        $token = $prihlaska['token'] !== '' ? $prihlaska['token'] : novy_token();
        $u = $pdo->prepare('UPDATE trvale_prihlasky SET jmeno=:j, telefon=:tel, token=:tok, aktivni=1 WHERE id=:id');
        $u->execute([':j' => $jmeno, ':tel' => $telefon, ':tok' => $token, ':id' => $prihlaska['id']]);
        $prihlaska = ['id' => (int) $prihlaska['id'], 'jmeno' => $jmeno, 'email' => $prihlaska['email'],
                      'telefon' => $telefon, 'token' => $token];
    } else {
        $token = novy_token();
        $i = $pdo->prepare('INSERT INTO trvale_prihlasky (jmeno, email, telefon, token) VALUES (:j, :e, :tel, :tok)');
        $i->execute([':j' => $jmeno, ':e' => $email, ':tel' => $telefon, ':tok' => $token]);
        $prihlaska = ['id' => (int) $pdo->lastInsertId(), 'jmeno' => $jmeno, 'email' => $email,
                      'telefon' => $telefon, 'token' => $token];
    }

    $pridane = [];
    $plne = 0;
    foreach (budouci_lekce($pdo) as $lekce) {
        $vysledek = vytvor_rezervaci($pdo, (int) $lekce['id'], $jmeno, $email, $telefon, 'trvala');
        if ($vysledek['ok'] || $vysledek['kod'] === 409) {
            // Už přihlášený člověk v seznamu zůstává, obsazená lekce ne.
            if ($vysledek['ok'] || strpos($vysledek['chyba'], 'již přihlášeni') !== false) {
                $pridane[] = $lekce;
                continue;
            }
            $plne++;
        }
    }

    return ['prihlaska' => $prihlaska, 'pridane' => $pridane, 'plne' => $plne];
}

/**
 * Přihlásí na nově vypsanou skupinovou lekci všechny, kdo chodí pravidelně.
 * E-mail se neposílá hned — počká se, až lektorka dovypisuje ostatní termíny,
 * a pak jde jeden souhrn (viz odesli_cekajici_souhrny). Vrací počet přidaných.
 */
function doplnit_trvale_na_termin(PDO $pdo, int $terminId): int
{
    $t = $pdo->prepare('SELECT * FROM terminy WHERE id = :id');
    $t->execute([':id' => $terminId]);
    $termin = $t->fetch();

    if (!$termin || (int) $termin['zverejnit'] !== 1
        || overeny_typ($termin['typ'] ?? null) !== 'lekce'
        || $termin['datum'] < date('Y-m-d')) {
        return 0;
    }

    $pridano = 0;
    foreach ($pdo->query('SELECT * FROM trvale_prihlasky WHERE aktivni = 1 ORDER BY vytvoreno') as $p) {
        $vysledek = vytvor_rezervaci($pdo, $terminId, $p['jmeno'], $p['email'], $p['telefon'], 'trvala', 0);
        if ($vysledek['ok']) {
            $pridano++;
        }
    }

    return $pridano;
}

/**
 * Rezervace čekající na souhrnný e-mail, seskupené po lidech.
 * Vrací pole [email => ['jmeno' => …, 'terminy' => [...], 'posledni' => 'Y-m-d H:i:s']].
 */
function cekajici_souhrny(PDO $pdo): array
{
    $s = $pdo->query(
        "SELECT r.id, r.jmeno, r.email, r.token, r.vytvoreno,
                t.datum, t.cas_od, t.cas_do, t.misto, t.typ
         FROM rezervace r JOIN terminy t ON t.id = r.termin_id
         WHERE r.oznameno = 0 AND r.zdroj = 'trvala'
         ORDER BY r.email COLLATE NOCASE, t.datum, t.cas_od"
    );

    $skupiny = [];
    foreach ($s as $r) {
        $klic = mb_strtolower($r['email'], 'UTF-8');
        if (!isset($skupiny[$klic])) {
            $skupiny[$klic] = ['jmeno' => $r['jmeno'], 'email' => $r['email'],
                               'terminy' => [], 'posledni' => $r['vytvoreno']];
        }
        $skupiny[$klic]['terminy'][] = $r;
        if ($r['vytvoreno'] > $skupiny[$klic]['posledni']) {
            $skupiny[$klic]['posledni'] = $r['vytvoreno'];
        }
    }

    return $skupiny;
}

/**
 * Rozešle souhrny o nově přidaných termínech.
 *
 * Čeká se, až od posledního přidaného termínu uplyne PAUZA_SOUHRNU minut —
 * lektorka tak může vypsat deset termínů po sobě a lidem přijde jeden e-mail.
 * Parametrem $hned se čekání přeskočí (tlačítko v administraci).
 */
function odesli_cekajici_souhrny(PDO $pdo, bool $hned = false): int
{
    // Rychlá zkratka: většinou nic nečeká a nemá smysl skládat celý přehled.
    if ((int) $pdo->query("SELECT COUNT(*) FROM rezervace WHERE oznameno = 0 AND zdroj = 'trvala'")->fetchColumn() === 0) {
        return 0;
    }

    // SQLite ukládá vytvoreno v UTC, hranici tedy počítáme také v UTC.
    $hranice = gmdate('Y-m-d H:i:s', time() - PAUZA_SOUHRNU * 60);
    $odeslano = 0;

    foreach (cekajici_souhrny($pdo) as $skupina) {
        if (!$hned && $skupina['posledni'] > $hranice) { continue; }

        // Označíme dřív, než odešleme — ať souběžné požadavky nepošlou e-mail dvakrát.
        $idcka = array_map(function (array $r) { return (int) $r['id']; }, $skupina['terminy']);
        $u = $pdo->prepare('UPDATE rezervace SET oznameno = 1 WHERE id IN ('
            . implode(',', array_fill(0, count($idcka), '?')) . ') AND oznameno = 0');
        $u->execute($idcka);
        if ($u->rowCount() === 0) { continue; }

        $p = $pdo->prepare('SELECT * FROM trvale_prihlasky WHERE email = :e COLLATE NOCASE');
        $p->execute([':e' => $skupina['email']]);
        $prihlaska = $p->fetch() ?: ['jmeno' => $skupina['jmeno'], 'email' => $skupina['email'], 'token' => ''];

        email_nove_terminy($prihlaska, $skupina['terminy']);
        $odeslano++;
    }

    return $odeslano;
}
