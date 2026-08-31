<?php
/**
 * THE MOVE :: odesílání transakčních e-mailů.
 *
 * HTML šablona je v inc/maily/zprava.html — je psaná pro e-mailové klienty
 * (tabulky, inline styly, žádné webfonty), takže se její struktura needituje.
 * Ke každému HTML e-mailu se posílá i textová verze.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MAIL_ODESILATEL = 'info@themove.cz';
const MAIL_ODESILATEL_JMENO = 'The Move';
const MAIL_TELEFON = '+420 604 819 067';
const MAIL_LEKTORKA = 'Lenka Schwarzová';
const MAIL_FIRMA = 'The Move s.r.o., Nad Ostravicí 1394/6a, Slezská Ostrava, 710 00 Ostrava';

/**
 * Kolik minut se čeká, než se pravidelným účastníkům rozešle oznámení o nových
 * termínech. Lektorka může vypsat termíny postupně a lidem přijde jeden e-mail.
 */
const PAUZA_SOUHRNU = 60;

/** V kolik hodin se den před akcí rozesílají připomínky (HH:MM). */
const PRIPOMINKA_CAS = '11:05';

/** Ponechá, nebo odstraní blok ohraničený <!--{{#nazev}}--> … <!--{{/nazev}}-->. */
function sablona_blok(string $html, string $nazev, bool $ponechat): string
{
    $vzor = '~<!--\{\{#' . preg_quote($nazev, '~') . '\}\}-->(.*?)<!--\{\{/' . preg_quote($nazev, '~') . '\}\}-->~s';

    return (string) preg_replace_callback($vzor, function (array $m) use ($ponechat) {
        return $ponechat ? $m[1] : '';
    }, $html);
}

/**
 * Sestaví HTML zprávu ze šablony.
 * Klíče začínající „html_" se vkládají bez escapování (hotové kusy HTML).
 */
function sablona_zprava(array $promenne, array $bloky = []): string
{
    $html = (string) file_get_contents(__DIR__ . '/maily/zprava.html');

    foreach ($bloky as $nazev => $ponechat) {
        $html = sablona_blok($html, $nazev, (bool) $ponechat);
    }

    // Bloky, o kterých volající nerozhodl, se z šablony odstraní.
    // Název bloku může obsahovat i číslici (perex2), jinak by blok zůstal.
    $html = (string) preg_replace('~<!--\{\{#[a-z0-9_]+\}\}-->.*?<!--\{\{/[a-z0-9_]+\}\}-->~s', '', $html);

    $nahrady = [];
    foreach ($promenne as $klic => $hodnota) {
        $nahrady['{{' . $klic . '}}'] = strncmp($klic, 'html_', 5) === 0
            ? (string) $hodnota
            : htmlspecialchars((string) $hodnota, ENT_QUOTES, 'UTF-8');
    }
    $html = strtr($html, $nahrady);

    // Pojistka: co se nenahradilo, ať se aspoň nezobrazí příjemci.
    return (string) preg_replace('~\{\{[a-z0-9_]+\}\}~', '', $html);
}

