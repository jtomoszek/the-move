<?php
/**
 * THE MOVE :: přihlašování do administrace.
 *
 * Každý má vlastní účet (e-mail + heslo). Kdo zaškrtne „zůstat přihlášen",
 * dostane dlouhodobou cookie, takže se na telefonu nemusí hlásit pořád dokola.
 *
 * Cookie má tvar „selektor:ověřovač". V databázi je uložený jen otisk
 * ověřovače, takže z ukradené databáze se přihlašovací cookie sestavit nedá.
 * Ověřovač se při každém použití vymění za nový.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PRIHLASENI_COOKIE = 'themove_admin';
const PRIHLASENI_DNU = 90;          // jak dlouho cookie platí
const SESSION_NECINNOST = 43200;    // 12 h, než vyprší běžná session

/** Spustí session s rozumně dlouhou platností. */
function spust_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }

    // Výchozí PHP vyhazuje po 24 minutách nečinnosti, což je pro práci málo.
    ini_set('session.gc_maxlifetime', (string) SESSION_NECINNOST);

    session_set_cookie_params([
        'lifetime' => SESSION_NECINNOST,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/** Uživatel podle e-mailu; jen aktivní účty. */
function uzivatel_podle_emailu(PDO $pdo, string $email): ?array
{
    $s = $pdo->prepare('SELECT * FROM uzivatele WHERE email = :e COLLATE NOCASE AND aktivni = 1');
    $s->execute([':e' => $email]);

    return $s->fetch() ?: null;
}

function uzivatel_podle_id(PDO $pdo, int $id): ?array
{
    $s = $pdo->prepare('SELECT * FROM uzivatele WHERE id = :id AND aktivni = 1');
    $s->execute([':id' => $id]);

    return $s->fetch() ?: null;
}

/** Ověří e-mail a heslo. Vrací uživatele, nebo null. */
function over_heslo(PDO $pdo, string $email, string $heslo): ?array
{
    $u = uzivatel_podle_emailu($pdo, $email);
    if (!$u || !password_verify($heslo, (string) $u['heslo'])) {
        return null;
    }

    // Otisk se časem zastará, při přihlášení ho tiše obnovíme.
    if (password_needs_rehash((string) $u['heslo'], PASSWORD_DEFAULT)) {
        $n = $pdo->prepare('UPDATE uzivatele SET heslo = :h WHERE id = :id');
        $n->execute([':h' => password_hash($heslo, PASSWORD_DEFAULT), ':id' => $u['id']]);
    }

    return $u;
}

/** Zapíše uživatele do session (po úspěšném přihlášení). */
function prihlas_uzivatele(PDO $pdo, array $uzivatel): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['uzivatel_id'] = (int) $uzivatel['id'];

    $s = $pdo->prepare("UPDATE uzivatele SET prihlasen = datetime('now') WHERE id = :id");
    $s->execute([':id' => $uzivatel['id']]);
}

/** Krátký popis zařízení, ať je v přehledu poznat, odkud je kdo přihlášený. */
function popis_zarizeni(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $system = 'Neznámé zařízení';
    foreach ([
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
        'Macintosh' => 'Mac', 'Windows' => 'Windows', 'Linux' => 'Linux',
    ] as $jehla => $nazev) {
        if (stripos($ua, $jehla) !== false) { $system = $nazev; break; }
    }

    $prohlizec = '';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
              'Safari' => 'Safari', 'Firefox' => 'Firefox'] as $jehla => $nazev) {
        if (stripos($ua, $jehla) !== false) { $prohlizec = ' · ' . $nazev; break; }
    }

    return $system . $prohlizec;
}

/** Vystaví dlouhodobou přihlašovací cookie. */
function zapamatuj_prihlaseni(PDO $pdo, int $uzivatelId): void
{
    $selektor = bin2hex(random_bytes(9));
    $overovac = bin2hex(random_bytes(32));
    $platnost = gmdate('Y-m-d H:i:s', time() + PRIHLASENI_DNU * 86400);

    $s = $pdo->prepare(
        'INSERT INTO prihlaseni (uzivatel_id, selektor, overovac, zarizeni, platnost)
         VALUES (:u, :s, :o, :z, :p)'
    );
    $s->execute([
        ':u' => $uzivatelId, ':s' => $selektor,
        ':o' => hash('sha256', $overovac),
        ':z' => popis_zarizeni(), ':p' => $platnost,
    ]);

    posli_cookie($selektor . ':' . $overovac, time() + PRIHLASENI_DNU * 86400);
}

