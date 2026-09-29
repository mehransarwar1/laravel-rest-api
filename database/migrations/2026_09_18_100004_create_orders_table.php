<?php

use App\Enums\OrderStatus;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 32)->unique();
            $table->string('status', 32)->default(OrderStatus::Pending->value);
            $table->decimal('subtotal', 12, 2)->unsigned();
            $table->decimal('tax', 12, 2)->unsigned()->default(0);
            $table->decimal('total', 12, 2)->unsigned();
            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
