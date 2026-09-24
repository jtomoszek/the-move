<?php
/**
 * THE MOVE :: kartotéka klientů.
 *
 * Klient = jedna e-mailová adresa. Záznam vzniká sám při rezervaci,
 * docházka se dopočítává z rezervací, takže se nikde nedubluje.
 *
 * Pozn. k docházce: evidujeme rezervace, ne fyzickou přítomnost. „Absolvoval"
 * proto znamená „měl rezervaci na termín, který už proběhl".
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Založí nebo aktualizuje klienta podle e-mailu.
 * Jméno a telefon se přepíšou tím, co člověk vyplnil naposledy.
 * Odhlášení z novinek zůstává — rezervace ho nepřebíjí.
 */
function zapis_klienta(PDO $pdo, string $jmeno, string $email, string $telefon = '',
                       ?bool $novinky = null): array
{
    $s = $pdo->prepare('SELECT * FROM klienti WHERE email = :e COLLATE NOCASE');
    $s->execute([':e' => $email]);
    $klient = $s->fetch();

    if ($klient) {
        $u = $pdo->prepare(
            'UPDATE klienti SET jmeno = :j, telefon = CASE WHEN :t != \'\' THEN :t ELSE telefon END
             WHERE id = :id'
        );
        $u->execute([':j' => $jmeno, ':t' => $telefon, ':id' => $klient['id']]);

        // Kdo se jednou odhlásil, zůstane odhlášený. Políčko v rezervaci je
        // předzaškrtnuté, takže by ho nová rezervace vrátila zpět, aniž by si
        // toho všiml — zpět ho zařadí jen lektorka v administraci na požádání.
        if ($novinky === false && (int) $klient['novinky'] === 1) {
            odhlas_z_novinek($pdo, (string) $klient['token']);
        }

        $s->execute([':e' => $email]);
        return $s->fetch() ?: $klient;
    }

    $i = $pdo->prepare(
        'INSERT INTO klienti (email, jmeno, telefon, token, novinky)
         VALUES (:e, :j, :t, :tok, :n)'
    );
    $i->execute([
        ':e' => $email, ':j' => $jmeno, ':t' => $telefon,
        ':tok' => novy_token(), ':n' => $novinky === false ? 0 : 1,
    ]);

    $s->execute([':e' => $email]);
    return $s->fetch();
}

/** Odhlásí klienta z novinek. Vrací true, když se někdo opravdu odhlásil. */
function odhlas_z_novinek(PDO $pdo, string $token): bool
{
    $u = $pdo->prepare(
        "UPDATE klienti SET novinky = 0, odhlaseno = datetime('now')
         WHERE token = :tok AND novinky = 1"
    );
    $u->execute([':tok' => $token]);

    return $u->rowCount() > 0;
}

/** Klient podle tokenu z odhlašovacího odkazu. */
function klient_podle_tokenu(PDO $pdo, string $token): ?array
{
    $s = $pdo->prepare('SELECT * FROM klienti WHERE token = :tok');
    $s->execute([':tok' => $token]);

    return $s->fetch() ?: null;
}

/**
 * Přehled klientů i s docházkou.
 *
 * @param string $hledat  část jména, e-mailu nebo telefonu
 * @param string $razeni  posledni | prvni | nejvic | jmeno
 */
