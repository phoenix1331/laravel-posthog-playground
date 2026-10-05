/**
 * Keeps the browser's PostHog identity in line with who the server says is
 * logged in. Kept free of imports so it can be tested with node --test.
 *
 * - Logged in, browser not yet identified as them: identify, which links the
 *   browser's anonymous history to the user
 * - Logged in, browser identified as a different user (shared device): reset
 *   first, so two real people are never merged, then identify
 * - Logged out but the browser still identified as someone: reset, so the
 *   next visitor on this device starts as a fresh anonymous person
 * - Otherwise nothing to do
 *
 * @param {{ get_distinct_id(): string, get_property(key: string): unknown, identify(id: string, props?: object): void, reset(): void }} posthog
 * @param {{ distinctId: string, properties: object } | null | undefined} identity
 * @returns {'identified' | 'switched' | 'reset' | 'unchanged'}
 */
export function syncIdentity(posthog, identity) {
    if (identity) {
        if (posthog.get_distinct_id() === identity.distinctId) {
            return 'unchanged';
        }

        const switchingUser = posthog.get_property('$user_state') === 'identified';

        if (switchingUser) {
            posthog.reset();
        }

        posthog.identify(identity.distinctId, identity.properties);

        return switchingUser ? 'switched' : 'identified';
    }

    if (posthog.get_property('$user_state') === 'identified') {
        posthog.reset();

        return 'reset';
    }

    return 'unchanged';
}
