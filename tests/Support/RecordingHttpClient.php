<?php

declare(strict_types=1);

namespace Tests\Support;

use PostHog\HttpClient;
use PostHog\HttpResponse;

/**
 * Stands in for the PostHog SDK's HTTP layer: records each request and
 * answers with a fixed status, so tests exercise the real client without
 * touching the network.
 */
final class RecordingHttpClient extends HttpClient
{
    /** @var list<array{path: string, payload: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, string>  $bodies  response body by path prefix, e.g. ['/flags/' => '{"flags": {...}}']
     */
    public function __construct(
        private readonly int $status = 200,
        private readonly array $bodies = [],
    ) {
        parent::__construct('test.posthog.invalid');
    }

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = [], array $requestOptions = []): HttpResponse
    {
        $this->requests[] = ['path' => $path, 'payload' => json_decode((string) $payload, true) ?? []];

        $body = collect($this->bodies)->first(fn (string $body, string $prefix): bool => str_starts_with($path, $prefix), '{}');

        return new HttpResponse($body, $this->status);
    }

    /**
     * Requests whose path starts with the given prefix.
     *
     * @return list<array{path: string, payload: array<string, mixed>}>
     */
    public function requestsTo(string $prefix): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => str_starts_with($request['path'], $prefix)));
    }

    /**
     * Every message sent in /batch/ requests, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function batchedMessages(): array
    {
        return collect($this->requests)
            ->where('path', '/batch/')
            ->flatMap(fn (array $request): array => $request['payload']['batch'] ?? [])
            ->values()
            ->all();
    }
}
