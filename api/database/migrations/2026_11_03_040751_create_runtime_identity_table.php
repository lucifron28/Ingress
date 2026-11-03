<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_identity', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('mode', 16);
            $table->string('identity', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_identity');
    }
};
