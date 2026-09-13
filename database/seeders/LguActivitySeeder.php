<?php

namespace Database\Seeders;

use App\Models\LguActivity;
use Illuminate\Database\Seeder;

class LguActivitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        $year = $now->year;
        $month = $now->month;

        $activities = [
            [
                'title' => 'Barangay Health & Wellness Medical Outreach',
                'description' => 'Free medical consultation, pediatric checkups, dental screening, and maintenance medicine distribution for seniors and residents.',
                'category' => 'Health',
                'date' => sprintf('%04d-%02d-05', $year, $month),
                'time' => '08:00 AM - 01:00 PM',
                'location' => 'Municipal Gymnasium',
                'status' => 'completed',
                'organizer' => 'City Health Office',
            ],
            [
                'title' => 'Coastal Clean-Up & Mangrove Tree Planting',
                'description' => 'Community environmental drive aimed at coastal preservation and ecological sustainability. Volunteers will be provided tools and snacks.',
                'category' => 'Environment',
                'date' => sprintf('%04d-%02d-12', $year, $month),
                'time' => '06:30 AM - 10:30 AM',
                'location' => 'Seaside Eco-Park & Estuary',
                'status' => 'completed',
                'organizer' => 'Environment & Natural Resources Office',
            ],
            [
                'title' => 'LGU Youth Leadership & Digital Skills Forum',
                'description' => 'Interactive workshop focusing on entrepreneurship, AI literacy, and local youth civic engagement.',
                'category' => 'Education',
                'date' => sprintf('%04d-%02d-18', $year, $month),
                'time' => '09:00 AM - 03:00 PM',
                'location' => 'Legislative Hall, 3rd Floor',
                'status' => 'upcoming',
                'organizer' => 'Local Youth Development Council',
            ],
            [
                'title' => 'Public Consultation: 2027 Annual Investment Plan',
                'description' => 'Open town hall meeting for civic stakeholders, business sector, and community organizations to review priorities.',
                'category' => 'Governance',
                'date' => sprintf('%04d-%02d-23', $year, $month),
                'time' => '01:30 PM - 05:00 PM',
                'location' => 'City Cultural Center',
                'status' => 'upcoming',
                'organizer' => 'Office of the Municipal Mayor',
            ],
            [
                'title' => 'Mobile Civil Registrar & One-Stop Citizen Caravan',
                'description' => 'On-site processing of birth certificates, business permits, ID registrations, and social welfare certifications.',
                'category' => 'Public Service',
                'date' => sprintf('%04d-%02d-27', $year, $month),
                'time' => '08:00 AM - 04:00 PM',
                'location' => 'North District Covered Court',
                'status' => 'upcoming',
                'organizer' => 'Civil Registry Department',
            ],
        ];

        foreach ($activities as $data) {
            LguActivity::updateOrCreate(
                [
                    'title' => $data['title'],
                    'date' => $data['date'],
                ],
                $data
            );
        }
    }
}
