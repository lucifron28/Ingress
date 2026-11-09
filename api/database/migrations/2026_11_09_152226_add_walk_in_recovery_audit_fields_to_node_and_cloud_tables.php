<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('node_walk_in_recoveries', function (Blueprint $table) {
            $table->boolean('identity_verified')->default(false);
            $table->string('reason', 32)->default('legacy');
        });

        Schema::table('cloud_walk_in_recoveries', function (Blueprint $table) {
            $table->boolean('identity_verified')->default(false);
            $table->string('reason', 32)->default('legacy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloud_walk_in_recoveries', function (Blueprint $table) {
            $table->dropColumn(['identity_verified', 'reason']);
        });

        Schema::table('node_walk_in_recoveries', function (Blueprint $table) {
            $table->dropColumn(['identity_verified', 'reason']);
        });
    }
};
