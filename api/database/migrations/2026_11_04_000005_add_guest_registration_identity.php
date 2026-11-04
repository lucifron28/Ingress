<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('attendee_type', 16)->default('student');
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->unique(['event_id', 'guest_email']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_attendee_identity_check CHECK ((attendee_type = 'student' AND user_id IS NOT NULL AND guest_name IS NULL AND guest_email IS NULL) OR (attendee_type IN ('guest', 'alumni') AND user_id IS NULL AND guest_name IS NOT NULL AND guest_email IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('registrations')->whereNull('user_id')->exists()) {
            throw new LogicException('Cannot remove guest registration fields while guest registrations exist.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registrations DROP CONSTRAINT registrations_attendee_identity_check');
        }
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'guest_email']);
            $table->dropColumn(['attendee_type', 'guest_name', 'guest_email']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
