<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_information_can_be_updated(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->put('/user/profile-information', [
            'name' => 'Test Name',
            'email' => 'test@example.com',
            'employee_number' => 'EMP-5555',
            'position' => 'Senior Developer',
        ]);

        $this->assertEquals('Test Name', $user->fresh()->name);
        $this->assertEquals('test@example.com', $user->fresh()->email);
        $this->assertEquals('EMP-5555', $user->fresh()->employee_number);
        $this->assertEquals('Senior Developer', $user->fresh()->position);
    }
}
