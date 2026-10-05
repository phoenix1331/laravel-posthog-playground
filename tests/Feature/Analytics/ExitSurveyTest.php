<?php

declare(strict_types=1);

use App\Analytics\Events;
use App\Enums\ExitReason;
use App\Models\Basket;
use App\Models\TicketAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->analytics = fakeAnalytics();
    $this->user = User::factory()->create();
    $allocation = TicketAllocation::factory()->create();
    $this->post(route('seats.store', $allocation->fixture), ['ticket_allocation_id' => $allocation->id, 'quantity' => 2]);
    $this->basket = Basket::sole();
});

it('puts the exit survey on the checkout page', function (): void {
    $this->actingAs($this->user)->get(route('checkout.show'))
        ->assertSee('Before you go, what stopped you?')
        ->assertSee('data-basket="'.$this->basket->id.'"', false)
        ->assertSee(ExitReason::TooExpensive->label());
});

it('records the reason on the basket and in analytics', function (): void {
    $this->actingAs($this->user)
        ->postJson(route('checkout.exit-survey'), ['reason' => 'too_expensive'])
        ->assertNoContent();

    expect($this->basket->fresh()->exit_reason)->toBe(ExitReason::TooExpensive);
    $this->analytics->assertCaptured(Events::EXIT_SURVEY_ANSWERED, fn (array $call): bool => $call['distinct_id'] === (string) $this->user->id
        && $call['properties']['exit_reason'] === 'too_expensive'
        && $call['properties']['total_pence'] === $this->basket->totalPence());
    $this->analytics->assertNotCaptured(Events::SURVEY_SENT);
});

it('also sends PostHog\'s survey event when an API survey is configured', function (): void {
    config(['posthog.surveys.checkout_exit' => '01992f1a-survey']);

    $this->actingAs($this->user)->postJson(route('checkout.exit-survey'), ['reason' => 'payment_concerns']);

    $this->analytics->assertCaptured(Events::SURVEY_SENT, fn (array $call): bool => $call['properties'] === [
        '$survey_id' => '01992f1a-survey',
        '$survey_response' => ExitReason::PaymentConcerns->label(),
    ]);
});

it('keeps the first answer for a basket and stops asking', function (): void {
    $this->actingAs($this->user)->postJson(route('checkout.exit-survey'), ['reason' => 'too_expensive']);
    $this->postJson(route('checkout.exit-survey'), ['reason' => 'other'])->assertNoContent();

    expect($this->basket->fresh()->exit_reason)->toBe(ExitReason::TooExpensive);
    $this->analytics->assertCapturedTimes(Events::EXIT_SURVEY_ANSWERED, 1);
    $this->get(route('checkout.show'))->assertDontSee('Before you go');
});

it('rejects unknown reasons', function (): void {
    $this->actingAs($this->user)->postJson(route('checkout.exit-survey'), ['reason' => 'aliens'])->assertUnprocessable();

    expect($this->basket->fresh()->exit_reason)->toBeNull();
});

it('needs a logged-in supporter with a basket', function (): void {
    $this->postJson(route('checkout.exit-survey'), ['reason' => 'other'])->assertUnauthorized();
});
