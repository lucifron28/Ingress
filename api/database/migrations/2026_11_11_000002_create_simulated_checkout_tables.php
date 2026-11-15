<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registration_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 36);
            $table->string('attendee_type', 16);
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16)->default('pending');
            $table->timestamp('reservation_expires_at');
            $table->text('redemption_code_ciphertext')->nullable();
            $table->timestamp('redemption_code_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'idempotency_key']);
            $table->index(['event_id', 'status', 'reservation_expires_at']);
            $table->index(['ticket_category_id', 'status', 'reservation_expires_at']);
            $table->index(['user_id', 'event_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->uuid('attempt_key');
            $table->string('payment_method', 16);
            $table->string('status', 16)->default('pending');
            $table->string('outcome_code', 48)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['checkout_order_id', 'attempt_number']);
            $table->unique(['checkout_order_id', 'attempt_key']);
            $table->index(['checkout_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('checkout_orders');
    }
};
