<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction as BaseLogActivityAction;

class LogActivityAction extends BaseLogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        $request = request();

        // Only real HTTP requests carry a route; queue, scheduler and artisan get a fake 127.0.0.1/"Symfony" request.
        if ($request->route() !== null) {
            $activity->setAttribute('ip', $activity->getAttribute('ip') ?? $request->ip());
            $activity->setAttribute('user_agent', $activity->getAttribute('user_agent') ?? $request->userAgent());
        }

        parent::beforeActivityLogged($activity);
    }
}
