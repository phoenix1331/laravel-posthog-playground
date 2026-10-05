<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Analytics\DistinctId;
use App\Analytics\Groups;
use App\Analytics\PersonProperties;
use App\Models\User;
use App\Providers\AnalyticsServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Hands posthog-js its settings as JSON in the page, including who it should
 * be identified as. Renders nothing when
 * the app is not sending, so the client stays silent without a token.
 * Only public values belong here: never the personal or secret key.
 */
class PosthogConfig extends Component
{
    public function __construct(
        private readonly DistinctId $distinctId,
        private readonly ?User $user = null,
    ) {}

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
            'bootstrap' => $this->bootstrap(),
            'identity' => $this->identity(),
        ];
    }

    /**
     * First-visit identity for posthog-js: the user when logged in, else
     * the server's anonymous ID.
     *
     * @return array{distinctID: string, isIdentifiedID: bool}
     */
    private function bootstrap(): array
    {
        if ($this->user !== null) {
            return ['distinctID' => DistinctId::forUser($this->user), 'isIdentifiedID' => true];
        }

        return ['distinctID' => $this->distinctId->anonymous(), 'isIdentifiedID' => false];
    }

    /**
     * Who posthog-js should be identified as; null tells it to reset if it
     * is still identified from an earlier login.
     *
     * @return array{distinctId: string, properties: array<string, scalar>, groups: array<string, string>}|null
     */
    private function identity(): ?array
    {
        if ($this->user === null) {
            return null;
        }

        return [
            'distinctId' => DistinctId::forUser($this->user),
            'properties' => PersonProperties::for($this->user),
            'groups' => (object) Groups::for($this->user),
        ];
    }

    public function render(): View
    {
        return view('components.posthog-config');
    }
}