/** Hlavička s diakritikou (jméno odesílatele, předmět). */
function mime_text(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Odešle e-mail v HTML i textové podobě.
 * Na vývojovém serveru se místo odeslání uloží do data/maily/.
 */
function posli_mail(string $komu, string $jmeno, string $predmet, string $html, string $text): bool
{
    $hranice = '=_' . bin2hex(random_bytes(12));

    $telo = "--{$hranice}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--{$hranice}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . "--{$hranice}--";

    $hlavicky = "MIME-Version: 1.0\r\n"
        . 'From: ' . mime_text(MAIL_ODESILATEL_JMENO) . ' <' . MAIL_ODESILATEL . ">\r\n"
        . 'Reply-To: ' . MAIL_ODESILATEL . "\r\n"
        . "Content-Type: multipart/alternative; boundary=\"{$hranice}\"\r\n";

    // Lokální vývoj: e-maily se neodesílají, jen ukládají k nahlédnutí.
    if (strpos(zakladni_url(), 'localhost') !== false || strpos(zakladni_url(), '127.0.0.1') !== false) {
        $dir = __DIR__ . '/../data/maily';
        if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        // Do názvu i mikrosekundy — několik e-mailů v jedné sekundě se jinak přepíše.
        $zaklad = $dir . '/' . date('Ymd-His') . '-' . substr((string) hrtime(true), -6)
            . '-' . preg_replace('~[^a-z0-9]+~i', '-', $komu);
        file_put_contents($zaklad . '.html', $html);
        file_put_contents($zaklad . '.txt', "Komu: {$jmeno} <{$komu}>\nPředmět: {$predmet}\n\n" . $text);
        return true;
    }

    $prijemce = $jmeno !== '' ? mime_text($jmeno) . ' <' . $komu . '>' : $komu;
    $predmetMime = mime_text($predmet);

    // Parametr -f zlepšuje doručitelnost, některé hostingy ho ale nedovolí.
    if (@mail($prijemce, $predmetMime, $telo, $hlavicky, '-f' . MAIL_ODESILATEL)) {
        return true;
    }

    return @mail($prijemce, $predmetMime, $telo, $hlavicky);
}

/** Společné proměnné patičky a hlavičky. */
function mail_zaklad(): array
{
    $web = zakladni_url() . '/';

    return [
        'web_url'               => $web,
        'logo_url'              => $web . 'assets/img/logo-email.png',
        'kontakt_email'         => MAIL_ODESILATEL,
        'kontakt_telefon'       => MAIL_TELEFON,
        'kontakt_telefon_link'  => str_replace(' ', '', MAIL_TELEFON),
        'firma'                 => MAIL_FIRMA,
    ];
}

/** Odkaz na stránku rezervace (zrušení, přihlášení napořád). */
function odkaz_rezervace(string $token, string $akce = ''): string
{
    return zakladni_url() . '/rezervace.php?k=' . urlencode($token)
        . ($akce !== '' ? '&akce=' . urlencode($akce) : '');
}

/** Odkaz na správu trvalé přihlášky. */
function odkaz_trvale(string $token): string
{
    return zakladni_url() . '/rezervace.php?p=' . urlencode($token);
}

/** Jedno řádkové shrnutí termínu do textové verze e-mailu. */
function termin_textem(array $t): string
{
    return datum_slovy($t['datum']) . ' ' . date('Y', (int) strtotime($t['datum']))
        . ', ' . $t['cas_od'] . ' do ' . $t['cas_do'] . ' · ' . $t['misto']
        . (trim((string) ($t['cena'] ?? '')) !== '' ? ' · ' . $t['cena'] : '');
}

/** Čas, místo a případná cena termínu — druhý sloupec řádku v HTML seznamu. */
function termin_radek_html(array $t): string
{
    return htmlspecialchars(
        $t['cas_od'] . ' do ' . $t['cas_do'] . ' · ' . $t['misto']
        . (trim((string) ($t['cena'] ?? '')) !== '' ? ' · ' . $t['cena'] : ''),
        ENT_QUOTES, 'UTF-8'
    );
}

/** Potvrzení přihlášky na konkrétní termín. */
function email_potvrzeni(array $rezervace, array $termin): bool
{
    $nazev = nazev_typu($termin['typ'] ?? null);
    $kdy   = datum_slovy($termin['datum']) . ', ' . $termin['cas_od'] . ' do ' . $termin['cas_do'];
    $kde   = trim((string) $termin['misto']);
    if (trim((string) ($termin['adresa'] ?? '')) !== '') {
        $kde .= ', ' . trim((string) $termin['adresa']);
    }

    $minut = delka_minut($termin['cas_od'], $termin['cas_do']);
    $podnadpis = ($minut > 0 ? $minut . ' min · ' : '') . MAIL_LEKTORKA;

    $jePravidelna = overeny_typ($termin['typ'] ?? null) === 'lekce';
    $zruseni = 'Nestíháte? <a href="' . htmlspecialchars(odkaz_rezervace($rezervace['token'], 'zrusit'), ENT_QUOTES, 'UTF-8')
        . '" style="color:#111111;text-decoration:underline;">Zrušte rezervaci</a> do začátku lekce a místo dostane někdo další.';

    // U skupinové lekce přijdou praktické informace až v připomínce den předem.
    // Pozdní přihlášky už připomínku nedostanou, informace nesou rovnou tady.
    $pozdni = !empty($rezervace['pozdni']);
    $perex2 = '';
    if ($jePravidelna && $pozdni) {
        $perex = 'Těšíme se na vás. Na lekci vás čeká prostor pro zpomalení, vnímání'
            . ' vlastního těla a objevování pohybu trochu jinak – bez potřeby něco'
            . ' zvládnout správně nebo podávat výkon.';
        $perex2 = 'Přijďte ideálně v teplém, pohodlném oblečení, ve kterém se můžete'
            . ' volně pohybovat. Během lekce využíváme karimatky a deky (ty se pokládají'
            . ' na karimatku pro větší tělesný komfort). Vše je k dispozici na sále,'
            . ' můžete si však přinést i deku svou.';
    } elseif ($jePravidelna) {
        $perex = 'Těšíme se na vás. Den před lekcí vám pošleme e-mail se všemi praktickými informacemi.';
    } else {
        $perex = 'Přijďte prosím o deset minut dřív, v pohodlném oblečení. Podložky a pomůcky máme na sále.';
    }

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'          => 'Potvrzení rezervace · The Move',
        'preheader'        => 'Máte místo na ' . $nazev . ' — ' . $kdy . ', ' . $termin['misto'] . '.',
        'stitek'           => 'Potvrzení rezervace',
        'nadpis'           => 'Máte místo.',
        'perex'            => $perex,
        'perex2'           => $perex2,
        'nazev_akce'       => $nazev,
        'podnadpis_akce'   => $podnadpis,
        'kdy'              => $kdy,
        'kde'              => $kde,
        'cena'             => trim((string) ($termin['cena'] ?? '')),
        'poznamka'         => (string) ($termin['poznamka_email'] ?? ''),
        'na_jmeno'         => $rezervace['jmeno'],
        'cislo_rezervace'  => 'R-' . str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT),
        'tlacitko_url'     => zakladni_url() . '/api/kalendar.php?k=' . urlencode($rezervace['token']),
        'tlacitko_text'    => 'Přidat do kalendáře',
        'pravidelne_url'   => odkaz_rezervace($rezervace['token'], 'pravidelne'),
        'html_zruseni'     => $zruseni,
    ], [
        'detail'     => true,
        'seznam'     => false,
        'perex2'     => $perex2 !== '',
        'cena'       => trim((string) ($termin['cena'] ?? '')) !== '',
        'poznamka'   => trim((string) ($termin['poznamka_email'] ?? '')) !== '',
        'pravidelne' => $jePravidelna,
    ]);

    $text = "Máte místo.\n\n"
        . $perex . "\n"
        . ($perex2 !== '' ? "\n" . $perex2 . "\n" : '')
        . "\n"
        . $nazev . "\n" . $podnadpis . "\n\n"
        . 'Kdy: ' . $kdy . "\n"
        . 'Kde: ' . $kde . "\n"
        . (trim((string) ($termin['cena'] ?? '')) !== '' ? 'Cena: ' . $termin['cena'] . "\n" : '')
        . (trim((string) ($termin['poznamka_email'] ?? '')) !== '' ? 'Poznámka: ' . $termin['poznamka_email'] . "\n" : '')
        . 'Na jméno: ' . $rezervace['jmeno'] . "\n"
        . 'Rezervace: #R-' . str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT) . "\n\n"
        . 'Přidat do kalendáře: ' . zakladni_url() . '/api/kalendar.php?k=' . $rezervace['token'] . "\n"
        . ($jePravidelna ? 'Chodit na lekce pravidelně: ' . odkaz_rezervace($rezervace['token'], 'pravidelne') . "\n" : '')
        . 'Zrušit rezervaci: ' . odkaz_rezervace($rezervace['token'], 'zrusit') . "\n\n"
        . "Více pohybu. Více radosti. Více života.\n\n"
        . 'Dotazy: ' . MAIL_ODESILATEL . ' · ' . MAIL_TELEFON . "\n"
        . MAIL_FIRMA . "\n";

    return posli_mail($rezervace['email'], $rezervace['jmeno'],
        'Máte místo — ' . $nazev . ', ' . $kdy, $html, $text);
}

