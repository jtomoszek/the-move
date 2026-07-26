<?php
/**
 * THE MOVE — sdílené připojení k SQLite databázi.
 * Databáze se vytvoří automaticky při prvním použití.
 */

declare(strict_types=1);

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
            kapacita  INTEGER NOT NULL DEFAULT 8,
            poznamka  TEXT    NOT NULL DEFAULT '',
            zverejnit INTEGER NOT NULL DEFAULT 1,
            vytvoreno TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rezervace (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            termin_id INTEGER NOT NULL REFERENCES terminy(id) ON DELETE CASCADE,
            jmeno     TEXT    NOT NULL,
            email     TEXT    NOT NULL,
            telefon   TEXT    NOT NULL DEFAULT '',
            poznamka  TEXT    NOT NULL DEFAULT '',
            vytvoreno TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
    }

    return $pdo;
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
