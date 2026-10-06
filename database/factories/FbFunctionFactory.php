<?php

namespace Database\Factories;

use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbFunction>
 */
class FbFunctionFactory extends Factory
{
    protected $model = FbFunction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'function' => fake()->jobTitle(),
            'details' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Attach the created function to one or more events.
     */
    public function forEvent(FbEvent|int $event): static
    {
        return $this->afterCreating(function (FbFunction $function) use ($event): void {
            $eventId = $event instanceof FbEvent ? $event->id : $event;
            $function->events()->syncWithoutDetaching([$eventId]);
        });
    }

    /**
     * Indicate that the function is an "Others" option.
     */
    public function others(?string $customDetail = null): static
    {
        return $this->state(fn () => [
            'function' => 'Others',
            'details' => $customDetail,
        ]);
    }
}
