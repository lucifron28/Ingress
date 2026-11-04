<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_walk_in_issuances', function (Blueprint $table) {
            $table->text('sync_package_ciphertext')->nullable();
            $table->string('sync_payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_walk_in_issuances', function (Blueprint $table) {
            $table->dropColumn(['sync_package_ciphertext', 'sync_payload_hash']);
        });
    }
};
