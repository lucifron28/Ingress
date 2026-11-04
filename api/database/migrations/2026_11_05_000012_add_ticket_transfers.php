<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('ticket_transfers_allowed')->default(false);
        });

        Schema::create('ticket_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_public_id', 64);
            $table->foreignId('pending_ticket_id')->nullable()->unique()->constrained('tickets')->nullOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_name');
            $table->string('from_email');
            $table->string('recipient_name');
            $table->string('recipient_email');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('requested_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'requested_at']);
            $table->index(['recipient_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_transfers');
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('ticket_transfers_allowed');
        });
    }
};
