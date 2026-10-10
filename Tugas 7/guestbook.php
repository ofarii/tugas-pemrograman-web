<?php

declare(strict_types=1);

/**
 * guestbook.php
 */

require_once __DIR__ . '/GuestBook.php';

/* ------------------------------------------------------------------
 * Sesi dan header keamanan
 * ---------------------------------------------------------------- */
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

/* ------------------------------------------------------------------
 * Fungsi bantu
 * ---------------------------------------------------------------- */

function e(?string $nilai): string
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_valid(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function format_tanggal(string $tanggal): string
{
    $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    try {
        $dt = new DateTimeImmutable($tanggal);
    } catch (Exception) {
        return $tanggal;
    }

    return $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')] . ' ' . $dt->format('Y, H:i');
}

/* ------------------------------------------------------------------
 * Logika halaman
 * ---------------------------------------------------------------- */
$errors  = [];
$old     = ['nama' => '', 'email' => '', 'pesan' => ''];
$entries = [];
$dbError = null;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    $guestBook = new GuestBook(GuestBook::connect());
    $guestBook->createTable();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            $errors['csrf'] = 'Sesi formulir tidak valid atau sudah kedaluwarsa. Silakan kirim ulang formulir.';
        } else {
            $old    = GuestBook::normalize($_POST);
            $errors = $guestBook->validate($old);

            if ($errors === []) {
                $guestBook->add($old);

                // Rotasi token agar token lama tidak dapat dipakai ulang.
                unset($_SESSION['csrf_token']);
                $_SESSION['flash'] = 'Terima kasih, pesan Anda berhasil disimpan.';

                // Post/Redirect/Get
                header('Location: ' . basename(__FILE__), true, 303);
                exit;
            }

            http_response_code(422);
        }
    }

    $entries = $guestBook->getAll();
} catch (PDOException $ex) {
    error_log('[guestbook] ' . $ex->getMessage());
    http_response_code(500);
    $dbError = 'Maaf, basis data sedang tidak dapat diakses. Silakan coba beberapa saat lagi.';
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buku Tamu Perpustakaan</title>
    <style>
        :root {
            --bg: #f6f4ef; --card: #ffffff; --ink: #1f2933; --muted: #6b7280;
            --line: #e5e1d8; --accent: #2f5d50; --accent-ink: #ffffff;
            --error: #b42318; --error-bg: #fef3f2; --ok: #067647; --ok-bg: #ecfdf3;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--ink);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .wrap { max-width: 960px; margin: 0 auto; padding: 32px 16px 64px; }
        header h1 { margin: 0; font-size: 1.75rem; }
        header p { margin: 4px 0 24px; color: var(--muted); }
        .card {
            background: var(--card); border: 1px solid var(--line);
            border-radius: 12px; padding: 24px; margin-bottom: 24px;
        }
        .card h2 { margin: 0 0 16px; font-size: 1.15rem; }
        .grid { display: grid; gap: 16px; grid-template-columns: 1fr 1fr; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input, textarea {
            width: 100%; padding: 10px 12px; font: inherit; color: inherit;
            border: 1px solid var(--line); border-radius: 8px; background: #fff;
        }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        [aria-invalid="true"] { border-color: var(--error); }
        .field-error { color: var(--error); font-size: .875rem; margin-top: 4px; }
        .hint { color: var(--muted); font-size: .875rem; margin-top: 4px; }
        button {
            background: var(--accent); color: var(--accent-ink); border: 0;
            padding: 10px 20px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer;
        }
        button:hover { filter: brightness(1.1); }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert-error { background: var(--error-bg); color: var(--error); }
        .alert-ok { background: var(--ok-bg); color: var(--ok); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        td.pesan { white-space: pre-line; word-break: break-word; min-width: 220px; }
        td.nowrap { white-space: nowrap; }
        .empty { color: var(--muted); text-align: center; padding: 24px; }
        @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main class="wrap">
    <header>
        <h1>Buku Tamu Perpustakaan</h1>
        <p>Tinggalkan kesan, saran, atau pesan Anda untuk perpustakaan kami.</p>
    </header>

    <section class="card" aria-labelledby="judul-form">
        <h2 id="judul-form">Tulis Pesan</h2>

        <?php if ($flash !== null): ?>
            <div class="alert alert-ok" role="status"><?= e($flash) ?></div>
        <?php endif; ?>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error" role="alert"><?= e($dbError) ?></div>
        <?php endif; ?>

        <?php if (isset($errors['csrf'])): ?>
            <div class="alert alert-error" role="alert"><?= e($errors['csrf']) ?></div>
        <?php elseif ($errors !== []): ?>
            <div class="alert alert-error" role="alert">Periksa kembali isian yang ditandai di bawah.</div>
        <?php endif; ?>

        <form method="post" action="<?= e(basename(__FILE__)) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

            <div class="grid">
                <div>
                    <label for="nama">Nama</label>
                    <input type="text" id="nama" name="nama" required
                           maxlength="<?= GuestBook::NAMA_MAKS ?>"
                           value="<?= e($old['nama']) ?>"
                           aria-invalid="<?= isset($errors['nama']) ? 'true' : 'false' ?>"
                           <?= isset($errors['nama']) ? 'aria-describedby="err-nama"' : '' ?>>
                    <?php if (isset($errors['nama'])): ?>
                        <div class="field-error" id="err-nama"><?= e($errors['nama']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required
                           maxlength="<?= GuestBook::EMAIL_MAKS ?>"
                           value="<?= e($old['email']) ?>"
                           aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>"
                           <?= isset($errors['email']) ? 'aria-describedby="err-email"' : '' ?>>
                    <?php if (isset($errors['email'])): ?>
                        <div class="field-error" id="err-email"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="full">
                    <label for="pesan">Pesan</label>
                    <textarea id="pesan" name="pesan" required
                              minlength="<?= GuestBook::PESAN_MIN ?>"
                              maxlength="<?= GuestBook::PESAN_MAKS ?>"
                              aria-invalid="<?= isset($errors['pesan']) ? 'true' : 'false' ?>"
                              aria-describedby="<?= isset($errors['pesan']) ? 'err-pesan' : 'hint-pesan' ?>"><?= e($old['pesan']) ?></textarea>
                    <?php if (isset($errors['pesan'])): ?>
                        <div class="field-error" id="err-pesan"><?= e($errors['pesan']) ?></div>
                    <?php else: ?>
                        <div class="hint" id="hint-pesan">Minimal <?= GuestBook::PESAN_MIN ?> karakter.</div>
                    <?php endif; ?>
                </div>

                <div class="full">
                    <button type="submit">Kirim Pesan</button>
                </div>
            </div>
        </form>
    </section>

    <section class="card" aria-labelledby="judul-daftar">
        <h2 id="judul-daftar">Daftar Pesan (<?= count($entries) ?>)</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">No</th>
                    <th scope="col">Nama</th>
                    <th scope="col">Email</th>
                    <th scope="col">Pesan</th>
                    <th scope="col">Tanggal Kirim</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($entries === []): ?>
                    <tr><td colspan="5" class="empty">Belum ada pesan. Jadilah yang pertama!</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $i => $row): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($row['nama']) ?></td>
                            <td><?= e($row['email']) ?></td>
                            <td class="pesan"><?= e($row['pesan']) ?></td>
                            <td class="nowrap"><?= e(format_tanggal((string) $row['tanggal_kirim'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
