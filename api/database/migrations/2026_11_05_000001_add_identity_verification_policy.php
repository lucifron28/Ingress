<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('identity_verification_level', 32)->default('standard');
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->boolean('identity_check_confirmed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropColumn('identity_check_confirmed');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('identity_verification_level');
        });
    }
};
