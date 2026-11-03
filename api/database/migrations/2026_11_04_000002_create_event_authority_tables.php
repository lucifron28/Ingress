<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_event_authorities', function (Blueprint $table) {
            $table->foreignId('event_id')->primary()->constrained('events')->restrictOnDelete();
            $table->uuid('snapshot_id')->unique();
            $table->foreign('snapshot_id')->references('id')->on('event_snapshots')->restrictOnDelete();
            $table->string('node_id', 64);
            $table->foreign('node_id')->references('id')->on('event_nodes')->restrictOnDelete();
            $table->text('grant');
            $table->timestamp('issued_at');
        });
        Schema::create('node_event_authorities', function (Blueprint $table) {
            $table->unsignedBigInteger('cloud_event_id')->primary();
            $table->foreignId('event_id')->unique()->constrained('events')->restrictOnDelete();
            $table->uuid('snapshot_id')->unique();
            $table->unsignedInteger('version');
            $table->string('sha256', 64);
            $table->text('grant');
            $table->timestamp('activated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_event_authorities');
        Schema::dropIfExists('cloud_event_authorities');
    }
};
