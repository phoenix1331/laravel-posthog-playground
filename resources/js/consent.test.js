import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyConsent, consentCookie, readConsent } from './consent.js';

function fakePostHog({ optedIn = false, optedOut = false } = {}) {
    const calls = [];

    return {
        calls,
        has_opted_in_capturing: () => optedIn,
        has_opted_out_capturing: () => optedOut,
        opt_in_capturing: () => calls.push('opt_in'),
        opt_out_capturing: () => calls.push('opt_out'),
    };
}

test('reads the choice from the cookie string', () => {
    assert.equal(readConsent('a=1; analytics_consent=granted; b=2'), 'granted');
    assert.equal(readConsent('analytics_consent=denied'), 'denied');
});

test('treats a missing or unexpected value as no choice yet', () => {
    assert.equal(readConsent(''), 'unknown');
    assert.equal(readConsent('analytics_consent=maybe'), 'unknown');
    assert.equal(readConsent('other_analytics_consent=granted'), 'unknown');
});

test('writes a year-long first-party cookie', () => {
    assert.equal(consentCookie('granted'), 'analytics_consent=granted; Max-Age=31536000; Path=/; SameSite=Lax');
});

test('opts in once consent is granted', () => {
    const posthog = fakePostHog({ optedOut: true });

    assert.equal(applyConsent(posthog, 'granted'), 'opted-in');
    assert.deepEqual(posthog.calls, ['opt_in']);
});

test('does not opt in again on every page', () => {
    const posthog = fakePostHog({ optedIn: true });

    assert.equal(applyConsent(posthog, 'granted'), 'unchanged');
    assert.deepEqual(posthog.calls, []);
});

test('opts out when declined or not yet chosen', () => {
    for (const state of ['denied', 'unknown']) {
        const posthog = fakePostHog({ optedIn: true });

        assert.equal(applyConsent(posthog, state), 'opted-out');
        assert.deepEqual(posthog.calls, ['opt_out']);
    }
});