/**
 * Aktuální přehled lekcí, na které je člověk přihlášený — posílá se po každé
 * úpravě výběru. U každé lekce je odkaz, kterým se z ní dá odhlásit.
 */
function email_pravidelne(array $prihlaska, array $rezervace): bool
{
    $radky = '';
    $seznamText = '';
    if (!$rezervace) {
        $radky = '<tr><td style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;color:#111111;">'
            . 'Aktuálně nejste přihlášeni na žádnou lekci.</td></tr>';
        $seznamText = "Aktuálně nejste přihlášeni na žádnou lekci.\n";
    }
    foreach ($rezervace as $r) {
        $zrusit = odkaz_rezervace($r['token'], 'zrusit');
        $radky .= '<tr>'
            . '<td width="180" valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;mso-line-height-rule:exactly;color:#999999;">'
            . htmlspecialchars(datum_slovy($r['datum']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;mso-line-height-rule:exactly;color:#111111;">'
            . termin_radek_html($r)
            . '<br><a href="' . htmlspecialchars($zrusit, ENT_QUOTES, 'UTF-8')
            . '" style="font-size:13px;color:#999999;text-decoration:underline;">Nemůžu tento termín</a>'
            . '</td></tr>';
        $seznamText .= '- ' . termin_textem($r) . "\n  Odhlásit: " . $zrusit . "\n";
    }

    $pocet = count($rezervace);
    $perex = 'Tady je aktuální přehled lekcí, na které jste přihlášeni. Den před'
        . ' každou z nich vám pošleme připomenutí. A kdykoli vypíšeme nové skupinové'
        . ' lekce, dáme vám vědět e-mailem, ať si můžete vybrat další.';

    $zruseni = 'Kdyby se něco změnilo, výběr lekcí můžete kdykoli upravit tlačítkem výše'
        . ' nebo odkazem u jednotlivého termínu.';

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'        => 'Vaše lekce · The Move',
        'preheader'      => 'Aktuálně jste přihlášeni na ' . $pocet . ' ' . sklonuj_lekce($pocet) . '.',
        'stitek'         => 'Pravidelné lekce',
        'nadpis'         => 'Vaše lekce.',
        'perex'          => $perex,
        'nazev_seznamu'  => 'Vaše lekce',
        'html_seznam_radky' => $radky,
        'tlacitko_url'   => odkaz_trvale((string) $prihlaska['token']),
        'tlacitko_text'  => 'Upravit můj výběr',
        'html_zruseni'   => $zruseni,
    ], [
        'detail'     => false,
        'seznam'     => true,
        'pravidelne' => false,
    ]);

    $text = "Vaše lekce.\n\n" . $perex . "\n\n"
        . "Vaše lekce:\n" . $seznamText . "\n"
        . 'Upravit můj výběr: ' . odkaz_trvale((string) $prihlaska['token']) . "\n\n"
        . "Více pohybu. Více radosti. Více života.\n\n"
        . 'Dotazy: ' . MAIL_ODESILATEL . ' · ' . MAIL_TELEFON . "\n"
        . MAIL_FIRMA . "\n";

    return posli_mail((string) $prihlaska['email'], (string) $prihlaska['jmeno'],
        'Vaše lekce — aktuálně jste přihlášeni na ' . $pocet . ' ' . sklonuj_lekce($pocet), $html, $text);
}

