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
 * Kolik minut se čeká, než se pravidelným účastníkům rozešle souhrn o nových
 * termínech. Lektorka může vypsat termíny postupně a lidem přijde jeden e-mail.
 */
const PAUZA_SOUHRNU = 60;

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
    $html = (string) preg_replace('~<!--\{\{#[a-z_]+\}\}-->.*?<!--\{\{/[a-z_]+\}\}-->~s', '', $html);

    $nahrady = [];
    foreach ($promenne as $klic => $hodnota) {
        $nahrady['{{' . $klic . '}}'] = strncmp($klic, 'html_', 5) === 0
            ? (string) $hodnota
            : htmlspecialchars((string) $hodnota, ENT_QUOTES, 'UTF-8');
    }

    return strtr($html, $nahrady);
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
        $zaklad = $dir . '/' . date('Ymd-His') . '-' . preg_replace('~[^a-z0-9]+~i', '-', $komu);
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
        . ', ' . $t['cas_od'] . ' do ' . $t['cas_do'] . ' · ' . $t['misto'];
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

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'          => 'Potvrzení rezervace · The Move',
        'preheader'        => 'Máte místo na ' . $nazev . ' — ' . $kdy . ', ' . $termin['misto'] . '.',
        'stitek'           => 'Potvrzení rezervace',
        'nadpis'           => 'Máte místo.',
        'perex'            => 'Přijďte prosím o deset minut dřív, v pohodlném oblečení. Podložky a pomůcky máme na sále.',
        'nazev_akce'       => $nazev,
        'podnadpis_akce'   => $podnadpis,
        'kdy'              => $kdy,
        'kde'              => $kde,
        'poznamka'         => (string) ($termin['poznamka'] ?? ''),
        'na_jmeno'         => $rezervace['jmeno'],
        'cislo_rezervace'  => 'R-' . str_pad((string) $rezervace['id'], 5, '0', STR_PAD_LEFT),
        'tlacitko_url'     => zakladni_url() . '/api/kalendar.php?k=' . urlencode($rezervace['token']),
        'tlacitko_text'    => 'Přidat do kalendáře',
        'pravidelne_url'   => odkaz_rezervace($rezervace['token'], 'pravidelne'),
        'html_zruseni'     => $zruseni,
    ], [
        'detail'     => true,
        'seznam'     => false,
        'poznamka'   => trim((string) ($termin['poznamka'] ?? '')) !== '',
        'pravidelne' => $jePravidelna,
    ]);

    $text = "Máte místo.\n\n"
        . "Přijďte prosím o deset minut dřív, v pohodlném oblečení. Podložky a pomůcky máme na sále.\n\n"
        . $nazev . "\n" . $podnadpis . "\n\n"
        . 'Kdy: ' . $kdy . "\n"
        . 'Kde: ' . $kde . "\n"
        . (trim((string) ($termin['poznamka'] ?? '')) !== '' ? 'Poznámka: ' . $termin['poznamka'] . "\n" : '')
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

/** Potvrzení trvalé přihlášky na pravidelné skupinové lekce. */
function email_pravidelne(array $prihlaska, array $terminy, int $plne = 0): bool
{
    $radky = '';
    $seznamText = '';
    foreach ($terminy as $t) {
        $radky .= '<tr>'
            . '<td width="180" valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;mso-line-height-rule:exactly;color:#999999;">'
            . htmlspecialchars(datum_slovy($t['datum']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;mso-line-height-rule:exactly;color:#111111;">'
            . htmlspecialchars($t['cas_od'] . ' do ' . $t['cas_do'] . ' · ' . $t['misto'], ENT_QUOTES, 'UTF-8')
            . '</td></tr>';
        $seznamText .= '- ' . termin_textem($t) . "\n";
    }

    if ($radky === '') {
        $radky = '<tr><td style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;color:#111111;">'
            . 'Zatím nejsou vypsané žádné termíny — jakmile přibude první, ozveme se.</td></tr>';
        $seznamText = "Zatím nejsou vypsané žádné termíny — jakmile přibude první, ozveme se.\n";
    }

    $pocet = count($terminy);
    $perex = $pocet > 0
        ? 'Přihlásili jsme vás na ' . $pocet . ' ' . sklonuj_lekce($pocet)
          . '. Každou další skupinovou lekci, kterou vypíšeme, vám přidáme automaticky a pošleme potvrzení.'
        : 'Máte u nás trvalou přihlášku. Každou skupinovou lekci, kterou vypíšeme, vám přidáme automaticky a pošleme potvrzení.';

    if ($plne === 1) {
        $perex .= ' Jedna lekce už byla plná, na tu vás bohužel přidat nešlo.';
    } elseif ($plne > 1 && $plne < 5) {
        $perex .= ' ' . $plne . ' lekce už byly plné, na ty vás bohužel přidat nešlo.';
    } elseif ($plne >= 5) {
        $perex .= ' ' . $plne . ' lekcí už bylo plných, na ty vás bohužel přidat nešlo.';
    }

    $zruseni = 'Chcete přestat? <a href="' . htmlspecialchars(odkaz_trvale($prihlaska['token']), ENT_QUOTES, 'UTF-8')
        . '" style="color:#111111;text-decoration:underline;">Zrušte pravidelnou docházku</a> — rezervace, které už máte, můžete zrušit každou zvlášť.';

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'        => 'Pravidelné lekce · The Move',
        'preheader'      => 'Máte místo na všech vypsaných skupinových lekcích.',
        'stitek'         => 'Pravidelné lekce',
        'nadpis'         => 'Chodíte pravidelně.',
        'perex'          => $perex,
        'nazev_seznamu'  => 'Vaše nejbližší lekce',
        'html_seznam_radky' => $radky,
        'tlacitko_url'   => zakladni_url() . '/index.html#terminy',
        'tlacitko_text'  => 'Zobrazit všechny termíny',
        'html_zruseni'   => $zruseni,
    ], [
        'detail'     => false,
        'seznam'     => true,
        'pravidelne' => false,
    ]);

    $text = "Chodíte pravidelně.\n\n" . $perex . "\n\n"
        . "Vaše nejbližší lekce:\n" . $seznamText . "\n"
        . 'Všechny termíny: ' . zakladni_url() . "/index.html#terminy\n"
        . 'Zrušit pravidelnou docházku: ' . odkaz_trvale($prihlaska['token']) . "\n\n"
        . "Více pohybu. Více radosti. Více života.\n\n"
        . 'Dotazy: ' . MAIL_ODESILATEL . ' · ' . MAIL_TELEFON . "\n"
        . MAIL_FIRMA . "\n";

    return posli_mail($prihlaska['email'], $prihlaska['jmeno'],
        'Chodíte pravidelně — potvrzení přihlášky', $html, $text);
}

