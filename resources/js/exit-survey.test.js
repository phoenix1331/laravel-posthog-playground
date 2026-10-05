import { test } from 'node:test';
import assert from 'node:assert/strict';
import { alreadyShown, isExitIntent, storageKey } from './exit-survey.js';

test('treats the pointer leaving through the top of the window as exit intent', () => {
    assert.equal(isExitIntent({ type: 'mouseout', relatedTarget: null, clientY: -2 }), true);
    assert.equal(isExitIntent({ type: 'mouseout', relatedTarget: null, clientY: 0 }), true);
});

test('ignores moving between elements or leaving through the sides and bottom', () => {
    assert.equal(isExitIntent({ type: 'mouseout', relatedTarget: {}, clientY: -2 }), false);
    assert.equal(isExitIntent({ type: 'mouseout', relatedTarget: null, clientY: 400 }), false);
    assert.equal(isExitIntent({ type: 'mouseover', relatedTarget: null, clientY: -2 }), false);
});

test('remembers per basket that the survey was shown', () => {
    const store = new Map();
    const storage = { getItem: (key) => store.get(key) ?? null };

    assert.equal(alreadyShown(storage, 'basket-1'), false);
    store.set(storageKey('basket-1'), 'shown');
    assert.equal(alreadyShown(storage, 'basket-1'), true);
    assert.equal(alreadyShown(storage, 'basket-2'), false);
});
