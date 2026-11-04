<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('re_entry_allowed')->default(false)->after('ends_at');
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->string('direction', 8)->default('entry')->after('credential_version');
            $table->index(['ticket_id', 'id'], 'admissions_ticket_movement_index');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropIndex('admissions_ticket_movement_index');
            $table->dropColumn('direction');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('re_entry_allowed');
        });
    }
};
