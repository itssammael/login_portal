<?php

namespace Database\Seeders;

use App\Models\SsoClient;
use Illuminate\Database\Seeder;

class SsoClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SsoClient::updateOrCreate(
            ['client_id' => env('LFews_SSO_CLIENT_ID', 'lfews_client_id')],
            [
                'name' => 'LFews 2.0',
                'client_secret' => env('LFews_SSO_CLIENT_SECRET', 'lfews_client_secret'),
                'redirect_uri' => env('LFews_SSO_REDIRECT_URI', 'http://127.0.0.1:8001/sso/callback,http://localhost:8001/sso/callback'),
                'is_active' => true,
            ]
        );

        SsoClient::updateOrCreate(
            ['client_id' => env('PROJECT_TRACKER_SSO_CLIENT_ID', 'project_tracker_client_id')],
            [
                'name' => 'Project Tracker',
                'client_secret' => env('PROJECT_TRACKER_SSO_CLIENT_SECRET', 'project_tracker_client_secret'),
                'redirect_uri' => env('PROJECT_TRACKER_SSO_REDIRECT_URI', 'http://127.0.0.1:8002/sso/callback,http://localhost:8002/sso/callback'),
                'is_active' => true,
            ]
        );
    }
}
