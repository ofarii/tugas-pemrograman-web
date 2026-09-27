<?php
declare(strict_types=1);

/**
 * Kelas Transaction
 * Merepresentasikan satu transaksi keuangan (deposit / withdrawal).
 * Properti dienkapsulasi (private) dan diisi lewat constructor property promotion.
 */
class Transaction
{
    public function __construct(
        private readonly string $id,
        private readonly string $type,
        private readonly float $amount
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Memproses transaksi berdasarkan jenisnya.
     * Saldo dilewatkan secara pass-by-reference agar langsung terbarui
     * di sisi pemanggil (finance.php), tanpa perlu return value saldo baru.
     */
    public function process(float &$currentBalance): bool
    {
        return match ($this->type) {
            'deposit' => $this->processDeposit($currentBalance),
            'withdrawal' => $this->processWithdrawal($currentBalance),
            default => false,
        };
    }

    private function processDeposit(float &$balance): bool
    {
        $balance += $this->amount;
        return true;
    }

    private function processWithdrawal(float &$balance): bool
    {
        // Tolak jika saldo tidak mencukupi
        if ($balance < $this->amount) {
            return false;
        }

        $balance -= $this->amount;
        return true;
    }
}