/**
 * Souhrn nově vypsaných termínů pro pravidelného účastníka.
 * U každého termínu je odkaz, kterým se z něj dá odhlásit.
 */
function email_nove_terminy(array $prihlaska, array $rezervace): bool
{
    $radky = '';
    $seznamText = '';
    foreach ($rezervace as $r) {
        $zrusit = odkaz_rezervace($r['token'], 'zrusit');
        $radky .= '<tr>'
            . '<td width="180" valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;mso-line-height-rule:exactly;color:#999999;">'
            . htmlspecialchars(datum_slovy($r['datum']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td valign="top" style="padding:14px 0;border-top:1px solid #e6e6e6;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;mso-line-height-rule:exactly;color:#111111;">'
            . htmlspecialchars($r['cas_od'] . ' do ' . $r['cas_do'] . ' · ' . $r['misto'], ENT_QUOTES, 'UTF-8')
            . '<br><a href="' . htmlspecialchars($zrusit, ENT_QUOTES, 'UTF-8')
            . '" style="font-size:13px;color:#999999;text-decoration:underline;">Nemůžu tento termín</a>'
            . '</td></tr>';
        $seznamText .= '- ' . termin_textem($r) . "\n  Odhlásit: " . $zrusit . "\n";
    }

    $pocet = count($rezervace);
    $nadpis = $pocet === 1 ? 'Máte nový termín.' : 'Máte nové termíny.';
    $perex = $pocet === 1
        ? 'Vypsali jsme novou skupinovou lekci a rezervovali vám na ní místo. Kdyby se vám nehodila, stačí se odhlásit.'
        : 'Vypsali jsme ' . $pocet . ' nové skupinové lekce a rezervovali vám na nich místo. Kdyby se vám některá nehodila, stačí se z ní odhlásit.';
    if ($pocet >= 5) {
        $perex = 'Vypsali jsme ' . $pocet . ' nových skupinových lekcí a rezervovali vám na nich místo.'
            . ' Kdyby se vám některá nehodila, stačí se z ní odhlásit.';
    }

    $zruseni = 'Nechcete už chodit pravidelně? <a href="' . htmlspecialchars(odkaz_trvale((string) $prihlaska['token']), ENT_QUOTES, 'UTF-8')
        . '" style="color:#111111;text-decoration:underline;">Zrušte pravidelnou docházku</a> a nové termíny vám přidávat nebudeme.';

    $html = sablona_zprava(mail_zaklad() + [
        'titulek'           => 'Nové termíny · The Move',
        'preheader'         => $pocet === 1 ? 'Přidali jsme vám nový termín.' : 'Přidali jsme vám ' . $pocet . ' nových termínů.',
        'stitek'            => 'Pravidelné lekce',
        'nadpis'            => $nadpis,
        'perex'             => $perex,
        'nazev_seznamu'     => 'Nové termíny',
        'html_seznam_radky' => $radky,
        'tlacitko_url'      => zakladni_url() . '/index.html#terminy',
        'tlacitko_text'     => 'Zobrazit všechny termíny',
        'html_zruseni'      => $zruseni,
    ], [
        'detail'     => false,
        'seznam'     => true,
        'pravidelne' => false,
    ]);

    $text = $nadpis . "\n\n" . $perex . "\n\n"
        . "Nové termíny:\n" . $seznamText . "\n"
        . 'Všechny termíny: ' . zakladni_url() . "/index.html#terminy\n"
        . 'Zrušit pravidelnou docházku: ' . odkaz_trvale((string) $prihlaska['token']) . "\n\n"
        . "Více pohybu. Více radosti. Více života.\n\n"
        . 'Dotazy: ' . MAIL_ODESILATEL . ' · ' . MAIL_TELEFON . "\n"
        . MAIL_FIRMA . "\n";

    return posli_mail((string) $prihlaska['email'], (string) $prihlaska['jmeno'],
        $pocet === 1 ? 'Nový termín pravidelné lekce' : 'Nové termíny pravidelných lekcí', $html, $text);
}

/** „1 lekci", „3 lekce", „5 lekcí". */
function sklonuj_lekce(int $pocet): string
{
    if ($pocet === 1) { return 'lekci'; }
    if ($pocet >= 2 && $pocet <= 4) { return 'lekce'; }

    return 'lekcí';
}
