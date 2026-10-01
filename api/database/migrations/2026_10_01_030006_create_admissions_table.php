<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('access_point_id')->constrained('access_points')->restrictOnDelete();
            $table->foreignId('scanned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('client_scan_id', 64)->unique();
            $table->timestamp('admitted_at');
            $table->timestamps();

            $table->index(['ticket_id', 'admitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
