<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, $command))
        ?? throw new RuntimeException("{$command} is not scheduled");
}

test('the backup and numbering jobs are scheduled in Bahrain time without overlap', function (string $command, string $cron) {
    $event = scheduledEvent($command);

    expect($event->expression)->toBe($cron)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(config('app.timezone'))->toBe('Asia/Bahrain');
})->with([
    ['backup:clean', '15 3 * * *'],
    ['backup:run', '30 3 * * *'],
    ['backup:monitor', '0 7 * * *'],
    ['rms:number-sequences', '30 4 1 12 *'],
]);

test('a successful run pings its heartbeat exactly once', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200)]));
    $stack->push(Middleware::history($history));
    app()->instance(ClientInterface::class, new Client(['handler' => $stack]));

    config(['services.forge.heartbeats.number_sequences' => 'https://forge.test/heartbeat/abc']);
    require base_path('routes/console.php'); // re-register now that the URL is configured

    $event = collect(app(Schedule::class)->events())->last(fn ($e) => str_contains((string) $e->command, 'rms:number-sequences'));
    // Scheduled commands run in a child process, outside the test transaction: swap in a harmless
    // command so nothing is committed, keeping the heartbeat callback routes/console.php attached.
    $event->command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' --version';
    $event->run(app());

    expect($event->exitCode)->toBe(0)
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://forge.test/heartbeat/abc');
});
