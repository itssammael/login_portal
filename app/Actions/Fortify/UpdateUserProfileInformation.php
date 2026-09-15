<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
            'position' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['nullable', 'string', 'max:255'],
            'section_id' => ['nullable', 'exists:sections,id'],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        $extraData = [
            'position' => $input['position'] ?? $user->position,
            'employee_number' => $input['employee_number'] ?? $user->employee_number,
        ];
        if (array_key_exists('section_id', $input)) {
            $extraData['section_id'] = $input['section_id'];
        }

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, array_merge($input, $extraData));
        } else {
            $user->forceFill(array_merge([
                'name' => $input['name'],
                'email' => $input['email'],
            ], $extraData))->save();
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'position' => $input['position'] ?? $user->position,
            'employee_number' => $input['employee_number'] ?? $user->employee_number,
            'section_id' => array_key_exists('section_id', $input) ? $input['section_id'] : $user->section_id,
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
