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
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'timezone:all'],
        ])->validateWithBag('updateProfileInformation');

        $data = [
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null,
            'timezone' => $input['timezone'] ?? null,
        ];

        if ($input['email'] !== $user->email && $user instanceof MustVerifyEmail) {
            $user->forceFill($data + ['email_verified_at' => null])->save();
            $user->sendEmailVerificationNotification();
        } else {
            $user->forceFill($data)->save();
        }
    }
}
