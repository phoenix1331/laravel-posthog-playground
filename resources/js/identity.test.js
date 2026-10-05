import { test } from 'node:test';
import assert from 'node:assert/strict';
import { syncIdentity } from './identity.js';

function fakePostHog({ distinctId = 'anon-1', userState = 'anonymous' } = {}) {
    const calls = [];

    return {
        calls,
        get_distinct_id: () => distinctId,
        get_property: (key) => (key === '$user_state' ? userState : undefined),
        identify: (id, properties) => calls.push(['identify', id, properties]),
        reset: () => calls.push(['reset']),
    };
}

const identity = { distinctId: '42', properties: { supporters_club: 'harbour-city-exiles' } };

test('identifies an anonymous browser once the supporter is logged in', () => {
    const posthog = fakePostHog();

    assert.equal(syncIdentity(posthog, identity), 'identified');
    assert.deepEqual(posthog.calls, [['identify', '42', { supporters_club: 'harbour-city-exiles' }]]);
});

test('does nothing when the browser is already the logged-in supporter', () => {
    const posthog = fakePostHog({ distinctId: '42', userState: 'identified' });

    assert.equal(syncIdentity(posthog, identity), 'unchanged');
    assert.deepEqual(posthog.calls, []);
});

test('resets before identifying a different supporter on a shared browser, so two users never merge', () => {
    const posthog = fakePostHog({ distinctId: '7', userState: 'identified' });

    assert.equal(syncIdentity(posthog, identity), 'switched');
    assert.deepEqual(posthog.calls.map((call) => call.slice(0, 2)), [['reset'], ['identify', '42']]);
});

test('resets an identified browser after logout', () => {
    const posthog = fakePostHog({ distinctId: '42', userState: 'identified' });

    assert.equal(syncIdentity(posthog, null), 'reset');
    assert.deepEqual(posthog.calls, [['reset']]);
});

test('leaves an anonymous visitor alone', () => {
    const posthog = fakePostHog();

    assert.equal(syncIdentity(posthog, undefined), 'unchanged');
    assert.deepEqual(posthog.calls, []);
});
