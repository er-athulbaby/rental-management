<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Called by Fortify's NewPasswordController after the token is verified.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        if (! $user->active) {
            throw ValidationException::withMessages(['email' => __('passwords.user')]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        $user->logoutEverywhere();
    }
}
