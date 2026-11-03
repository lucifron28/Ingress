<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_event_heads', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->primary();
            $table->unsignedInteger('version')->default(0);
        });
        Schema::create('received_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('event_id');
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            $table->string('sha256', 64);
            $table->longText('payload_ciphertext');
            $table->timestamps();
            $table->unique(['event_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_snapshots');
        Schema::dropIfExists('received_event_heads');
    }
};
