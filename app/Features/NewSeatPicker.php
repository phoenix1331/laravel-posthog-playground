<?php

declare(strict_types=1);

namespace App\Features;

use App\Analytics\Flags\FlagScope;
use App\Analytics\Flags\Rollout;

/**
 * The stadium-map seat picker. Rolled out to a share of visitors in
 * PostHog under the key "new-seat-picker".
 *
 * resolve() is only the fallback for when PostHog is not configured or does
 * not know the flag. It reproduces PostHog's own bucketing, so without an
 * account the same visitors see the new picker as would with one.
 */
class NewSeatPicker
{
    public string $name = 'new-seat-picker';

    public const int ROLLOUT_PERCENTAGE = 20;

    public function resolve(FlagScope $scope): bool
    {
        return Rollout::includes($this->name, $scope->distinctId, self::ROLLOUT_PERCENTAGE);
    }
}
