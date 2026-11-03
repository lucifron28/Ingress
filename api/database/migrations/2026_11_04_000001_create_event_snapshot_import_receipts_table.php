<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_snapshot_import_receipts', function (Blueprint $table) {
            $table->uuid('snapshot_id')->primary();
            $table->foreign('snapshot_id')->references('id')->on('event_snapshots')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->foreign('node_id')->references('id')->on('event_nodes');
            $table->string('sha256', 64);
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_snapshot_import_receipts');
    }
};
