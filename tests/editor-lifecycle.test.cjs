const assert = require('node:assert/strict');
const { setImmediate: settle } = require('node:timers/promises');
const { test } = require('node:test');
const { editorHarness } = require('./support/editor-harness.cjs');

for (const outcome of ['unconfirmed', 'rejected', 'aborted']) {
    test(`editor refreshes job history after ${outcome} submission`,
        /** @returns {Promise<void>} Verify actual request handling and visible feedback. */
        async function () {
            const host = editorHarness({ outcome });
            await settle();
            assert.ok(host.button('Translate content'));
            assert.equal(host.button('Translate content').props.disabled, false);
            host.button('Translate content').props.onClick();
            await settle();
            assert.equal(host.calls.filter(
                /** @param {Object} call HTTP observation. @returns {boolean} Whether this is a submission. */
                (call) => call.method === 'POST'
            ).length, 1);
            assert.equal(host.calls.filter(
                /** @param {Object} call HTTP observation. @returns {boolean} Whether this is polling. */
                (call) => call.method === 'GET'
            ).length, 2);
            assert.equal(host.timers[0].delay, 30000);
            if (outcome === 'unconfirmed') {
                assert.equal(host.errors.length, 0);
                assert.equal(host.infos.length, 1);
            } else {
                assert.equal(host.errors.length, 1);
                if (outcome === 'aborted') {
                    assert.match(host.errors[0], /may still be running/);
                    assert.doesNotMatch(host.errors[0], /cancelled/i);
                }
            }
        }
    );
}
