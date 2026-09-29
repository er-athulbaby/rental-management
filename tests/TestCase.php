<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** DatabaseTruncation runs TRUNCATE, which the app user may not do (spec §8.5). */
    protected array $connectionsToTruncate = ['migrator'];

    protected function setUp(): void
    {
        parent::setUp();

        // Views call @vite; tests never need built assets.
        $this->withoutVite();
    }

    /**
     * RefreshDatabase and DatabaseTruncation migrate as the DDL user; tests query as the app user.
     * (Overriding migrateFreshUsing() does not work: the trait Pest mixes in wins over this class.)
     */
    public function artisan($command, $parameters = [])
    {
        if ($command === 'migrate:fresh') {
            $parameters['--database'] ??= 'migrator';
        }

        return parent::artisan($command, $parameters);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
