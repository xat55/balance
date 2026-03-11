<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\BalanceService;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BalanceController extends Controller
{
    public function __construct(private readonly BalanceService $balance)
    {
    }

    /**
     * Webhook для депозитов
     *
     * @param Request $request
     * @return Response|JsonResponse|ResponseFactory
     */
    public function depositWebhook(Request $request): Response|JsonResponse|ResponseFactory
    {
        // Проверка подписи (обязательно!)
        if (!$this->verifySignature($request)) {
            return response('Unauthorized', 401);
        }

        try {
            $tx = $this->balance->deposit(
                userId: $this->getUserByAddress($request->address),
                currency: $request->currency,
                txid: $request->txid,
                fromAddress: $request->from,
                toAddress: $request->address,
                amount: $request->amount,
                fee: $request->fee ?? 0,
                confirmations: $request->confirmations ?? 0
            );

            return response()->json(['status' => 'ok', 'transaction_id' => $tx->id]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Запрос на вывод
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function withdraw(Request $request)
    {
        $request->validate([
            'currency' => 'required|in:btc,eth,usdt',
            'address' => 'required|string',
            'amount' => 'required|numeric|min:0.001'
        ]);

        try {
            $tx = $this->balance->withdraw(
                userId: auth()->id(),
                currency: $request->currency,
                toAddress: $request->address,
                amount: $request->amount
            );

            return response()->json([
                'success' => true,
                'transaction_id' => $tx->id,
                'status' => $tx->status
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Баланс пользователя
     *
     * @return JsonResponse
     */
    public function balance(): JsonResponse
    {
        $wallets = Wallet::where('user_id', auth()->id())->get();

        return response()->json(
            $wallets->mapWithKeys(fn($w) => [
                $w->currency => [
                    'balance' => $w->balance,
                    'locked' => $w->locked,
                    'available' => $w->available
                ]
            ])
        );
    }
}
