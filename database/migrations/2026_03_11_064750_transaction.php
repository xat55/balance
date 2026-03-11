<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->string('txid')->nullable()->index(); // хеш транзакции
            $table->string('type'); // deposit, withdraw, payment, fee
            $table->string('status')->default('pending'); // pending, completed, failed, cancelled
            $table->string('currency', 10);
            $table->decimal('amount', 20, 8); // положительное для депозита, отрицательное для вывода
            $table->decimal('fee', 20, 8)->default(0);
            $table->string('to_address')->nullable();
            $table->string('from_address')->nullable();
            $table->integer('confirmations')->default(0);
            $table->text('meta')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['txid', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
