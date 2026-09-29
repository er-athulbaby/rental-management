<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateUser
{
    public function __construct(private SyncUserRoles $roles, private SyncUserBuildings $buildings) {}

    /**
     * Admin never sets passwords (spec §8.1): the user gets a random one nobody knows, plus a reset link.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $buildingIds
     */
    public function handle(User $actor, string $name, string $email, array $roles, array $buildingIds): User
    {
        if (! $actor->can(PermissionName::UsersManage)) {
            throw new AuthorizationException;
        }

        $data = Validator::make(['name' => $name, 'email' => Str::lower($email)], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ])->validate();

        $user = DB::transaction(function () use ($actor, $data, $roles, $buildingIds) {
            $user = User::create([...$data, 'password' => Str::password(40)]);

            Audit::log('user.created', $user, new: ['name' => $user->name, 'email' => $user->email], causer: $actor);

            $this->roles->handle($actor, $user, $roles);
            $this->buildings->handle($actor, $user, $buildingIds);

            return $user;
        });

        Password::broker()->sendResetLink(['email' => $user->email]);

        return $user;
    }
}
