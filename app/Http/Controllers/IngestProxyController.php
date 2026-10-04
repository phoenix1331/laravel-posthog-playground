<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Providers\AnalyticsServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse proxy for posthog-js. The browser talks to /ingest on our own
 * domain, which ad blockers leave alone, and this forwards to PostHog.
 *
 * Runs outside the web middleware group: no session, cookies or CSRF, so
 * proxied requests stay cheap and nothing of ours leaks to PostHog.
 */
class IngestProxyController extends Controller
{
    /**
     * Request headers worth passing on. Cookies and auth are deliberately
     * not forwarded.
     */
    private const array FORWARDED_HEADERS = ['Content-Type', 'Content-Encoding', 'User-Agent', 'Accept', 'Origin', 'Referer'];

    public function __invoke(Request $request): Response
    {
        // Not an open relay: the proxy only exists while the app sends to PostHog
        abort_unless(AnalyticsServiceProvider::sending(), Response::HTTP_NOT_FOUND);

        try {
            $upstream = Http::withHeaders($this->headers($request))
                ->withOptions(['decode_content' => false])
                ->timeout(10)
                ->withBody($request->getContent(), (string) $request->header('Content-Type', 'text/plain'))
                ->send($request->method(), $this->target($request));
        } catch (ConnectionException) {
            return response('PostHog is unreachable', Response::HTTP_BAD_GATEWAY);
        }

        return response($upstream->body(), $upstream->status())
            ->withHeaders(array_filter([
                'Content-Type' => $upstream->header('Content-Type'),
                'Content-Encoding' => $upstream->header('Content-Encoding'),
                'Cache-Control' => $upstream->header('Cache-Control'),
            ]));
    }

    /**
     * Forwards the raw path and query exactly as received. Laravel's route
     * parameter drops trailing slashes and getQueryString() reorders keys;
     * PostHog endpoints such as /e/ and /flags/?v=2 expect them untouched.
     *
     * Static assets (the recorder, surveys and other lazy-loaded bundles)
     * come from the assets host; everything else is ingestion or API.
     */
    private function target(Request $request): string
    {
        $prefix = '/'.trim((string) config('posthog.proxy_path'), '/');
        $rest = Str::after($request->getRequestUri(), $prefix);

        $host = Str::startsWith(ltrim($rest, '/'), ['static/', 'array/'])
            ? config('posthog.assets_host')
            : config('posthog.host');

        return rtrim((string) $host, '/').'/'.ltrim($rest, '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = collect(self::FORWARDED_HEADERS)
            ->mapWithKeys(fn (string $name): array => [$name => (string) $request->header($name)])
            ->filter()
            ->all();

        // Lets PostHog geolocate the visitor rather than this server
        $headers['X-Forwarded-For'] = (string) $request->ip();

        return $headers;
    }
}
