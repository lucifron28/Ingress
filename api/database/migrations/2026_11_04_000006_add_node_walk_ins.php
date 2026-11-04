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
            $table->string('walk_in_kind', 16)->nullable();
            $table->string('guest_student_id')->nullable();
            $table->unique(['event_id', 'guest_student_id']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registrations DROP CONSTRAINT registrations_attendee_identity_check');
            DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_attendee_identity_check CHECK ((attendee_type = 'student' AND user_id IS NOT NULL AND guest_name IS NULL AND guest_email IS NULL AND walk_in_kind IS NULL AND guest_student_id IS NULL) OR (attendee_type IN ('guest', 'alumni') AND user_id IS NULL AND guest_name IS NOT NULL AND guest_email IS NOT NULL AND walk_in_kind IS NULL AND guest_student_id IS NULL) OR (attendee_type = 'walk_in' AND user_id IS NULL AND guest_name IS NOT NULL AND guest_email IS NOT NULL AND walk_in_kind IS NOT NULL AND ((walk_in_kind = 'student' AND guest_student_id IS NOT NULL) OR (walk_in_kind IN ('guest', 'alumni') AND guest_student_id IS NULL))))");
        }
        Schema::create('node_walk_in_issuances', function (Blueprint $table) {
            $table->id();
            $table->uuid('issuance_id')->unique();
            $table->uuid('client_request_id')->unique();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('registration_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('registrations')->where('attendee_type', 'walk_in')->exists()) {
            throw new LogicException('Cannot remove walk-in fields while walk-in registrations exist.');
        }
        Schema::dropIfExists('node_walk_in_issuances');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registrations DROP CONSTRAINT registrations_attendee_identity_check');
            DB::statement("ALTER TABLE registrations ADD CONSTRAINT registrations_attendee_identity_check CHECK ((attendee_type = 'student' AND user_id IS NOT NULL AND guest_name IS NULL AND guest_email IS NULL) OR (attendee_type IN ('guest', 'alumni') AND user_id IS NULL AND guest_name IS NOT NULL AND guest_email IS NOT NULL))");
        }
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'guest_student_id']);
            $table->dropColumn(['walk_in_kind', 'guest_student_id']);
        });
    }
};
