<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            $table->string('sha256', 64);
            $table->longText('payload_ciphertext');
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_snapshots');
    }
};
