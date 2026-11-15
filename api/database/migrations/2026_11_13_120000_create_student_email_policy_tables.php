<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_access_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('require_institutional_email')->default(false);
            $table->timestamp('policy_enabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('student_access_policy_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->string('admin_email', 255);
            $table->boolean('old_required');
            $table->boolean('new_required');
            $table->timestamp('created_at');
        });

        DB::table('student_access_settings')->insert([
            'id' => 1,
            'require_institutional_email' => false,
            'policy_enabled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_access_policy_audits');
        Schema::dropIfExists('student_access_settings');
    }
};
