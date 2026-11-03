<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_snapshots', function (Blueprint $table) {
            $table->string('target_node_id', 64)->nullable();
            $table->foreign('target_node_id')->references('id')->on('event_nodes');
        });
    }

    public function down(): void
    {
        Schema::table('event_snapshots', function (Blueprint $table) {
            $table->dropForeign(['target_node_id']);
            $table->dropColumn('target_node_id');
        });
    }
};
