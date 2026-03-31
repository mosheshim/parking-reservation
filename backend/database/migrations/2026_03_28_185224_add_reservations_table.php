<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // Foreign key to users table
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Foreign key to parking_spots table
            $table->foreignId('spot_id')->constrained('parking_spots')->cascadeOnDelete();

            // Reservation time range
            $table->timestamp('start_time');
            $table->timestamp('end_time');

            // Reservation status
            $table->enum('status', ['Booked', 'Completed'])->default('Booked');

            $table->timestamps();
        });

        // Required for combining equality + range in GiST
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Prevent overlapping active reservations for the same parking spot.
        DB::statement("
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_no_overlap_per_spot
            EXCLUDE USING GIST (
                spot_id WITH =,
                tsrange(start_time, end_time) WITH &&
            )
            WHERE (status = 'Booked')
        ");

        // Partial index to optimize queries that fetch booked reservations within a specific time range.
        // The index only includes rows where status = 'Booked', reducing index size and improving scan performance.
        // Designed for queries like:
        // WHERE status = 'Booked' AND tsrange(start_time, end_time) && tsrange(?, ?)
        DB::statement("
            CREATE INDEX reservations_booked_range_idx
            ON reservations
            USING GIST (tsrange(start_time, end_time))
            INCLUDE (spot_id)
            WHERE status = 'Booked';"
        );

        // Partial index to efficiently locate expired active reservations for background processing.
        // Used by the worker to find bookings where end_time has passed and mark them as 'Completed'.
        // The partial condition ensures only active ('Booked') rows are indexed, minimizing unnecessary index overhead.
        // Designed for queries like:
        // WHERE status = 'Booked' AND end_time < now()
        DB::statement("
            CREATE INDEX reservations_end_time_booked_idx
            ON reservations (end_time)
            WHERE status = 'Booked'
        ");

        // Enforce valid time range
        DB::statement("
            ALTER TABLE reservations
            ADD CONSTRAINT reservations_valid_time_range
            CHECK (end_time > start_time)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
