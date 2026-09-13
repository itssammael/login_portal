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
        Schema::create('sso', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('client_id')->unique();
            $table->string('client_secret');
            $table->text('redirect_uri');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Migrate existing rows from sso_clients if the table exists
        if (Schema::hasTable('sso_clients')) {
            $existing = DB::table('sso_clients')->get();
            foreach ($existing as $client) {
                DB::table('sso')->updateOrInsert(
                    ['client_id' => $client->client_id],
                    [
                        'name' => $client->name,
                        'client_secret' => $client->client_secret,
                        'redirect_uri' => $client->redirect_uri,
                        'is_active' => (bool) $client->is_active,
                        'created_at' => $client->created_at ?? now(),
                        'updated_at' => $client->updated_at ?? now(),
                    ]
                );
            }
        }

        // Ensure default configurations from .env are seeded
        DB::table('sso')->updateOrInsert(
            ['client_id' => env('LFEWS_SSO_CLIENT_ID', 'lfews_client_id')],
            [
                'name' => 'LFEWS 2.0',
                'client_secret' => env('LFEWS_SSO_CLIENT_SECRET', 'lfews_client_secret'),
                'redirect_uri' => env('LFEWS_SSO_REDIRECT_URI', 'http://127.0.0.1:8001/sso/callback'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('sso')->updateOrInsert(
            ['client_id' => env('PROJECT_TRACKER_SSO_CLIENT_ID', 'project_tracker_client_id')],
            [
                'name' => 'Project Tracker',
                'client_secret' => env('PROJECT_TRACKER_SSO_CLIENT_SECRET', 'project_tracker_client_secret'),
                'redirect_uri' => env('PROJECT_TRACKER_SSO_REDIRECT_URI', 'http://127.0.0.1:8002/sso/callback'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sso');
    }
};
