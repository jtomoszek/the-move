<?php
/**
 * THE MOVE :: společný začátek stránek administrace (mimo index.php,
 * který si řeší i přihlašovací obrazovku sám).
 *
 * Kdo není přihlášený, putuje na index.php. Stránka, která tento soubor
 * načte, si předtím nastaví $nadpisStranky a $aktivniZalozka.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/rezervace.php';
require_once __DIR__ . '/../inc/klienti.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

$pdo = db();

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_over(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Neplatný formulář, vraťte se zpět a zkuste to znovu.');
    }
}

$csrf = csrf_token();
$aktivniZalozka = $aktivniZalozka ?? '';
$nadpisStranky = $nadpisStranky ?? 'Administrace';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($nadpisStranky) ?> · The Move</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://api.fontshare.com/v2/css?f[]=general-sans@400,500&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css?v=<?= substr(md5_file(__DIR__ . '/admin.css') ?: '', 0, 8) ?>">
<link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg">
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <img class="logo" src="../assets/img/logo.webp" alt="The Move">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <a class="btn btn--mini<?= $aktivniZalozka === 'terminy' ? '' : ' btn--ghost' ?>" href="index.php">Termíny</a>
      <a class="btn btn--mini<?= $aktivniZalozka === 'klienti' ? '' : ' btn--ghost' ?>" href="klienti.php">Klienti</a>
      <a class="btn btn--mini<?= $aktivniZalozka === 'novinky' ? '' : ' btn--ghost' ?>" href="novinky.php">Novinky</a>
      <a class="btn btn--ghost btn--mini" href="../index.html#terminy">Zobrazit web</a>
      <form method="post" action="index.php" style="display:inline">
        <input type="hidden" name="akce" value="odhlasit">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--mini" type="submit">Odhlásit</button>
      </form>
    </div>
  </div>
