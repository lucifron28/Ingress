<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_walk_in_recoveries', function (Blueprint $table) {
            $table->text('sync_package_ciphertext')->nullable();
            $table->string('sync_payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
        });
        Schema::create('cloud_walk_in_recoveries', function (Blueprint $table) {
            $table->string('node_id', 64);
            $table->foreign('node_id')->references('id')->on('event_nodes')->restrictOnDelete();
            $table->unsignedBigInteger('node_recovery_id');
            $table->uuid('issuance_id');
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('recovered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('recovered_at');
            $table->string('payload_hash', 64);
            $table->timestamp('received_at');
            $table->primary(['node_id', 'node_recovery_id']);
            $table->foreign(['node_id', 'issuance_id'])->references(['node_id', 'issuance_id'])->on('cloud_walk_in_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_walk_in_recoveries');
        Schema::table('node_walk_in_recoveries', function (Blueprint $table) {
            $table->dropColumn(['sync_package_ciphertext', 'sync_payload_hash', 'synced_at']);
        });
    }
};
