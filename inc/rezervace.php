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

    $token = novy_token();
    $ins = $pdo->prepare(
        'INSERT INTO rezervace (termin_id, jmeno, email, telefon, token, zdroj)
         VALUES (:t, :j, :e, :tel, :tok, :z)'
    );
    $ins->execute([':t' => $terminId, ':j' => $jmeno, ':e' => $email,
                   ':tel' => $telefon, ':tok' => $token, ':z' => $zdroj]);
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
 * Přihlásí na nově vypsanou skupinovou lekci všechny, kdo chodí pravidelně,
 * a pošle jim potvrzení. Vrací počet přidaných lidí.
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
        $vysledek = vytvor_rezervaci($pdo, $terminId, $p['jmeno'], $p['email'], $p['telefon'], 'trvala');
        if ($vysledek['ok']) {
            $pridano++;
            email_potvrzeni($vysledek['rezervace'], $vysledek['termin']);
        }
    }

    return $pridano;
}
