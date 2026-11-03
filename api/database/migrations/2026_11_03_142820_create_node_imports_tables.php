<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_import_ids', function (Blueprint $table) {
            $table->string('kind', 32);
            $table->unsignedBigInteger('cloud_id');
            $table->unsignedBigInteger('local_id');
            $table->boolean('role_known')->default(false);
            $table->primary(['kind', 'cloud_id']);
            $table->unique(['kind', 'local_id']);
        });
        Schema::create('node_event_imports', function (Blueprint $table) {
            $table->unsignedBigInteger('cloud_event_id')->primary();
            $table->foreignId('event_id')->unique()->constrained('events')->restrictOnDelete();
            $table->uuid('snapshot_id')->unique();
            $table->unsignedInteger('version');
            $table->string('sha256', 64);
            $table->timestamp('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_event_imports');
        Schema::dropIfExists('node_import_ids');
    }
};
