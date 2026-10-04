<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Analytics\DistinctId;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Hands posthog-js its settings as JSON in the page. Renders nothing when
 * the app is not sending, so the client stays silent without a token.
 * Only public values belong here: never the personal or secret key.
 */
class PosthogConfig extends Component
{
    public function __construct(private readonly DistinctId $distinctId) {}

    public function shouldRender(): bool
    {
        return AnalyticsServiceProvider::sending();
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'token' => config('posthog.project_token'),
            'apiHost' => '/'.config('posthog.proxy_path'),
            'uiHost' => config('posthog.ui_host'),
            'bootstrap' => [
                'distinctID' => $this->distinctId->anonymous(),
            ],
        ];
    }

    public function render(): View
    {
        return view('components.posthog-config');
    }
}
