<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/** Admin components run behind the auth middleware; this gives Actions a non-null actor. */
trait WithActor
{
    protected function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Runs $run signed in as $as, then puts back whoever was signed in, even when $run throws. Lets a report package
     * run a ready-made report as the person it is for (spec 2026-10-17 §4).
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    protected function asActor(User $as, Closure $run): mixed
    {
        $previous = Auth::hasUser() ? Auth::user() : null;
        Auth::setUser($as);
        try {
            return $run();
        } finally {
            if ($previous === null) {
                Auth::forgetUser();
            } else {
                Auth::setUser($previous);
            }
        }
    }
}
