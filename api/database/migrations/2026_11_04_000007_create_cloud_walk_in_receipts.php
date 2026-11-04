<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_walk_in_receipts', function (Blueprint $table) {
            $table->string('node_id', 64);
            $table->foreign('node_id')->references('id')->on('event_nodes')->restrictOnDelete();
            $table->uuid('issuance_id');
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('registration_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->unique()->constrained()->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->timestamp('received_at');
            $table->primary(['node_id', 'issuance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_walk_in_receipts');
    }
};
