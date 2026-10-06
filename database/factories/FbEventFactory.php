<?php

namespace Database\Factories;

use App\Models\FbEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbEvent>
 */
class FbEventFactory extends Factory
{
    protected $model = FbEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'details' => fake()->paragraph(),
            'api_key' => FbEvent::generateUniqueApiKey(),
            'created_by' => User::factory(),
        ];
    }
}
