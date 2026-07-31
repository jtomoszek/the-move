<?php
/**
 * THE MOVE :: vytvoření rezervace na termín (JSON POST).
 */

declare(strict_types=1);

require __DIR__ . '/../inc/db.php';

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
    $pdo = db();
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
        odpoved(false, 'Tento termín už není dostupný.', 404);
    }

    if ((int) $termin['obsazeno'] >= (int) $termin['kapacita']) {
        $pdo->rollBack();
        odpoved(false, 'Tento termín je bohužel již obsazený.', 409);
    }

    $dup = $pdo->prepare(
        'SELECT COUNT(*) FROM rezervace WHERE termin_id = :t AND email = :e COLLATE NOCASE'
    );
    $dup->execute([':t' => $terminId, ':e' => $email]);
    if ((int) $dup->fetchColumn() > 0) {
        $pdo->rollBack();
        odpoved(false, 'Na tento termín jste již přihlášeni.', 409);
    }

    $ins = $pdo->prepare(
        'INSERT INTO rezervace (termin_id, jmeno, email, telefon) VALUES (:t, :j, :e, :tel)'
    );
    $ins->execute([':t' => $terminId, ':j' => $jmeno, ':e' => $email, ':tel' => $telefon]);
    $pdo->commit();

    // Upozornění lektorce (na sdíleném hostingu funguje mail(); selhání nevadí).
    $predmet = '=?UTF-8?B?' . base64_encode('Nová rezervace :: The Move') . '?=';
    $telo = "Nová rezervace lekce:\n\n"
        . cesky_den($termin['datum']) . ' ' . ceske_datum($termin['datum'])
        . ' ' . $termin['cas_od'] . ' do ' . $termin['cas_do'] . "\n"
        . $termin['misto'] . "\n\n"
        . "Jméno: {$jmeno}\nE-mail: {$email}\nTelefon: {$telefon}\n";
    @mail('info@themove.cz', $predmet, $telo,
        "From: web@themove.cz\r\nContent-Type: text/plain; charset=UTF-8");

    $volno = (int) $termin['kapacita'] - (int) $termin['obsazeno'] - 1;
    odpoved(true, 'Děkujeme! Vaše místo je rezervované, brzy se vám ozveme.', 200, ['volno' => $volno]);
} catch (Throwable $e) {
    odpoved(false, 'Rezervaci se nepodařilo uložit, zkuste to prosím znovu.', 500);
}
