<?php

namespace App\Livewire\Concerns;

use App\Models\User;

/** Admin components run behind the auth middleware; this gives Actions a non-null actor. */
trait WithActor
{
    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
