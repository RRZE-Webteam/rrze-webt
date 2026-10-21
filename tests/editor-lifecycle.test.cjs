const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { setImmediate: settle } = require('node:timers/promises');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');
const { join } = require('node:path');

/**
 * Run the actual editor script against a minimal WordPress host with no network.
 * Hooks render once; these tests inspect request/notices behavior, not React layout.
 * @param {string} outcome Simulated REST result: unconfirmed, rejected or aborted.
 * @returns {Object} Captured button, requests, notices and timers.
 */
function editorHarness(outcome) {
    const calls = [];
    const errors = [];
    const infos = [];
    const effects = [];
    const timers = [];
    let plugin;
    let button;

    /** @returns {void} Deliberately inert host operation. */
    function noop() {}
    /** @returns {null} No persisted language preference. */
    function noPreference() { return null; }
    /** @param {*} value Lazy state or literal. @returns {*} Its initial value. */
    function initial(value) { return typeof value === 'function' ? value() : value; }
    /** @param {*} value State initializer. @returns {Array} Value and inert setter. */
    function useState(value) { return [initial(value), noop]; }
    /** @param {*} value Ref initializer. @returns {Object} Mutable ref. */
    function useRef(value) { return { current: value }; }
    /** @param {Function} effect Mount effect. @returns {void} */
    function useEffect(effect) { effects.push(effect); }
    /** @param {string} message Notice text. @returns {void} */
    function errorNotice(message) { errors.push(message); }
    /** @param {string} message Notice text. @returns {void} */
    function infoNotice(message) { infos.push(message); }
    /** @param {string} message Translation source. @returns {string} Untranslated text. */
    function translate(message) { return message; }
    /** @param {Function} callback Timer body. @param {number} delay Delay. @returns {number} Handle. */
    function timeout(callback, delay) { timers.push({ callback, delay }); return timers.length; }
    /** @param {string} name Plugin name. @param {Object} definition Plugin definition. @returns {void} */
    function registerPlugin(name, definition) { plugin = definition; }
    /** @param {*} type Element type. @param {Object} props Props. @param {...*} children Children. @returns {Object} Rendered node. */
    function createElement(type, props, ...children) {
        if (typeof type === 'function') return type(props);
        const node = { type, props, children };
        if (type === 'Button' && props.onClick) button = node;
        return node;
    }
    /** @returns {string} Disposable editor content. */
    function content() { return '<p>Lokaler Test</p>'; }
    /** @returns {string} Disposable title. */
    function title() { return 'Lokaler Test'; }
    /** @returns {number} Synthetic post ID, never sent to a server. */
    function postId() { return 123; }
    /** @returns {Object} Minimal core/editor store. */
    function select() { return { getEditedPostContent: content, getEditedPostAttribute: title, getCurrentPostId: postId }; }
    /** @returns {Object} Editor/notices operations. */
    function dispatch() { return { editPost: noop, createSuccessNotice: noop, createErrorNotice: errorNotice, createInfoNotice: infoNotice }; }
    /** @param {Object} request REST request. @returns {Promise<Object>} Deterministic response. */
    async function apiFetch(request) {
        calls.push(request);
        if (request.method === 'GET') return { jobs: [] };
        if (outcome === 'unconfirmed') return { token: 'fixture-token', jobId: '', status: 'pending', reused: true };
        const error = new Error(outcome === 'aborted' ? 'Request aborted' : 'Provider rejected request');
        if (outcome === 'aborted') error.name = 'AbortError';
        throw error;
    }
    const wp = {
        plugins: { registerPlugin },
        editPost: { PluginSidebar: 'Sidebar', PluginSidebarMoreMenuItem: 'SidebarMenu' },
        components: { PanelBody: 'Panel', SelectControl: 'Select', Button: 'Button', Notice: 'Notice', Spinner: 'Spinner', Dashicon: 'Icon' },
        element: { createElement, Fragment: 'Fragment', useMemo: initial, useState, useEffect, useRef },
        data: { select, dispatch }, i18n: { __: translate }, apiFetch,
    };
    const window = {
        RRZEWebTConfig: {
            hasCredentials: true, availableLanguages: ['DE', 'EN'], sourceLanguage: 'DE',
            jobsEndpoint: '/wp-json/rrze-webt/v1/jobs', jobsAckEndpoint: '/wp-json/rrze-webt/v1/jobs/ack',
            restRoute: 'rrze-webt/v1/translate', clientRequestTimeoutMs: 30000,
        },
        location: { origin: 'https://webt.invalid' }, setInterval: noop, clearInterval: noop,
    };
    runInNewContext(readFileSync(join(__dirname, '../src/editor.js'), 'utf8'), {
        wp, window, localStorage: { getItem: noPreference, setItem: noop },
        URL, AbortController, setTimeout: timeout, clearTimeout: noop, console,
    });
    plugin.render();
    for (const effect of effects) effect();
    return { button, calls, errors, infos, timers };
}

for (const outcome of ['unconfirmed', 'rejected', 'aborted']) {
    test(`editor refreshes job history after ${outcome} submission`,
        /** @returns {Promise<void>} Verify actual request handling and visible feedback. */
        async function () {
            const host = editorHarness(outcome);
            await settle();
            assert.ok(host.button);
            assert.equal(host.button.props.disabled, false);
            host.button.props.onClick();
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