function posli_cookie(string $hodnota, int $expirace): void
{
    setcookie(PRIHLASENI_COOKIE, $hodnota, [
        'expires'  => $expirace,
        'path'     => '/admin/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

/**
 * Zkusí přihlásit podle dlouhodobé cookie. Vrací true, když se to povedlo.
 * Ověřovač se vymění za nový — ukradená starší cookie tak přestane platit.
 */
function over_trvale_prihlaseni(PDO $pdo): bool
{
    $cookie = (string) ($_COOKIE[PRIHLASENI_COOKIE] ?? '');
    if (strpos($cookie, ':') === false) { return false; }

    [$selektor, $overovac] = explode(':', $cookie, 2);

    $s = $pdo->prepare('SELECT * FROM prihlaseni WHERE selektor = :s');
    $s->execute([':s' => $selektor]);
    $zaznam = $s->fetch();

    if (!$zaznam || $zaznam['platnost'] < gmdate('Y-m-d H:i:s')) {
        zapomen_prihlaseni($pdo);
        return false;
    }

    if (!hash_equals((string) $zaznam['overovac'], hash('sha256', $overovac))) {
        // Nesedící ověřovač bere jako pokus o zneužití — řadu zneplatníme.
        $d = $pdo->prepare('DELETE FROM prihlaseni WHERE uzivatel_id = :u');
        $d->execute([':u' => $zaznam['uzivatel_id']]);
        posli_cookie('', time() - 3600);
        return false;
    }

    $uzivatel = uzivatel_podle_id($pdo, (int) $zaznam['uzivatel_id']);
    if (!$uzivatel) {
        zapomen_prihlaseni($pdo);
        return false;
    }

    // Rotace ověřovače + prodloužení platnosti.
    $novy = bin2hex(random_bytes(32));
    $platnost = gmdate('Y-m-d H:i:s', time() + PRIHLASENI_DNU * 86400);
    $u = $pdo->prepare(
        "UPDATE prihlaseni SET overovac = :o, platnost = :p, pouzito = datetime('now')
         WHERE id = :id"
    );
    $u->execute([':o' => hash('sha256', $novy), ':p' => $platnost, ':id' => $zaznam['id']]);
    posli_cookie($selektor . ':' . $novy, time() + PRIHLASENI_DNU * 86400);

    prihlas_uzivatele($pdo, $uzivatel);

    return true;
}

/** Zruší dlouhodobé přihlášení tohoto zařízení. */
function zapomen_prihlaseni(PDO $pdo): void
{
    $cookie = (string) ($_COOKIE[PRIHLASENI_COOKIE] ?? '');
    if (strpos($cookie, ':') !== false) {
        [$selektor] = explode(':', $cookie, 2);
        $d = $pdo->prepare('DELETE FROM prihlaseni WHERE selektor = :s');
        $d->execute([':s' => $selektor]);
    }
    posli_cookie('', time() - 3600);
}

/** Smaže prošlé záznamy, ať tabulka nebobtná. */
function uklid_prihlaseni(PDO $pdo): void
{
    $pdo->prepare('DELETE FROM prihlaseni WHERE platnost < :ted')
        ->execute([':ted' => gmdate('Y-m-d H:i:s')]);
}

/**
 * Zajistí přihlášeného uživatele. Když chybí session, zkusí dlouhodobou
 * cookie. Vrací uživatele, nebo null (pak patří návštěvník na přihlášení).
 */
function prihlaseny_uzivatel(PDO $pdo): ?array
{
    if (!empty($_SESSION['uzivatel_id'])) {
        $u = uzivatel_podle_id($pdo, (int) $_SESSION['uzivatel_id']);
        if ($u) { return $u; }
        // Účet mezitím zmizel nebo byl vypnutý.
        $_SESSION = [];
    }

    if (over_trvale_prihlaseni($pdo) && !empty($_SESSION['uzivatel_id'])) {
        return uzivatel_podle_id($pdo, (int) $_SESSION['uzivatel_id']);
    }

    return null;
}
