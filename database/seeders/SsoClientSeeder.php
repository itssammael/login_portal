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
            ['client_id' => 'lfews_client_id'],
            [
                'name' => 'LFEWS 2.0',
                'client_secret' => 'lfews_client_secret',
                'redirect_uri' => 'http://127.0.0.1:8001/sso/callback,http://localhost:8001/sso/callback',
                'is_active' => true,
            ]
        );

        SsoClient::updateOrCreate(
            ['client_id' => 'project_tracker_client_id'],
            [
                'name' => 'Project Tracker',
                'client_secret' => 'project_tracker_client_secret',
                'redirect_uri' => 'http://127.0.0.1:8002/sso/callback,http://localhost:8002/sso/callback',
                'is_active' => true,
            ]
        );
    }
}
