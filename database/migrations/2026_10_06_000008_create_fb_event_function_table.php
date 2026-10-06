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
        Schema::create('fb_event_function', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('fb_events')->cascadeOnDelete();
            $table->foreignId('function_id')->constrained('fb_functions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'function_id']);
        });

        // Migrate existing event assignments from fb_functions to the pivot table
        if (Schema::hasColumn('fb_functions', 'event_id')) {
            $existingFunctions = DB::table('fb_functions')
                ->whereNotNull('event_id')
                ->get(['id', 'event_id', 'created_at', 'updated_at']);

            foreach ($existingFunctions as $func) {
                // Verify event exists to respect foreign key constraint
                $eventExists = DB::table('fb_events')->where('id', $func->event_id)->exists();
                if ($eventExists) {
                    DB::table('fb_event_function')->insertOrIgnore([
                        'event_id' => $func->event_id,
                        'function_id' => $func->id,
                        'created_at' => $func->created_at ?? now(),
                        'updated_at' => $func->updated_at ?? now(),
                    ]);
                }
            }

            // Drop foreign key and column from fb_functions
            Schema::table('fb_functions', function (Blueprint $table) {
                $table->dropForeign(['event_id']);
                $table->dropColumn('event_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('fb_functions', 'event_id')) {
            Schema::table('fb_functions', function (Blueprint $table) {
                $table->foreignId('event_id')->nullable()->after('details')->constrained('fb_events')->cascadeOnDelete();
            });

            // Restore first event relationship from pivot table
            $pivotAssignments = DB::table('fb_event_function')->get(['event_id', 'function_id']);
            foreach ($pivotAssignments as $assignment) {
                DB::table('fb_functions')
                    ->where('id', $assignment->function_id)
                    ->whereNull('event_id')
                    ->update(['event_id' => $assignment->event_id]);
            }
        }

        Schema::dropIfExists('fb_event_function');
    }
};
