<?php

declare(strict_types=1);

use App\Analytics\Analytics;
use App\Analytics\FakeAnalytics;
use Tests\DuskTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Feature tests boot the full Laravel application. Unit tests stay plain
| PHPUnit test cases so they run without the framework.
|
*/

pest()->extend(TestCase::class)->in('Feature');

// Browser scripts run with `make dusk` against the live app, never with `make test`
pest()->extend(DuskTestCase::class)->in('Browser');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Swap the Analytics binding for a recording fake and return it.
 */
function fakeAnalytics(): FakeAnalytics
{
    $fake = new FakeAnalytics;

    app()->instance(Analytics::class, $fake);

    return $fake;
}
