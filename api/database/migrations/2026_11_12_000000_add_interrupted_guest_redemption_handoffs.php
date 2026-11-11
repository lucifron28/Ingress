<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_ticket_credential_rotations', function (Blueprint $table): void {
            $table->char('previous_redemption_hash', 64)->nullable();
            $table->unique('previous_redemption_hash', 'guest_rotation_previous_hash_uq');
            $table->char('recovery_session_hash', 64)->nullable();
            $table->text('redemption_code_ciphertext')->nullable();
            $table->timestamp('redemption_code_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guest_ticket_credential_rotations', function (Blueprint $table): void {
            $table->dropUnique('guest_rotation_previous_hash_uq');
            $table->dropColumn([
                'previous_redemption_hash',
                'recovery_session_hash',
                'redemption_code_ciphertext',
                'redemption_code_expires_at',
            ]);
        });
    }
};
