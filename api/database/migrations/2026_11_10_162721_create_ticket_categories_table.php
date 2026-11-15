<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'registration_eligibility')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->string('registration_eligibility', 16)->default('public');
            });
        }

        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('PHP');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('eligibility', 16)->default('public');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'name']);
            $table->index(['event_id', 'is_active']);
        });

        if (! Schema::hasColumn('registrations', 'ticket_category_id')) {
            Schema::table('registrations', function (Blueprint $table): void {
                $table->foreignId('ticket_category_id')->nullable()->constrained('ticket_categories')->restrictOnDelete();
            });
        }

        DB::table('events')->orderBy('id')->chunkById(100, function ($events): void {
            foreach ($events as $event) {
                $now = now();
                $categoryId = DB::table('ticket_categories')->insertGetId([
                    'event_id' => $event->id,
                    'name' => 'General Admission',
                    'price_minor' => 0,
                    'currency' => 'PHP',
                    'capacity' => null,
                    'eligibility' => 'public',
                    'is_active' => true,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('registrations')->where('event_id', $event->id)->update([
                    'ticket_category_id' => $categoryId,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ticket_category_id');
        });

        Schema::dropIfExists('ticket_categories');

        if (Schema::hasColumn('events', 'registration_eligibility')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->dropColumn('registration_eligibility');
            });
        }
    }
};