/**
 * Oznámení pravidelnému účastníkovi, že přibyly nové termíny skupinových
 * lekcí. Nikam ho nepřihlašuje — tlačítko vede na stránku s výběrem.
 */
function email_nove_terminy(array $prihlaska, array $terminy): bool
{
    $radky = '';
    $seznamText = '';
    foreach ($terminy as $t) {
        $radky .= '<tr>'
            . '<td width="180" valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;mso-line-height-rule:exactly;color:#999999;">'
            . htmlspecialchars(datum_slovy($t['datum']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;mso-line-height-rule:exactly;color:#111111;">'
            . termin_radek_html($t)
            . '</td></tr>';
        $seznamText .= '- ' . termin_textem($t) . "\n";
    }

    $pocet = count($terminy);
    $nadpis = $pocet === 1 ? 'Máme nový termín.' : 'Máme nové termíny.';
    if ($pocet === 1) {
        $perex = 'Vypsali jsme novou skupinovou lekci. Když se vám hodí, jedním klikem si na ní rezervujete místo.';
    } elseif ($pocet < 5) {
        $perex = 'Vypsali jsme ' . $pocet . ' nové skupinové lekce. Vyberte si, na které chcete přijít — stačí je zaškrtnout a místa vám rezervujeme hned.';
    } else {
        $perex = 'Vypsali jsme ' . $pocet . ' nových skupinových lekcí. Vyberte si, na které chcete přijít — stačí je zaškrtnout a místa vám rezervujeme hned.';
    }

    $zruseni = 'Výběr lekcí můžete stejným odkazem kdykoli upravit — přihlásit se na další termíny i některý zrušit.';

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'           => 'Nové termíny · The Move',
        'preheader'         => $pocet === 1 ? 'Vypsali jsme novou skupinovou lekci — vyberte si termín.' : 'Vypsali jsme nové skupinové lekce — vyberte si termíny.',
        'stitek'            => 'Nové termíny',
        'nadpis'            => $nadpis,
        'perex'             => $perex,
        'nazev_seznamu'     => 'Nové termíny',
        'html_seznam_radky' => $radky,
        'tlacitko_url'      => odkaz_trvale((string) $prihlaska['token']),
        'tlacitko_text'     => 'Vybrat si termíny',
        'html_zruseni'      => $zruseni,
    ], [
        'detail'     => false,
        'seznam'     => true,
        'pravidelne' => false,
    ]);

    $text = $nadpis . "\n\n" . $perex . "\n\n"
        . "Nové termíny:\n" . $seznamText . "\n"
        . 'Vybrat si termíny: ' . odkaz_trvale((string) $prihlaska['token']) . "\n\n"
        . "Více pohybu. Více radosti. Více života.\n\n"
        . 'Dotazy: ' . MAIL_ODESILATEL . ' · ' . MAIL_TELEFON . "\n"
        . MAIL_FIRMA . "\n";

    return posli_mail((string) $prihlaska['email'], (string) $prihlaska['jmeno'],
        $pocet === 1 ? 'Nový termín skupinové lekce' : 'Nové termíny skupinových lekcí', $html, $text);
}

