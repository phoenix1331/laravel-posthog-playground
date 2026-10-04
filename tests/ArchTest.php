<?php

declare(strict_types=1);

arch('no native debugging or unsafe php functions')
    ->preset()
    ->php();

arch('no laravel debugging helpers')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();

arch('no insecure functions')
    ->preset()
    ->security();

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();
