<?php

declare(strict_types=1);

namespace App\Analytics;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * Test double that records every call so tests can assert on what would
 * have been sent. Swap it in with the fakeAnalytics() Pest helper.
 */
final class FakeAnalytics implements Analytics
{
    /** @var list<array{distinct_id: string, event: string, properties: array<string, scalar>, groups: array<string, string>, timestamp: CarbonInterface|null}> */
    private array $captured = [];

    /** @var list<array{distinct_id: string, properties: array<string, scalar>}> */
    private array $identified = [];

    /** @var list<array{distinct_id: string, alias: string}> */
    private array $aliased = [];

    /** @var list<array{group_type: string, group_key: string, properties: array<string, scalar>}> */
    private array $groupsIdentified = [];

    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        array $groups = [],
        ?CarbonInterface $timestamp = null,
    ): void {
        $this->captured[] = [
            'distinct_id' => $distinctId,
            'event' => $event,
            'properties' => $properties,
            'groups' => $groups,
            'timestamp' => $timestamp,
        ];
    }

    public function identify(string $distinctId, array $properties = []): void
    {
        $this->identified[] = ['distinct_id' => $distinctId, 'properties' => $properties];
    }

    public function alias(string $distinctId, string $alias): void
    {
        $this->aliased[] = ['distinct_id' => $distinctId, 'alias' => $alias];
    }

    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        $this->groupsIdentified[] = ['group_type' => $groupType, 'group_key' => $groupKey, 'properties' => $properties];
    }

    /**
     * Names of every captured event, in the order they were captured.
     *
     * @return list<string>
     */
    public function capturedEvents(): array
    {
        return array_column($this->captured, 'event');
    }

    /**
     * Captured calls for one event, optionally filtered.
     *
     * @param  (callable(array{distinct_id: string, event: string, properties: array<string, scalar>, groups: array<string, string>, timestamp: CarbonInterface|null}): bool)|null  $callback
     * @return Collection<int, array{distinct_id: string, event: string, properties: array<string, scalar>, groups: array<string, string>, timestamp: CarbonInterface|null}>
     */
    public function captured(string $event, ?callable $callback = null): Collection
    {
        return collect($this->captured)
            ->where('event', $event)
            ->filter($callback ?? fn (): bool => true)
            ->values();
    }

    /**
     * @param  (callable(array{distinct_id: string, event: string, properties: array<string, scalar>, groups: array<string, string>, timestamp: CarbonInterface|null}): bool)|null  $callback
     */
    public function assertCaptured(string $event, ?callable $callback = null): void
    {
        Assert::assertTrue(
            $this->captured($event, $callback)->isNotEmpty(),
            "Expected analytics event [{$event}] was not captured.",
        );
    }

    public function assertCapturedTimes(string $event, int $times): void
    {
        $count = $this->captured($event)->count();

        Assert::assertSame($times, $count, "Analytics event [{$event}] was captured {$count} times instead of {$times}.");
    }

    public function assertNotCaptured(string $event): void
    {
        Assert::assertTrue(
            $this->captured($event)->isEmpty(),
            "Unexpected analytics event [{$event}] was captured.",
        );
    }

    public function assertNothingCaptured(): void
    {
        $events = implode(', ', array_column($this->captured, 'event'));

        Assert::assertEmpty($this->captured, "Expected no analytics events, captured: {$events}.");
    }

    /**
     * @param  (callable(array<string, scalar>): bool)|null  $callback  receives the person properties
     */
    public function assertIdentified(string $distinctId, ?callable $callback = null): void
    {
        $matches = collect($this->identified)
            ->where('distinct_id', $distinctId)
            ->filter(fn (array $call): bool => $callback === null || $callback($call['properties']));

        Assert::assertTrue($matches->isNotEmpty(), "Expected [{$distinctId}] to be identified.");
    }

    /**
     * IDs aliased to a distinct ID, in the order they were aliased.
     *
     * @return list<string>
     */
    public function aliasesOf(string $distinctId): array
    {
        return array_values(array_column(
            array_filter($this->aliased, fn (array $call): bool => $call['distinct_id'] === $distinctId),
            'alias',
        ));
    }

    public function assertAliased(string $distinctId, string $alias): void
    {
        Assert::assertContains(
            ['distinct_id' => $distinctId, 'alias' => $alias],
            $this->aliased,
            "Expected [{$distinctId}] to be aliased to [{$alias}].",
        );
    }

    public function assertGroupIdentified(string $groupType, string $groupKey): void
    {
        $matches = collect($this->groupsIdentified)->where('group_type', $groupType)->where('group_key', $groupKey);

        Assert::assertTrue($matches->isNotEmpty(), "Expected group [{$groupType}:{$groupKey}] to be identified.");
    }
}
