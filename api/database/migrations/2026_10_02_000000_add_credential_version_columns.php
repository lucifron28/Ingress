<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which credential issuance produced an admission.
     *
     * Adds credential_version to tickets and admissions to support cryptographic
     * credential issuance versioning and rotation, ensuring retired credentials
     * preserve audit history while admissions track the active credential version.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedSmallInteger('credential_version')->default(1);
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('credential_version')->default(1);

            $table->index(['ticket_id', 'credential_version']);
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'credential_version']);
            $table->dropColumn('credential_version');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('credential_version');
        });
    }
};
