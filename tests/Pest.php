<?php

declare(strict_types=1);

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
