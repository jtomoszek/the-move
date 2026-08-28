<?php
/**
 * THE MOVE :: sdílené připojení k SQLite databázi.
 * Databáze se vytvoří automaticky při prvním použití.
 */

declare(strict_types=1);

// Ať se datumy a časy počítají v českém čase bez ohledu na nastavení hostingu.
// SQLite ukládá vytvoreno v UTC, tam se proto pracuje s gmdate().
date_default_timezone_set('Europe/Prague');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $dir . '/themove.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec("CREATE TABLE IF NOT EXISTS nastaveni (
            klic    TEXT PRIMARY KEY,
            hodnota TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS terminy (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            datum     TEXT    NOT NULL,              -- YYYY-MM-DD
            cas_od    TEXT    NOT NULL,              -- HH:MM
            cas_do    TEXT    NOT NULL,              -- HH:MM
            misto     TEXT    NOT NULL,
            typ       TEXT    NOT NULL DEFAULT 'lekce',
            cena      TEXT    NOT NULL DEFAULT '',
            kapacita  INTEGER NOT NULL DEFAULT 8,
            poznamka  TEXT    NOT NULL DEFAULT '',
            zverejnit INTEGER NOT NULL DEFAULT 1,
            oznameno  INTEGER NOT NULL DEFAULT 1,
            vytvoreno TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rezervace (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            termin_id INTEGER NOT NULL REFERENCES terminy(id) ON DELETE CASCADE,
            jmeno     TEXT    NOT NULL,
            email     TEXT    NOT NULL,
            telefon   TEXT    NOT NULL DEFAULT '',
            poznamka  TEXT    NOT NULL DEFAULT '',
            token     TEXT    NOT NULL DEFAULT '',
            zdroj     TEXT    NOT NULL DEFAULT 'web',
            oznameno  INTEGER NOT NULL DEFAULT 1,
            pripominka INTEGER NOT NULL DEFAULT 0,
            vytvoreno TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        // Trvalá přihláška: člověk chodí na pravidelné skupinové lekce a každou
        // nově vypsanou mu systém přidá sám.
        $pdo->exec("CREATE TABLE IF NOT EXISTS trvale_prihlasky (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            jmeno     TEXT    NOT NULL,
            email     TEXT    NOT NULL,
            telefon   TEXT    NOT NULL DEFAULT '',
            token     TEXT    NOT NULL DEFAULT '',
            aktivni   INTEGER NOT NULL DEFAULT 1,
            vytvoreno TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        // Starší databáze nové sloupce nemají, doplníme je.
        // Stávající termíny zůstanou jako skupinové lekce.
        doplnit_sloupec($pdo, 'terminy', 'typ', "TEXT NOT NULL DEFAULT 'lekce'");
        doplnit_sloupec($pdo, 'terminy', 'adresa', "TEXT NOT NULL DEFAULT ''");
        doplnit_sloupec($pdo, 'terminy', 'cena', "TEXT NOT NULL DEFAULT ''");
        // 0 = pravidelní účastníci o tomto termínu ještě nedostali e-mail
        doplnit_sloupec($pdo, 'terminy', 'oznameno', 'INTEGER NOT NULL DEFAULT 1');
        doplnit_sloupec($pdo, 'rezervace', 'token', "TEXT NOT NULL DEFAULT ''");
        doplnit_sloupec($pdo, 'rezervace', 'zdroj', "TEXT NOT NULL DEFAULT 'web'");
        // 0 = čeká na souhrnný e-mail o nových termínech (už se nepoužívá)
        doplnit_sloupec($pdo, 'rezervace', 'oznameno', 'INTEGER NOT NULL DEFAULT 1');
        // 0 = den před akcí se má poslat připomínkový e-mail
        doplnit_sloupec($pdo, 'rezervace', 'pripominka', 'INTEGER NOT NULL DEFAULT 0');

        // Rezervace založené před zavedením e-mailů token nemají; bez něj by
        // odkaz na zrušení v případném pozdějším e-mailu nefungoval.
        foreach ($pdo->query("SELECT id FROM rezervace WHERE token = ''") as $r) {
            $u = $pdo->prepare('UPDATE rezervace SET token = :t WHERE id = :id');
            $u->execute([':t' => novy_token(), ':id' => $r['id']]);
        }
    }

    return $pdo;
}

/** Přidá sloupec do tabulky, pokud v ní ještě není. */
function doplnit_sloupec(PDO $pdo, string $tabulka, string $sloupec, string $definice): void
{
    foreach ($pdo->query("PRAGMA table_info({$tabulka})") as $s) {
        if ($s['name'] === $sloupec) { return; }
    }
    $pdo->exec("ALTER TABLE {$tabulka} ADD COLUMN {$sloupec} {$definice}");
}

/** Náhodný token do odkazů v e-mailech (zrušení rezervace apod.). */
function novy_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Základní adresa webu — do absolutních odkazů v e-mailech. */
function zakladni_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    // Na vývojovém serveru odkazujeme na localhost, jinak vždy na ostrý web.
    if (preg_match('~^(localhost|127\.0\.0\.1)(:\d+)?$~', $host)) {
        return 'http://' . $host;
    }

    return 'https://themove.cz';
}

/**
 * Druhy událostí. Klíč se ukládá do databáze, hodnota se zobrazuje.
 * Pořadí určuje i pořadí filtrů na webu.
 */
function typy_udalosti(): array
{
    return [
        'lekce'    => 'Skupinová lekce',
        'workshop' => 'Workshop',
        'seminar'  => 'Seminář',
    ];
}

/** Název druhu události; u neznámé hodnoty se vrátí skupinová lekce. */
function nazev_typu(?string $typ): string
{
    $typy = typy_udalosti();
    return $typy[(string) $typ] ?? $typy['lekce'];
}

/** Ověří, že druh události existuje, jinak vrátí výchozí. */
function overeny_typ(?string $typ): string
{
    return isset(typy_udalosti()[(string) $typ]) ? (string) $typ : 'lekce';
}

/** Český název dne v týdnu pro datum YYYY-MM-DD. */
function cesky_den(string $datum): string
{
    $dny = [1 => 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];
    $n = (int) date('N', strtotime($datum));
    return $dny[$n] ?? '';
}

/** Datum YYYY-MM-DD → „8. 9. 2026". */
function ceske_datum(string $datum): string
{
    $t = strtotime($datum);
    return date('j', $t) . '. ' . date('n', $t) . '. ' . date('Y', $t);
}

/** Datum YYYY-MM-DD → „pondělí 8. září" (do e-mailů). */
function datum_slovy(string $datum): string
{
    $mesice = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
               'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
    $t = strtotime($datum);
    $den = mb_strtolower(cesky_den($datum), 'UTF-8');

    return $den . ' ' . date('j', $t) . '. ' . $mesice[(int) date('n', $t)];
}

/** Délka termínu v minutách podle času od–do. */
function delka_minut(string $casOd, string $casDo): int
{
    $od = strtotime('2000-01-01 ' . $casOd);
    $do = strtotime('2000-01-01 ' . $casDo);
    if ($od === false || $do === false || $do <= $od) { return 0; }

    return (int) round(($do - $od) / 60);
}