/**
 * Připomenutí den před akcí. U skupinové lekce nese praktické informace
 * (oblečení, karimatky) — potvrzovací e-mail je proto už neobsahuje.
 */
function email_pripominka(array $rezervace, array $termin): bool
{
    $jeLekce = overeny_typ($termin['typ'] ?? null) === 'lekce';
    $nazev = nazev_typu($termin['typ'] ?? null);
    $kdy = datum_slovy($termin['datum']) . ', ' . $termin['cas_od'] . ' do ' . $termin['cas_do'];
    $kde = trim((string) $termin['misto']);
    if (trim((string) ($termin['adresa'] ?? '')) !== '') {
        $kde .= ', ' . trim((string) $termin['adresa']);
    }

    // Připomínka chodí den předem; kdyby se rozesílala až v den akce
    // (třeba když na web nikdo nepřišel), ať nelže.
    $slovo = $termin['datum'] === date('Y-m-d') ? 'dnes' : 'zítra';

    if ($jeLekce) {
        $perex = 'Na lekci vás čeká prostor pro zpomalení, vnímání vlastního těla'
            . ' a objevování pohybu trochu jinak – bez potřeby něco zvládnout správně'
            . ' nebo podávat výkon.';
        $perex2 = 'Přijďte ideálně v teplém, pohodlném oblečení, ve kterém se můžete'
            . ' volně pohybovat. Během lekce využíváme karimatky a deky (ty se pokládají'
            . ' na karimatku pro větší tělesný komfort). Vše je k dispozici na sále,'
            . ' můžete si však přinést i deku svou.';
    } else {
        $perex = 'Připomínáme, že ' . $slovo . ' se potkáme. Přijďte prosím o deset'
            . ' minut dřív, v pohodlném oblečení. Podložky a pomůcky máme na sále.';
        $perex2 = '';
    }

    $minut = delka_minut($termin['cas_od'], $termin['cas_do']);
    $zruseni = 'Nemůžete přijít? <a href="' . htmlspecialchars(odkaz_rezervace($rezervace['token'], 'zrusit'), ENT_QUOTES, 'UTF-8')
        . '" style="color:#111111;text-decoration:underline;">Zrušte rezervaci</a> a místo dostane někdo další.';

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'          => 'Připomenutí · The Move',
        'preheader'        => ucfirst($slovo) . ' ' . $termin['cas_od'] . ': ' . $nazev . ', ' . $termin['misto'] . '.',
        'stitek'           => 'Připomenutí',
        'nadpis'           => ucfirst($slovo) . ' se uvidíme.',
        'perex'            => $perex,
        'perex2'           => $perex2,
        'nazev_akce'       => $nazev,
        'podnadpis_akce'   => ($minut > 0 ? $minut . ' min · ' : '') . MAIL_LEKTORKA,
        'kdy'              => $kdy,
        'kde'              => $kde,
        'cena'             => trim((string) ($termin['cena'] ?? '')),
        'poznamka'         => (string) ($termin['poznamka_email'] ?? ''),
        'na_jmeno'         => $rezervace['jmeno'],
        'cislo_rezervace'  => 'R-' . str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT),
        'tlacitko_url'     => zakladni_url() . '/api/kalendar.php?k=' . urlencode($rezervace['token']),
        'tlacitko_text'    => 'Přidat do kalendáře',
        'html_zruseni'     => $zruseni,
    ], [
        'detail'     => true,
        'seznam'     => false,
        'perex2'     => $perex2 !== '',
        'cena'       => trim((string) ($termin['cena'] ?? '')) !== '',
        'poznamka'   => trim((string) ($termin['poznamka_email'] ?? '')) !== '',
        'pravidelne' => false,
    ]);

    $text = ucfirst($slovo) . " se uvidíme.\n\n"
        . $perex . "\n"
        . ($perex2 !== '' ? "\n" . $perex2 . "\n" : '')
        . "\n" . $nazev . "\n\n"
        . 'Kdy: ' . $kdy . "\n"
        . 'Kde: ' . $kde . "\n"
        . (trim((string) ($termin['cena'] ?? '')) !== '' ? 'Cena: ' . $termin['cena'] . "\n" : '')
        . (trim((string) ($termin['poznamka_email'] ?? '')) !== '' ? 'Poznámka: ' . $termin['poznamka_email'] . "\n" : '')
        . 'Rezervace: #R-' . str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT) . "\n\n"
        . 'Přidat do kalendáře: ' . zakladni_url() . '/api/kalendar.php?k=' . $rezervace['token'] . "\n"
        . 'Zrušit rezervaci: ' . odkaz_rezervace($rezervace['token'], 'zrusit') . "\n\n"
        . "Více pohybu. Více radosti. Více života.\n\n"
        . 'Dotazy: ' . MAIL_ODESILATEL . ' · ' . MAIL_TELEFON . "\n"
        . MAIL_FIRMA . "\n";

    return posli_mail($rezervace['email'], $rezervace['jmeno'],
        ucfirst($slovo) . ' ' . $termin['cas_od'] . ' — ' . $nazev . ', ' . $termin['misto'], $html, $text);
}

/** „1 lekci", „3 lekce", „5 lekcí". */
function sklonuj_lekce(int $pocet): string
{
    if ($pocet === 1) { return 'lekci'; }
    if ($pocet >= 2 && $pocet <= 4) { return 'lekce'; }

    return 'lekcí';
}
