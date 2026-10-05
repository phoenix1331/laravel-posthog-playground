/**
 * Analytics consent, stored in a first-party "analytics_consent" cookie the
 * server also reads. Kept free of imports so it can be tested with
 * node --test.
 */

export const CONSENT_COOKIE = 'analytics_consent';

const ONE_YEAR_SECONDS = 60 * 60 * 24 * 365;

/**
 * @param {string} cookieString document.cookie
 * @returns {'granted' | 'denied' | 'unknown'}
 */
export function readConsent(cookieString) {
    const match = cookieString
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(`${CONSENT_COOKIE}=`));

    const value = match ? decodeURIComponent(match.slice(CONSENT_COOKIE.length + 1)) : '';

    return value === 'granted' || value === 'denied' ? value : 'unknown';
}

/**
 * @param {'granted' | 'denied'} state
 * @returns {string} a value for document.cookie
 */
export function consentCookie(state) {
    return `${CONSENT_COOKIE}=${state}; Max-Age=${ONE_YEAR_SECONDS}; Path=/; SameSite=Lax`;
}

/**
 * Brings posthog-js in line with the visitor's choice. posthog-js starts
 * opted out (no capture, cookies or replay) until consent is granted.
 *
 * @param {{ has_opted_in_capturing(): boolean, has_opted_out_capturing(): boolean, opt_in_capturing(): void, opt_out_capturing(): void }} posthog
 * @param {'granted' | 'denied' | 'unknown'} state
 * @returns {'opted-in' | 'opted-out' | 'unchanged'}
 */
export function applyConsent(posthog, state) {
    if (state === 'granted' && !posthog.has_opted_in_capturing()) {
        posthog.opt_in_capturing();

        return 'opted-in';
    }

    if (state !== 'granted' && !posthog.has_opted_out_capturing()) {
        posthog.opt_out_capturing();

        return 'opted-out';
    }

    return 'unchanged';
}

/**
 * Wires the banner's buttons and the footer "Analytics preferences" link.
 *
 * @param {Document} doc
 * @param {(state: 'granted' | 'denied') => void} onChoice
 */
export function bindConsentBanner(doc, onChoice) {
    const banner = doc.getElementById('consent-banner');

    if (!banner) {
        return;
    }

    banner.querySelectorAll('[data-consent]').forEach((button) => {
        button.addEventListener('click', () => {
            const state = button.dataset.consent;

            doc.cookie = consentCookie(state);
            banner.hidden = true;
            onChoice(state);
        });
    });

    doc.querySelectorAll('[data-consent-preferences]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            banner.hidden = false;
        });
    });
}
