const assert = require('node:assert/strict');
const { setImmediate: settle } = require('node:timers/promises');
const { test } = require('node:test');
const { editorHarness } = require('./support/editor-harness.cjs');

/** @param {Object} overrides Fixture changes. @returns {Object} Completed snapshot-backed job. */
function completedJob(overrides = {}) {
    return {
        token: 'review-token', postId: 123, status: 'completed', acknowledged: false,
        sourceTitle: 'Lokaler Test', sourceContent: '<p>Lokaler Test</p>', sourcePreview: 'Lokaler Test',
        title: 'Local test', content: '<p>Local test</p>', preview: 'Local test', ...overrides,
    };
}

/** @param {Object} options Harness changes. @returns {Promise<Object>} Mounted editor with loaded history. */
async function ready(options = {}) {
    const host = editorHarness({ jobs: [completedJob()], ...options });
    await settle(); host.render();
    return host;
}

/** @param {Object} host Mounted editor. @returns {Object} Rendered dialog. */
function openReview(host) {
    host.button('Review translation').props.onClick();
    return host.render();
}

test('polling and cancelling a review never edit or acknowledge the result',
    /** @returns {Promise<void>} Check passive history and cancel behavior. */
    async () => {
        const host = await ready();
        await host.poll(); host.render();
        const tree = openReview(host);
        assert.equal(host.edits.length, 0);
        assert.equal(host.infos.length, 1);
        assert.doesNotMatch(JSON.stringify(tree), /dangerouslySetInnerHTML/);
        host.button('Cancel').props.onClick(); host.render();
        assert.equal(host.edits.length, 0);
        assert.ok(host.calls.every(
            /** @param {Object} call Request. @returns {boolean} Whether read-only. */
            (call) => call.method === 'GET'
        ));
    }
);

test('explicit confirmation updates title and blocks once without saving or publishing',
    /** @returns {Promise<void>} Check the only permitted write path. */
    async () => {
        const host = await ready(); openReview(host);
        const button = host.button('Apply in editor');
        assert.equal(button.props.disabled, false);
        button.props.onClick(); button.props.onClick();
        await settle(); host.render(); await host.poll();
        assert.equal(host.edits.length, 1);
        assert.equal(host.edits[0].title, 'Local test');
        assert.equal(host.edits[0].content, '<p>Local test</p>');
        assert.equal(host.edits[0].blocks.length, 1);
        assert.equal(host.calls.filter(
            /** @param {Object} call Request. @returns {boolean} Whether it is an acknowledgement. */
            (call) => call.method === 'POST' && call.path.endsWith('/ack/review-token')
        ).length, 1);
        assert.equal(host.calls.length, 3); // Initial GET, acknowledgement, final poll.
        assert.equal(host.successes.length, 1);
    }
);

for (const field of ['content', 'title', 'postId']) {
    test(`changes to ${field} after opening the preview block confirmation`,
        /** @returns {Promise<void>} Revalidate even before React renders a new button. */
        async () => {
            const host = await ready(); openReview(host);
            const click = host.button('Apply in editor').props.onClick;
            host.editor[field] = field === 'postId' ? 456 : 'Newer edit';
            click(); host.render();
            assert.equal(host.edits.length, 0);
            assert.equal(host.button('Apply in editor').props.disabled, true);
            assert.ok(host.calls.every(
                /** @param {Object} call Request. @returns {boolean} No write is permitted. */
                (call) => call.method === 'GET'
            ));
        }
    );
}

for (const variant of ['invalid', 'unknown', 'nested', 'different']) {
    test(`Gutenberg ${variant} blocks cannot be applied`,
        /** @returns {Promise<void>} Test validator outcomes without replacing the post. */
        async () => {
            /** @param {string} content Block input. @returns {Array} Simulated Gutenberg parser result. */
            function parse(content) {
                const translated = content.includes('Local test');
                const block = { name: 'core/paragraph', isValid: true, innerBlocks: [] };
                if (translated && variant === 'invalid') block.isValid = false;
                if (translated && variant === 'unknown') block.name = 'core/missing';
                if (translated && variant === 'different') block.name = 'core/heading';
                if (variant === 'nested') block.innerBlocks = [{ name: 'core/paragraph', isValid: !translated, innerBlocks: [] }];
                return [block];
            }
            const host = await ready({ parse }); openReview(host);
            assert.equal(host.button('Apply in editor').props.disabled, true);
            host.button('Apply in editor').props.onClick();
            assert.equal(host.edits.length, 0);
        }
    );
}

test('legacy results without snapshots remain reviewable but cannot replace the editor',
    /** @returns {Promise<void>} Require a new source snapshot for old jobs. */
    async () => {
        const host = await ready({ jobs: [completedJob({ sourceContent: null, sourceTitle: null })] });
        openReview(host);
        assert.equal(host.button('Apply in editor').props.disabled, true);
        assert.equal(host.edits.length, 0);
    }
);

test('an acknowledgement failure never retries or rolls back the editor change',
    /** @returns {Promise<void>} Keep an unsaved edit and report metadata failure. */
    async () => {
        const host = await ready({ ackFails: true }); openReview(host);
        host.button('Apply in editor').props.onClick(); await settle();
        assert.equal(host.edits.length, 1);
        assert.match(host.errors[0], /review could not be recorded/);
    }
);

test('a legacy synchronous response never silently applies a translation',
    /** @returns {Promise<void>} Close the previous synchronous bypass. */
    async () => {
        const host = await ready({ jobs: [], outcome: 'legacy' });
        host.button('Translate content').props.onClick(); await settle();
        assert.equal(host.edits.length, 0);
        assert.equal(host.errors.length, 1);
    }
);

test('an intentionally empty title is applied as part of the same edit',
    /** @returns {Promise<void>} Preserve explicit empty values rather than falling back. */
    async () => {
        const host = await ready({ jobs: [completedJob({ sourceTitle: '', title: '' })] });
        host.editor.title = ''; openReview(host);
        host.button('Apply in editor').props.onClick();
        assert.equal(host.edits.length, 1);
        assert.equal(host.edits[0].title, '');
    }
);