function klienti_prehled(PDO $pdo, string $hledat = '', string $razeni = 'posledni',
                         ?int $jenId = null): array
{
    $dnes = date('Y-m-d');

    $sql =
        "SELECT k.*,
                (SELECT COUNT(*) FROM rezervace r JOIN terminy t ON t.id = r.termin_id
                 WHERE r.email = k.email COLLATE NOCASE AND t.datum < :dnes)   AS absolvoval,
                (SELECT COUNT(*) FROM rezervace r JOIN terminy t ON t.id = r.termin_id
                 WHERE r.email = k.email COLLATE NOCASE AND t.datum >= :dnes)  AS prihlasen,
                (SELECT MIN(t.datum) FROM rezervace r JOIN terminy t ON t.id = r.termin_id
                 WHERE r.email = k.email COLLATE NOCASE AND t.datum < :dnes)   AS prvni_navsteva,
                (SELECT MAX(t.datum) FROM rezervace r JOIN terminy t ON t.id = r.termin_id
                 WHERE r.email = k.email COLLATE NOCASE AND t.datum < :dnes)   AS posledni_navsteva,
                (SELECT MIN(t.datum) FROM rezervace r JOIN terminy t ON t.id = r.termin_id
                 WHERE r.email = k.email COLLATE NOCASE AND t.datum >= :dnes)  AS nejblizsi,
                EXISTS (SELECT 1 FROM trvale_prihlasky p
                        WHERE p.email = k.email COLLATE NOCASE AND p.aktivni = 1) AS pravidelny
         FROM klienti k";

    $parametry = [':dnes' => $dnes];
    if ($jenId !== null) {
        $sql .= ' WHERE k.id = :id';
        $parametry[':id'] = $jenId;
    } elseif (trim($hledat) !== '') {
        $sql .= ' WHERE k.jmeno LIKE :h OR k.email LIKE :h OR k.telefon LIKE :h';
        $parametry[':h'] = '%' . trim($hledat) . '%';
    }

    // „IS NULL" místo NULLS LAST — to starší SQLite na hostingu nemusí znát.
    $razeniSql = [
        'posledni' => 'posledni_navsteva IS NULL, posledni_navsteva DESC, k.vytvoreno DESC',
        'prvni'    => 'k.vytvoreno ASC',
        'nejvic'   => 'absolvoval DESC, k.jmeno COLLATE NOCASE',
        'jmeno'    => 'k.jmeno COLLATE NOCASE',
    ];
    $sql .= ' ORDER BY ' . ($razeniSql[$razeni] ?? $razeniSql['posledni']);

    $s = $pdo->prepare($sql);
    $s->execute($parametry);

    return $s->fetchAll();
}

/** Jeden klient i s docházkou (stejná čísla jako v přehledu). */
function klient_detail(PDO $pdo, int $id): ?array
{
    $nalezeni = klienti_prehled($pdo, '', 'posledni', $id);

    return $nalezeni[0] ?? null;
}

/** Všechny rezervace klienta, od nejnovějšího termínu. */
function klient_rezervace(PDO $pdo, string $email): array
{
    $s = $pdo->prepare(
        'SELECT r.id, r.token, r.zdroj, r.vytvoreno,
                t.datum, t.cas_od, t.cas_do, t.misto, t.typ, t.cena
         FROM rezervace r JOIN terminy t ON t.id = r.termin_id
         WHERE r.email = :e COLLATE NOCASE
         ORDER BY t.datum DESC, t.cas_od DESC'
    );
    $s->execute([':e' => $email]);

    return $s->fetchAll();
}

/** Souhrnná čísla nad celou kartotékou. */
function klienti_statistiky(PDO $pdo): array
{
    $dnes = date('Y-m-d');
    $pred30 = date('Y-m-d', strtotime('-30 days'));

    $jedno = function (string $sql, array $p = []) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    };

    $celkem = $jedno('SELECT COUNT(*) FROM klienti');
    $odebiraji = $jedno('SELECT COUNT(*) FROM klienti WHERE novinky = 1');

    return [
        'celkem'     => $celkem,
        'novi30'     => $jedno("SELECT COUNT(*) FROM klienti WHERE vytvoreno >= :d", [':d' => $pred30]),
        'odebiraji'  => $odebiraji,
        'odhlaseni'  => $celkem - $odebiraji,
        'pravidelni' => $jedno('SELECT COUNT(*) FROM trvale_prihlasky WHERE aktivni = 1'),
        'rezervaci'  => $jedno('SELECT COUNT(*) FROM rezervace'),
        'absolvovanych' => $jedno(
            'SELECT COUNT(*) FROM rezervace r JOIN terminy t ON t.id = r.termin_id WHERE t.datum < :d',
            [':d' => $dnes]
        ),
        'nadchazejicich' => $jedno(
            'SELECT COUNT(*) FROM rezervace r JOIN terminy t ON t.id = r.termin_id WHERE t.datum >= :d',
            [':d' => $dnes]
        ),
        // Kdo byl aspoň jednou za poslední tři měsíce.
        'aktivni' => $jedno(
            'SELECT COUNT(DISTINCT r.email COLLATE NOCASE)
             FROM rezervace r JOIN terminy t ON t.id = r.termin_id
             WHERE t.datum >= :od AND t.datum < :dnes',
            [':od' => date('Y-m-d', strtotime('-3 months')), ':dnes' => $dnes]
        ),
    ];
}

/** Příjemci newsletteru — jen ti, kdo se neodhlásili. */
function prijemci_novinek(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, jmeno, email, token FROM klienti
         WHERE novinky = 1 AND email != \'\'
         ORDER BY jmeno COLLATE NOCASE'
    )->fetchAll();
}
