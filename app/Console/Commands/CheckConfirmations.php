<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\BalanceService;
use Illuminate\Console\Command;

class CheckConfirmations extends Command
{
    protected $signature = 'crypto:check';
    protected $description = 'Check transaction confirmations';

    public function handle(BalanceService $balance)
    {
        $transactions = Transaction::where('type', 'deposit')
            ->where('status', 'pending')
            ->get();

        foreach ($transactions as $tx) {
            $currentConfirmations = $this->getConfirmations($tx->txid);

            if ($currentConfirmations > $tx->confirmations) {
                $balance->updateConfirmations($tx->txid, $currentConfirmations);
                $this->info("Updated {$tx->txid}: {$currentConfirmations} confirmations");
            }
        }
    }

    private function getConfirmations($txid)
    {
        // Заглушка - реальный вызов API
        return 6;
    }
}
