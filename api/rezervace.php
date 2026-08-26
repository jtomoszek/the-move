<?php
/**
 * THE MOVE :: vytvoření rezervace na termín (JSON POST).
 */

declare(strict_types=1);

require __DIR__ . '/../inc/rezervace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function odpoved(bool $ok, string $zprava, int $kod = 200, array $extra = [])
{
    http_response_code($kod);
    echo json_encode(['ok' => $ok, 'zprava' => $zprava] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    odpoved(false, 'Neplatný požadavek.', 405);
}

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

// Honeypot: pole „web" vyplňují jen roboti.
if (!empty($data['web'])) {
    odpoved(true, 'Děkujeme, rezervace byla přijata.');
}

$terminId = (int) ($data['termin_id'] ?? 0);
$jmeno    = trim((string) ($data['jmeno'] ?? ''));
$email    = trim((string) ($data['email'] ?? ''));
$telefon  = trim((string) ($data['telefon'] ?? ''));

if ($terminId <= 0 || $jmeno === '' || mb_strlen($jmeno) > 100) {
    odpoved(false, 'Vyplňte prosím své jméno.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
    odpoved(false, 'Vyplňte prosím platný e-mail.', 422);
}
if (mb_strlen($telefon) > 30) {
    odpoved(false, 'Telefon je příliš dlouhý.', 422);
}

try {
    $vysledek = vytvor_rezervaci(db(), $terminId, $jmeno, $email, $telefon);

    if (!$vysledek['ok']) {
        odpoved(false, $vysledek['chyba'], $vysledek['kod']);
    }

    // Potvrzení účastníkovi; když se e-mail nepodaří odeslat, rezervace platí dál.
    $odeslano = email_potvrzeni($vysledek['rezervace'], $vysledek['termin']);

    $termin = $vysledek['termin'];
    $volno = (int) $termin['kapacita'] - (int) $termin['obsazeno'] - 1;
    $zprava = $odeslano
        ? 'Děkujeme! Vaše místo je rezervované, potvrzení jsme vám poslali e-mailem.'
        : 'Děkujeme! Vaše místo je rezervované, brzy se vám ozveme.';

    odpoved(true, $zprava, 200, ['volno' => $volno]);
} catch (Throwable $e) {
    odpoved(false, 'Rezervaci se nepodařilo uložit, zkuste to prosím znovu.', 500);
}
