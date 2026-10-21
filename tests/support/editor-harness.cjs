const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const { join } = require('node:path');

/**
 * Execute the actual editor script with deterministic hooks, stores and REST responses.
 * Explicit render() calls simulate React rerenders; block validation is a test double.
 * No browser, live WordPress or network is involved.
 * @param {Object} options Submission outcome, stored jobs and optional block parser.
 * @returns {Object} Editor observations and user interaction helpers.
 */
function editorHarness(options = {}) {
    const calls = [], errors = [], infos = [], successes = [], edits = [], timers = [];
    const hooks = [], pendingEffects = [];
    const editor = { postId: 123, content: '<p>Lokaler Test</p>', title: 'Lokaler Test' };
    let cursor = 0, plugin, buttons = [], tree, interval;
    /** @returns {void} Inert host operation. */
    function noop() {}
    /** @returns {null} No saved language preference. */
    function noPreference() { return null; }
    /** @param {*} value Initializer or value. @returns {*} Resolved value. */
    function initial(value) { return typeof value === 'function' ? value() : value; }
    /** @param {*} value State initializer. @returns {Array} Current state and setter. */
    function useState(value) {
        const index = cursor++;
        if (!(index in hooks)) hooks[index] = initial(value);
        /** @param {*} next Replacement or updater. @returns {void} */
        function setState(next) { hooks[index] = typeof next === 'function' ? next(hooks[index]) : next; }
        return [hooks[index], setState];
    }
    /** @param {*} value Ref initializer. @returns {Object} Stable mutable ref. */
    function useRef(value) { const index = cursor++; return hooks[index] ||= { current: value }; }
    /** @param {Function} effect Effect body. @param {Array} deps Dependencies. @returns {void} */
    function useEffect(effect, deps) {
        const index = cursor++;
        if (JSON.stringify(hooks[index]) !== JSON.stringify(deps)) { hooks[index] = deps; pendingEffects.push(effect); }
    }
    /** @param {Function} factory Factory. @returns {*} Derived value. */
    function useMemo(factory) { return factory(); }
    /** @param {Function} callback Selector callback. @returns {*} Selected state. */
    function useSelect(callback) { return callback(select); }
    /** @param {string} message Notice. @returns {void} */
    function errorNotice(message) { errors.push(message); }
    /** @param {string} message Notice. @returns {void} */
    function infoNotice(message) { infos.push(message); }
    /** @param {string} message Notice. @returns {void} */
    function successNotice(message) { successes.push(message); }
    /** @param {string} message Source string. @returns {string} Source string. */
    function translate(message) { return message; }
    /** @param {Function} callback Timer callback. @param {number} delay Delay. @returns {number} Handle. */
    function timeout(callback, delay) { timers.push({ callback, delay }); return timers.length; }
    /** @param {Function} callback Poll callback. @returns {number} Handle. */
    function setInterval(callback) { interval = callback; return 1; }
    /** @param {string} name Plugin name. @param {Object} definition Plugin definition. @returns {void} */
    function registerPlugin(name, definition) { plugin = definition; }
    /** @param {*} type Element type. @param {Object} props Props. @param {...*} children Children. @returns {Object} Tree node. */
    function createElement(type, props, ...children) {
        if (typeof type === 'function') return type(props);
        const node = { type, props, children };
        if (type === 'Button') buttons.push(node);
        return node;
    }
    /** @returns {string} Current unsaved content. */
    function content() { return editor.content; }
    /** @returns {string} Current title. */
    function title() { return editor.title; }
    /** @returns {number} Current post. */
    function postId() { return editor.postId; }
    /** @returns {Object} Editor selectors. */
    function select() { return { getEditedPostContent: content, getEditedPostAttribute: title, getCurrentPostId: postId }; }
    /** @param {Object} update Unsaved edit; never sends a save request. @returns {void} */
    function editPost(update) { edits.push(update); Object.assign(editor, update); }
    /** @returns {Object} Editor and notice dispatchers. */
    function dispatch() { return { editPost, createSuccessNotice: successNotice, createErrorNotice: errorNotice, createInfoNotice: infoNotice }; }
    /** @param {Object} request REST request. @returns {Promise<Object>} Local response. */
    async function apiFetch(request) {
        calls.push(request);
        if (request.method === 'GET') return { jobs: options.jobs || [] };
        if (request.path.includes('/ack/')) {
            if (options.ackFails) throw new Error('Acknowledgement failed');
            return { status: 'acknowledged' };
        }
        if (!options.outcome || options.outcome === 'unconfirmed') return { token: 'fixture-token', jobId: '', status: 'pending', reused: true };
        if (options.outcome === 'legacy') return { translation: '<p>Must not be applied</p>' };
        const error = new Error(options.outcome === 'aborted' ? 'Request aborted' : 'Provider rejected request');
        if (options.outcome === 'aborted') error.name = 'AbortError';
        throw error;
    }
    /** @param {string} html Content. @returns {Array} Minimal valid block tree for UI tests. */
    function parse(html) { return [{ name: 'core/paragraph', isValid: true, innerBlocks: [], originalContent: html }]; }
    /** @param {string} name Block name. @returns {Object|undefined} Simulated registration. */
    function getBlockType(name) { return name.startsWith('core/') ? { name } : undefined; }
    const wp = {
        plugins: { registerPlugin },
        editPost: { PluginSidebar: 'Sidebar', PluginSidebarMoreMenuItem: 'SidebarMenu' },
        components: { PanelBody: 'Panel', SelectControl: 'Select', Button: 'Button', Notice: 'Notice', Spinner: 'Spinner', Dashicon: 'Icon', Modal: 'Modal' },
        element: { createElement, Fragment: 'Fragment', useMemo, useState, useEffect, useRef },
        data: { select, dispatch, useSelect }, i18n: { __: translate }, apiFetch,
        blocks: { parse: options.parse || parse, getBlockType },
    };
    const window = {
        RRZEWebTConfig: {
            hasCredentials: true, availableLanguages: ['DE', 'EN'], sourceLanguage: 'DE',
            jobsEndpoint: '/wp-json/rrze-webt/v1/jobs', jobsAckEndpoint: '/wp-json/rrze-webt/v1/jobs/ack',
            restRoute: 'rrze-webt/v1/translate', clientRequestTimeoutMs: 30000,
        },
        location: { origin: 'https://webt.invalid' }, setInterval, clearInterval: noop,
    };
    runInNewContext(readFileSync(join(__dirname, '../../src/editor.js'), 'utf8'), {
        wp, window, localStorage: { getItem: noPreference, setItem: noop },
        URL, AbortController, setTimeout: timeout, clearTimeout: noop, console,
    });
    /** @returns {Object} Rendered component tree after committing pending effects. */
    function render() {
        cursor = 0; buttons = []; tree = plugin.render();
        while (pendingEffects.length) pendingEffects.shift()();
        return tree;
    }
    /** @param {string} label Visible button text. @returns {Object|undefined} Matching button. */
    function button(label) {
        return buttons.find(
            /** @param {Object} node Element. @returns {boolean} Whether the button text matches. */
            (node) => node.children.includes(label)
        );
    }
    /** @returns {Promise<void>} Poll the fake REST API once. */
    async function poll() { await interval(); }
    render();
    return { render, button, poll, calls, errors, infos, successes, edits, timers, editor };
}
module.exports = { editorHarness };
