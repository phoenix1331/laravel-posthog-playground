import posthog from 'posthog-js';
import { applyConsent, bindConsentBanner, readConsent } from './consent';
import { syncIdentity } from './identity';

/**
 * Starts posthog-js when the server has rendered its settings into the page
 * (only when the app is sending to PostHog).
 *
 * - api_host points at our own /ingest proxy so ad blockers do not drop events
 * - bootstrap.distinctID is the server's anonymous ID, so client events such
 *   as $pageview land on the same person as server-side funnel events; once
 *   posthog-js has its own cookie the server adopts that ID instead
 * - nothing is captured, persisted or recorded until the visitor accepts
 *   analytics in the consent banner
 * - session replay masks every input and anything marked data-ph-mask, and
 *   never records elements with the ph-no-capture class (card details)
 * - identity then follows the server: identify on login, reset on logout
 */
export function startAnalytics() {
    const element = document.getElementById('posthog-config');

    if (!element) {
        return null;
    }

    const settings = JSON.parse(element.textContent);

    posthog.init(settings.token, {
        api_host: settings.apiHost,
        ui_host: settings.uiHost,
        defaults: '2026-08-30',
        bootstrap: settings.bootstrap,
        person_profiles: 'identified_only',
        opt_out_capturing_by_default: true,
        opt_out_persistence_by_default: true,
        disable_session_recording: false,
        // Adds X-POSTHOG-SESSION-ID to our own fetch/XHR calls, so server-side
        // errors from them link to this session's replay
        tracing_headers: [window.location.hostname],
        session_recording: {
            maskAllInputs: true,
            maskTextSelector: '[data-ph-mask]',
        },
    });

    applyConsent(posthog, readConsent(document.cookie));
    syncIdentity(posthog, settings.identity);

    bindConsentBanner(document, (state) => {
        applyConsent(posthog, state);

        if (state === 'granted') {
            syncIdentity(posthog, settings.identity);
        }
    });

    return posthog;
}
