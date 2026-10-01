<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which credential issuance produced an admission.
     *
     * A cancelled registration is reactivated on re-registration, so the
     * `tickets` row (and therefore its admission history) is reused and the
     * credential is rotated in place. `credential_version` increments on every
     * rotation so the admission recorded under a retired credential no longer
     * gates the freshly issued one.
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
