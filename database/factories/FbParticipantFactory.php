<?php

namespace Database\Factories;

use App\Models\FbFunction;
use App\Models\FbParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbParticipant>
 */
class FbParticipantFactory extends Factory
{
    protected $model = FbParticipant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'function_id' => FbFunction::factory(),
            'agency' => fake()->company(),
            'designation' => fake()->jobTitle(),
            'years_in_designation' => fake()->numberBetween(1, 15),
            'location' => fake()->city(),
            'no_of_exercises' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Indicate that the participant is anonymous.
     */
    public function anonymous(): static
    {
        return $this->state(fn () => [
            'name' => null,
        ]);
    }
}
