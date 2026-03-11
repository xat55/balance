<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BalanceService
{
    /**
     * Зачисление средств (депозит)
     */
    public function deposit($userId, $currency, $txid, $fromAddress, $toAddress, $amount, $fee = 0, $confirmations = 0)
    {
        return DB::transaction(function () use ($userId, $currency, $txid, $fromAddress, $toAddress, $amount, $fee, $confirmations) {

            if (Transaction::where('txid', $txid)->exists()) {
                throw new \Exception('Transaction already processed');
            }

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $userId, 'currency' => $currency],
                ['balance' => 0, 'locked' => 0]
            );

            $this->checkDepositRisks($fromAddress, $amount, $userId);

            $required = $this->requiredConfirmations($currency, $amount);

            $transaction = Transaction::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'txid' => $txid,
                'type' => 'deposit',
                'status' => $confirmations >= $required ? 'completed' : 'pending',
                'currency' => $currency,
                'amount' => $amount,
                'fee' => $fee,
                'from_address' => $fromAddress,
                'to_address' => $toAddress,
                'confirmations' => $confirmations,
                'meta' => ['risk_score' => $this->calculateRisk($fromAddress, $amount)]
            ]);

            if ($transaction->status === 'completed') {
                $wallet->increment('balance', $amount);
                $transaction->update(['completed_at' => now()]);
            }

            Log::info("Deposit: {$txid} for user {$userId} - {$amount} {$currency}");

            return $transaction;
        });
    }

    /**
     * Вывод средств
     */
    public function withdraw($userId, $currency, $toAddress, $amount, $type = 'withdraw')
    {
        return DB::transaction(function () use ($userId, $currency, $toAddress, $amount, $type) {

            $wallet = Wallet::where('user_id', $userId)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->available < $amount) {
                throw new \Exception('Insufficient funds');
            }

            $this->checkWithdrawalRisks($userId, $currency, $amount, $toAddress);

            $transaction = Transaction::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'type' => $type,
                'status' => 'pending',
                'currency' => $currency,
                'amount' => -$amount, // отрицательное для вывода
                'fee' => 0.0001, // комиссия
                'to_address' => $toAddress,
                'meta' => ['ip' => request()->ip()]
            ]);

            $wallet->increment('locked', $amount);

            Log::info("Withdraw initiated: user {$userId} - {$amount} {$currency}");

            return $transaction;
        });
    }

    /**
     * Подтверждение вывода
     */
    public function confirmWithdraw($transactionId, $txid)
    {
        return DB::transaction(function () use ($transactionId, $txid) {

            $transaction = Transaction::lockForUpdate()->findOrFail($transactionId);

            if ($transaction->status !== 'pending' || $transaction->amount >= 0) {
                throw new \Exception('Invalid transaction');
            }

            $wallet = Wallet::lockForUpdate()->find($transaction->wallet_id);
            $amount = abs($transaction->amount);

            if ($wallet->locked < $amount) {
                throw new \Exception('Locked balance mismatch');
            }

            $wallet->decrement('balance', $amount);
            $wallet->decrement('locked', $amount);

            $transaction->update([
                'txid' => $txid,
                'status' => 'completed',
                'completed_at' => now()
            ]);

            Log::info("Withdraw completed: {$txid}");

            return $transaction;
        });
    }

    /**
     * Отмена вывода
     */
    public function cancelWithdraw($transactionId, $reason)
    {
        return DB::transaction(function () use ($transactionId, $reason) {

            $transaction = Transaction::lockForUpdate()->findOrFail($transactionId);

            if ($transaction->status !== 'pending') {
                throw new \Exception('Can only cancel pending transactions');
            }

            $wallet = Wallet::lockForUpdate()->find($transaction->wallet_id);
            $amount = abs($transaction->amount);

            $wallet->decrement('locked', $amount);

            $transaction->update([
                'status' => 'cancelled',
                'meta' => array_merge($transaction->meta ?? [], ['cancel_reason' => $reason])
            ]);

            Log::info("Withdraw cancelled: {$transactionId} - {$reason}");

            return $transaction;
        });
    }

    /**
     * Обновление подтверждений депозита
     */
    public function updateConfirmations($txid, $newConfirmations)
    {
        return DB::transaction(function () use ($txid, $newConfirmations) {

            $transaction = Transaction::where('txid', $txid)
                ->where('type', 'deposit')
                ->where('status', 'pending')
                ->first();

            if (!$transaction) {
                return null;
            }

            $transaction->confirmations = $newConfirmations;
            $transaction->save();

            $required = $this->requiredConfirmations($transaction->currency, $transaction->amount);

            if ($newConfirmations >= $required && $transaction->status === 'pending') {
                $transaction->update(['status' => 'completed', 'completed_at' => now()]);

                Wallet::where('id', $transaction->wallet_id)->increment('balance', $transaction->amount);

                Log::info("Deposit confirmed: {$txid}");
            }

            return $transaction;
        });
    }

    private function requiredConfirmations($currency, $amount): int
    {
        if ($amount > 10000) return 12;
        if ($amount > 1000) return 6;

        return 3;
    }

    /**
     * @throws \Exception
     */
    private function checkDepositRisks($address, $amount, $userId): void
    {
        $blacklist = ['bad_address_1', 'bad_address_2'];

        if (in_array($address, $blacklist)) {
            throw new \Exception('Address is blacklisted');
        }

        if ($amount > 50000) {
            Log::warning("Large deposit: user {$userId} - {$amount} from {$address}");
        }
    }

    /**
     * @throws \Exception
     */
    private function checkWithdrawalRisks($userId, $currency, $amount, $address): void
    {
        $dailyTotal = Transaction::where('user_id', $userId)
            ->where('type', 'withdraw')
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDay())
            ->sum('amount');

        if (abs($dailyTotal) + $amount > 50000) {
            throw new \Exception('Daily withdrawal limit exceeded');
        }

        if (str_starts_with($address, 'bad')) {
            throw new \Exception('Suspicious address');
        }
    }

    private function calculateRisk($address, $amount): int
    {
        $score = 0;
        if ($amount > 10000) $score += 20;
        if ($amount > 50000) $score += 30;

        return $score;
    }
}
