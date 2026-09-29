<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use App\Notifications\UserEmailChanged;
use App\Support\Approvers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateUserProfile
{
    public function handle(User $actor, User $user, string $name, string $email): void
    {
        if (! $actor->can('update', $user)) {
            throw new AuthorizationException;
        }

        $data = Validator::make(['name' => $name, 'email' => Str::lower($email)], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ])->validate();

        $oldEmail = $user->email;

        DB::transaction(function () use ($actor, $user, $data, $oldEmail) {
            $user->fill($data)->save(); // model logging records 'updated' with old/new

            if ($oldEmail !== $user->email) {
                Audit::log('user.email.changed', $user, ['email' => $oldEmail], ['email' => $user->email], causer: $actor);
            }
        });

        if ($oldEmail !== $user->email) {
            $notice = new UserEmailChanged($user, $oldEmail, $user->email, $actor);
            Notification::route('mail', $oldEmail)->notify($notice);
            Notification::send(Approvers::notifiable($actor, $user), $notice);
        }
    }
}
