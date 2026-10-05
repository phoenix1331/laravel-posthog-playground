/**
 * Checkout exit survey. Opens once per basket when the pointer leaves the
 * top of the window (heading for the tab bar or address bar), then posts the
 * answer to the server. Pure helpers are exported for node --test.
 */

/**
 * @param {{ type: string, relatedTarget: unknown, clientY: number }} event
 */
export function isExitIntent(event) {
    return event.type === 'mouseout' && !event.relatedTarget && event.clientY <= 0;
}

/**
 * @param {string} basketId
 */
export function storageKey(basketId) {
    return `exit-survey:${basketId}`;
}

/**
 * @param {{ getItem(key: string): string | null }} storage
 * @param {string} basketId
 */
export function alreadyShown(storage, basketId) {
    return storage.getItem(storageKey(basketId)) !== null;
}

/**
 * @param {Document} doc
 * @param {Storage} storage sessionStorage
 * @param {{ capture(event: string, properties: object): void } | null} posthog
 * @param {typeof fetch} fetchFn
 */
export function bindExitSurvey(doc, storage, posthog, fetchFn) {
    const dialog = doc.getElementById('exit-survey');

    if (!dialog) {
        return;
    }

    const { basket, url, surveyId } = dialog.dataset;
    const form = dialog.querySelector('[data-exit-survey-form]');
    const surveyEvent = (name) => {
        if (posthog && surveyId) {
            posthog.capture(name, { $survey_id: surveyId });
        }
    };

    doc.addEventListener('mouseout', (event) => {
        if (!isExitIntent(event) || alreadyShown(storage, basket) || dialog.open) {
            return;
        }

        storage.setItem(storageKey(basket), 'shown');
        dialog.showModal();
        surveyEvent('survey shown');
    });

    form.addEventListener('submit', async (event) => {
        if (event.submitter?.value === 'dismiss') {
            surveyEvent('survey dismissed');

            return;
        }

        event.preventDefault();
        const reason = new FormData(form).get('reason');
        const token = doc.querySelector('meta[name="csrf-token"]').content;

        await fetchFn(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ reason }),
        });

        form.querySelector('[data-exit-survey-thanks]').hidden = false;
        setTimeout(() => dialog.close(), 1200);
    });
}
