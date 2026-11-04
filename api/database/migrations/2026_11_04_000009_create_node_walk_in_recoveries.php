<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_walk_in_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issuance_id')->constrained('node_walk_in_issuances')->restrictOnDelete();
            $table->foreignId('recovered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('recovered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_walk_in_recoveries');
    }
};
