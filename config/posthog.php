<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | When true, nothing is sent to PostHog even if a token is set. With no
    | project token the app also falls back to the null analytics driver, so
    | it runs without a PostHog account.
    |
    */

    'disabled' => (bool) env('POSTHOG_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Project
    |--------------------------------------------------------------------------
    |
    | project_token: the public project API key (phc_...), safe to expose to
    | the browser. secret_key: a personal API key (phx_...) or project secret
    | key (phs_...) used server-side only, for local feature flag evaluation.
    |
    */

    'project_token' => env('POSTHOG_PROJECT_TOKEN'),

    'secret_key' => env('POSTHOG_PERSONAL_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Hosts (PostHog Cloud EU by default)
    |--------------------------------------------------------------------------
    |
    | host: ingestion and API. assets_host: static files such as the
    | session replay recorder, served through the /ingest reverse proxy.
    | ui_host: the PostHog app, used by posthog-js for toolbar and links
    | when requests go through the proxy.
    |
    */

    'host' => env('POSTHOG_HOST', 'https://eu.i.posthog.com'),

    'assets_host' => env('POSTHOG_ASSETS_HOST', 'https://eu-assets.i.posthog.com'),

    'ui_host' => env('POSTHOG_UI_HOST', 'https://eu.posthog.com'),

    /*
    |--------------------------------------------------------------------------
    | Reverse proxy
    |--------------------------------------------------------------------------
    |
    | Client events are sent to this path on our own domain and forwarded to
    | PostHog, so ad blockers that block *.posthog.com do not drop them.
    |
    */

    'proxy_path' => env('POSTHOG_PROXY_PATH', 'ingest'),

    /*
    |--------------------------------------------------------------------------
    | Server-side capture
    |--------------------------------------------------------------------------
    |
    | Capture runs on the queue, never in the request. The worker listens on
    | this queue ahead of "default" (see docker-compose.yml).
    |
    */

    'queue' => env('POSTHOG_QUEUE', 'analytics'),

    /*
    |--------------------------------------------------------------------------
    | SDK client options
    |--------------------------------------------------------------------------
    |
    | Passed to PostHog\Client. Timeouts are in milliseconds. lib_curl sends
    | batches synchronously, which is fine because capture already runs on
    | the queue.
    |
    */

    'client' => [
        'consumer' => env('POSTHOG_CONSUMER', 'lib_curl'),
        'timeout' => (int) env('POSTHOG_TIMEOUT_MS', 3000),
        'feature_flag_request_timeout_ms' => (int) env('POSTHOG_FLAG_TIMEOUT_MS', 1500),
        'debug' => (bool) env('POSTHOG_DEBUG', false),
    ],

];
