<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Human Resource Management Office',
            'Municipal Disaster Risk Reduction and Management Office',
            'Municipal Planning and Development Office',
            'Accounting and Internal Audit Office',
            'Treasury Operations Department',
            'Information and Communications Technology Department',
            'General Services Office',
            'Health and Sanitation Services',
        ]);

        $words = explode(' ', $name);
        $acronym = '';
        foreach ($words as $w) {
            if (! in_array(strtolower($w), ['and', 'of', 'the'])) {
                $acronym .= strtoupper($w[0]);
            }
        }

        return [
            'name' => $name,
            'acronym' => $acronym,
            'contact_person_name' => fake()->name(),
            'contact_person_position' => fake()->jobTitle(),
            'department_phone_extension' => (string) fake()->numberBetween(100, 999),
            'email' => fake()->safeEmail(),
            'department_address' => fake()->address(),
        ];
    }
}
