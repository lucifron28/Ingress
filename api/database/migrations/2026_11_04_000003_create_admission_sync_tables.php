<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_admission_batches', function (Blueprint $table) {
            $table->foreignId('event_id')->primary()->constrained('events')->restrictOnDelete();
            $table->uuid('batch_id')->unique();
            $table->text('package_ciphertext');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('node_synced_admissions', function (Blueprint $table) {
            $table->foreignId('admission_id')->primary()->constrained('admissions')->restrictOnDelete();
            $table->uuid('batch_id');
            $table->timestamp('synced_at');
        });
        Schema::create('cloud_admission_batches', function (Blueprint $table) {
            $table->uuid('batch_id')->primary();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->string('node_id', 64);
            $table->foreign('node_id')->references('id')->on('event_nodes')->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->text('receipt');
            $table->timestamp('received_at');
        });
        Schema::create('cloud_synced_admissions', function (Blueprint $table) {
            $table->string('node_id', 64);
            $table->foreign('node_id')->references('id')->on('event_nodes')->restrictOnDelete();
            $table->unsignedBigInteger('node_admission_id');
            $table->foreignId('admission_id')->unique()->constrained('admissions')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->primary(['node_id', 'node_admission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_synced_admissions');
        Schema::dropIfExists('cloud_admission_batches');
        Schema::dropIfExists('node_synced_admissions');
        Schema::dropIfExists('node_admission_batches');
    }
};
