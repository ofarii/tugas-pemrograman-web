<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/Transaction.php';

// Inisialisasi saldo & riwayat transaksi dalam sesi (hanya sekali per sesi)
if (!isset($_SESSION['balance'])) {
    $_SESSION['balance'] = 0.0;
}
if (!isset($_SESSION['history'])) {
    $_SESSION['history'] = [];
}

// Generate token CSRF jika belum ada di sesi
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Validasi token CSRF terlebih dahulu, sebelum memproses apa pun
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $message = "Token CSRF tidak valid! Permintaan ditolak.";
        $messageType = "danger";
    } else {
        $rawType   = $_POST['type'] ?? '';
        $rawAmount = $_POST['amount'] ?? '';

        // 2. Validasi jumlah transaksi: harus angka desimal positif
        $isValidAmount = is_string($rawAmount)
            && preg_match('/^\d+(\.\d{1,2})?$/', $rawAmount) === 1
            && (float) $rawAmount > 0;

        if (!$isValidAmount) {
            $message = "Jumlah transaksi harus berupa angka desimal positif!";
            $messageType = "danger";
        } else {
            $amount = (float) $rawAmount;

            // 3. Gunakan match expression untuk mencocokkan jenis transaksi
            $validType = match ($rawType) {
                'deposit'    => 'deposit',
                'withdrawal' => 'withdrawal',
                default      => null,
            };

            if ($validType === null) {
                $message = "Jenis transaksi tidak valid!";
                $messageType = "danger";
            } else {
                // Buat ID Transaksi Unik
                $transactionId = 'TRX-' . strtoupper(bin2hex(random_bytes(4)));

                // Instansiasi Objek Transaction (OOP Enkapsulasi)
                $transaction = new Transaction($transactionId, $validType, $amount);

                // Proses Transaksi (saldo di-passing by reference)
                $success = $transaction->process($_SESSION['balance']);

                if ($success) {
                    $actionText = $validType === 'deposit' ? 'Deposit' : 'Penarikan';
                    $message = "Berhasil melakukan {$actionText} sebesar Rp " . number_format($amount, 2, ',', '.');
                    $messageType = "success";

                    // Catat ke riwayat
                    array_unshift($_SESSION['history'], [
                        'id'           => $transaction->getId(),
                        'type'         => $transaction->getType(),
                        'amount'       => $transaction->getAmount(),
                        'time'         => date('Y-m-d H:i:s'),
                        'balance_after' => $_SESSION['balance'],
                    ]);
                } else {
                    $message = "Gagal melakukan penarikan: Saldo Anda tidak mencukupi!";
                    $messageType = "danger";
                }
            }
        }
    }

    // Regenerasi token CSRF setiap kali diproses, agar token lama tidak bisa dipakai ulang (replay)
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$balance   = $_SESSION['balance'];
$history   = $_SESSION['history'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Sistem Manajemen Keuangan Sederhana</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #222; }
  .balance { font-size: 1.4em; font-weight: bold; margin-bottom: 16px; }
  .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
  .alert.success { background: #d4edda; color: #155724; }
  .alert.danger { background: #f8d7da; color: #721c24; }
  form { margin-bottom: 24px; }
  label { display: block; margin-bottom: 6px; font-weight: bold; }
  input, select { padding: 6px; margin-bottom: 12px; width: 100%; box-sizing: border-box; }
  button { padding: 8px 16px; cursor: pointer; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ccc; padding: 8px; text-align: left; font-size: 0.9em; }
  th { background: #f2f2f2; }
</style>
</head>
<body>

<h1>Sistem Manajemen Keuangan Sederhana</h1>

<div class="balance">
    Saldo saat ini: Rp <?= htmlspecialchars(number_format($balance, 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?>
</div>

<?php if ($message !== ''): ?>
<div class="alert <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <label for="type">Jenis Transaksi</label>
    <select name="type" id="type" required>
        <option value="deposit">Deposit</option>
        <option value="withdrawal">Penarikan</option>
    </select>

    <label for="amount">Jumlah (Rp)</label>
    <input type="text" name="amount" id="amount" placeholder="Contoh: 50000 atau 50000.50" required>

    <button type="submit">Proses Transaksi</button>
</form>

<h2>Riwayat Transaksi</h2>
<?php if (empty($history)): ?>
    <p>Belum ada transaksi.</p>
<?php else: ?>
<table>
    <tr>
        <th>ID</th>
        <th>Jenis</th>
        <th>Jumlah</th>
        <th>Waktu</th>
        <th>Saldo Setelah</th>
    </tr>
    <?php foreach ($history as $item): ?>
    <tr>
        <td><?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($item['type'], ENT_QUOTES, 'UTF-8') ?></td>
        <td>Rp <?= htmlspecialchars(number_format($item['amount'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8') ?></td>
        <td>Rp <?= htmlspecialchars(number_format($item['balance_after'], 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>