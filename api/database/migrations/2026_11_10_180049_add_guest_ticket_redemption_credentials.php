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
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('redemption_hash', 64)->nullable()->unique();
        });

        Schema::create('guest_ticket_credential_rotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('from_credential_version');
            $table->unsignedInteger('to_credential_version');
            $table->string('kind', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['ticket_id', 'to_credential_version']);
            $table->index(['event_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_ticket_credential_rotations');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['redemption_hash']);
            $table->dropColumn('redemption_hash');
        });
    }
};
