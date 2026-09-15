<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->randomElement([
                'Recruitment and Selection Section',
                'Employee Welfare and Benefits Section',
                'Operations and Monitoring Section',
                'Systems and Database Administration',
                'Network Infrastructure Section',
                'Disbursement and Payroll Section',
                'Revenue and Tax Assessment Section',
                'Emergency Response and Logistics Section',
            ]),
            'section_contact_person_name' => fake()->name(),
            'section_contact_person_position' => fake()->jobTitle(),
            'section_phone_extension' => (string) fake()->numberBetween(1000, 9999),
            'section_email' => fake()->safeEmail(),
            'section_address' => fake()->streetAddress(),
        ];
    }
}
