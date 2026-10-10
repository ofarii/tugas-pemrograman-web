<?php

declare(strict_types=1);

/**
 * GuestBook
 */
final class GuestBook
{
    public const NAMA_MAKS  = 100;
    public const EMAIL_MAKS = 150;
    public const PESAN_MIN  = 5;
    public const PESAN_MAKS = 1000;

    private const SCHEMA = <<<SQL
        CREATE TABLE IF NOT EXISTS buku_tamu (
            id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            nama          VARCHAR(100)  NOT NULL,
            email         VARCHAR(150)  NOT NULL,
            pesan         TEXT          NOT NULL,
            tanggal_kirim DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_tanggal_kirim (tanggal_kirim)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Membuat koneksi PDO ke MySQL/MariaDB dengan pengaturan yang aman.
     *
     * Konfigurasi dibaca dari environment variable (DB_HOST, DB_PORT,
     * DB_NAME, DB_USER, DB_PASS) dengan nilai bawaan untuk XAMPP/Laragon.
     *
     * @param array{host?:string,port?:string,name?:string,user?:string,pass?:string} $config
     */
    public static function connect(array $config = []): PDO
    {
        $host = $config['host'] ?? (getenv('DB_HOST') ?: '127.0.0.1');
        $port = $config['port'] ?? (getenv('DB_PORT') ?: '3306');
        $name = $config['name'] ?? (getenv('DB_NAME') ?: 'perpustakaan');
        $user = $config['user'] ?? (getenv('DB_USER') ?: 'root');
        $pass = $config['pass'] ?? (getenv('DB_PASS') ?: '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,               
        ]);
    }

    public function createTable(): void
    {
        $this->pdo->exec(self::SCHEMA);
    }

    /**
     * Merapikan masukan mentah (mis. $_POST) menjadi tiga string yang sudah di-trim.
     * Nilai yang bukan string (mis. nama[]=x) dianggap kosong.
     *
     * @param array<string,mixed> $input
     * @return array{nama:string,email:string,pesan:string}
     */
    public static function normalize(array $input): array
    {
        $ambil = static function (string $kunci) use ($input): string {
            $nilai = $input[$kunci] ?? '';
            return is_string($nilai) ? trim($nilai) : '';
        };

        return [
            'nama'  => $ambil('nama'),
            'email' => $ambil('email'),
            'pesan' => $ambil('pesan'),
        ];
    }

    /**
     * Memvalidasi data sesuai ketentuan:
     * - nama tidak boleh kosong,
     * - email harus berformat valid,
     * - pesan minimal lima karakter.
     *
     * @param array{nama:string,email:string,pesan:string} $data
     * @return array<string,string> Daftar galat per kolom; kosong berarti valid.
     */
    public function validate(array $data): array
    {
        $errors = [];

        if ($data['nama'] === '') {
            $errors['nama'] = 'Nama wajib diisi.';
        } elseif (self::panjang($data['nama']) > self::NAMA_MAKS) {
            $errors['nama'] = 'Nama maksimal ' . self::NAMA_MAKS . ' karakter.';
        }

        if ($data['email'] === '') {
            $errors['email'] = 'Email wajib diisi.';
        } elseif (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Format email tidak valid.';
        } elseif (self::panjang($data['email']) > self::EMAIL_MAKS) {
            $errors['email'] = 'Email maksimal ' . self::EMAIL_MAKS . ' karakter.';
        }

        if (self::panjang($data['pesan']) < self::PESAN_MIN) {
            $errors['pesan'] = 'Pesan minimal ' . self::PESAN_MIN . ' karakter.';
        } elseif (self::panjang($data['pesan']) > self::PESAN_MAKS) {
            $errors['pesan'] = 'Pesan maksimal ' . self::PESAN_MAKS . ' karakter.';
        }

        return $errors;
    }

    /**
     * Menyimpan satu pesan baru menggunakan prepared statement INSERT.
     * Data divalidasi ulang di sini sebagai lapisan pertahanan tambahan.
     *
     * @param array<string,mixed> $input
     * @return int ID baris yang baru disimpan.
     * @throws InvalidArgumentException Bila data tidak lolos validasi.
     */
    public function add(array $input): int
    {
        $data   = self::normalize($input);
        $errors = $this->validate($data);

        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO buku_tamu (nama, email, pesan, tanggal_kirim)
             VALUES (:nama, :email, :pesan, CURRENT_TIMESTAMP)'
        );
        $stmt->bindValue(':nama', $data['nama'], PDO::PARAM_STR);
        $stmt->bindValue(':email', $data['email'], PDO::PARAM_STR);
        $stmt->bindValue(':pesan', $data['pesan'], PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mengambil daftar pesan terbaru menggunakan prepared statement SELECT.
     *
     * @return list<array{id:int|string,nama:string,email:string,pesan:string,tanggal_kirim:string}>
     */
    public function getAll(int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100)); // batasi agar tidak memuat data berlebihan
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT id, nama, email, pesan, tanggal_kirim
             FROM buku_tamu
             ORDER BY tanggal_kirim DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Menghitung panjang string dalam karakter (bukan byte) agar huruf
     * non-ASCII tetap dihitung satu karakter.
     */
    private static function panjang(string $teks): int
    {
        return function_exists('mb_strlen') ? mb_strlen($teks, 'UTF-8') : strlen($teks);
    }
}
