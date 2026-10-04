import posthog from 'posthog-js';

/**
 * Starts posthog-js when the server has rendered its settings into the page
 * (only when the app is sending to PostHog).
 *
 * - api_host points at our own /ingest proxy so ad blockers do not drop events
 * - bootstrap.distinctID is the server's anonymous ID, so client events such
 *   as $pageview land on the same person as server-side funnel events; once
 *   posthog-js has its own cookie the server adopts that ID instead
 * - session replay stays off until it is configured with input masking
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
        disable_session_recording: true,
    });

    return posthog;
}
