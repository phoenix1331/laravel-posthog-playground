<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Analytics\Consent;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Accept/decline banner for analytics and session replay. Rendered only
 * while the app is sending to PostHog; visible only until the visitor has
 * chosen, so there is no flash of the banner for returning visitors. The
 * footer "Analytics preferences" link re-opens it.
 */
class ConsentBanner extends Component
{
    public function shouldRender(): bool
    {
        return AnalyticsServiceProvider::sending();
    }

    public function chosen(): bool
    {
        return Consent::state() !== Consent::UNKNOWN;
    }

    public function render(): View
    {
        return view('components.consent-banner');
    }
}
