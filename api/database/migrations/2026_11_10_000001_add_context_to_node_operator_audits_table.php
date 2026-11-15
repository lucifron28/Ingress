<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_operator_audits', function (Blueprint $table) {
            $table->unsignedBigInteger('affected_user_id')->nullable();
            $table->unsignedBigInteger('affected_event_id')->nullable();
            $table->string('node_id', 64)->nullable();
            $table->string('outcome', 24)->default('succeeded');
            $table->index(['affected_event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('node_operator_audits', function (Blueprint $table) {
            $table->dropIndex(['affected_event_id', 'created_at']);
            $table->dropColumn(['affected_user_id', 'affected_event_id', 'node_id', 'outcome']);
        });
    }
};
