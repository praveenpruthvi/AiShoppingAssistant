/**
 * Task 47 regression test: proves the REAL, DEPLOYED
 * chat-widget-core.js/chat-widget-luma.js source (loaded via `vm`, not
 * reimplemented here) genuinely renders a failure message into the chat
 * log BEFORE hiding the widget, and only hides it after a real
 * setTimeout delay elapses — not synchronously in the same step, which
 * was the actual live-reproduced bug (the widget could disappear before
 * the browser ever painted the message explaining why).
 *
 * Dependency-free by design, matching this module's own "vanilla JS, no
 * framework" convention for the widget itself: uses only Node's built-in
 * `node:test` runner and `node:test`'s built-in `mock.timers` (both
 * ship with Node, no npm install/package.json needed). Run with:
 *   node --test Test/Unit/js/chatWidgetHideTiming.test.js
 *
 * Builds the smallest DOM stub sufficient for chat-widget-luma.js's real
 * init() to run without throwing, then simulates a real form submit and
 * a controlled core.sendMessage() resolution — the same normalized
 * response shape (reason_code/message/products/...) Controller\Chat\Send
 * actually returns, per this module's live-verified response contract.
 *
 * The equivalent Hyva-layer sequencing (chat-widget-hyva.js) is
 * structurally identical — shouldHideWidget()/scheduleHideIfNeeded()
 * called in the same order for the same reason — but is verified by
 * direct code reading only, not a second harness: exercising Alpine.js's
 * real reactivity would need a real Alpine runtime, which is a much
 * heavier dependency than this module's own "no framework" JS carries,
 * and no browser-automation tool exists in this session either (the
 * same disclosed limitation as every other frontend-UI task in this
 * module).
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const CORE_JS_PATH = path.join(__dirname, '../../../view/frontend/web/js/chat-widget-core.js');
const LUMA_JS_PATH = path.join(__dirname, '../../../view/frontend/web/js/chat-widget-luma.js');

function makeStyleObject(onDisplayChange) {
    var display = '';
    return {
        get width() { return this._width || ''; },
        set width(value) { this._width = value; },
        get height() { return this._height || ''; },
        set height(value) { this._height = value; },
        get display() { return display; },
        set display(value) {
            display = value;
            onDisplayChange(value);
        }
    };
}

function escapeForInnerHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function makeElement(role, sequence) {
    var listeners = {};
    var children = [];
    var innerHtml = '';

    return {
        role: role,
        _listeners: listeners,
        children: children,
        className: '',
        // Mirrors the one real DOM behavior escapeHtml() in the actual
        // source relies on: setting .textContent, then reading .innerHTML
        // back out, returns the HTML-escaped form of that text — the
        // browser mechanism the real code uses to escape untrusted text
        // without a manual escaping implementation of its own.
        get innerHTML() { return innerHtml; },
        set innerHTML(value) { innerHtml = value; },
        get textContent() { return innerHtml; },
        set textContent(value) { innerHtml = escapeForInnerHtml(value); },
        type: '',
        value: '',
        disabled: false,
        scrollTop: 0,
        scrollHeight: 0,
        style: makeStyleObject(function (value) {
            sequence.push({event: 'style.display', role: role, value: value});
        }),
        classList: {toggle: function () {}},
        addEventListener: function (event, handler) {
            listeners[event] = handler;
        },
        setAttribute: function () {},
        getAttribute: function () {
            return '';
        },
        focus: function () {},
        remove: function () {},
        appendChild: function (child) {
            children.push(child);
            sequence.push({event: 'appendChild', role: role, childRole: child.role || null, childHtml: child.innerHTML});
            return child;
        },
        querySelector: function () {
            return null;
        },
        getBoundingClientRect: function () {
            return {width: 400, height: 600};
        }
    };
}

/**
 * Builds a minimal fake `window`/`document` sufficient for
 * chat-widget-luma.js's real init() to run end to end, plus a shared
 * `sequence` array every DOM mutation this test cares about is recorded
 * into (in real chronological order) so message-then-hide ordering can
 * be asserted directly, not inferred.
 */
function buildFakeDom() {
    var sequence = [];
    var domListeners = {};

    var roleElements = {};
    ['toggle', 'close', 'minimize', 'resize-handle', 'panel', 'log', 'form', 'input', 'send'].forEach(function (role) {
        roleElements[role] = makeElement(role, sequence);
    });

    var root = makeElement('root', sequence);
    root.getAttribute = function (name) {
        if (name === 'data-send-url') {
            return '/aichat/chat/send';
        }
        // Deliberately empty: skips the history-restore branch entirely
        // (core.fetchHistory()), which is irrelevant to this test and
        // would otherwise need its own fetch stub.
        return '';
    };
    root.querySelector = function (selector) {
        var match = /data-role="([^"]+)"/.exec(selector);
        return match ? roleElements[match[1]] || null : null;
    };

    var fakeWindow = {
        sessionStorage: {
            getItem: function () { return null; },
            setItem: function () {}
        },
        innerWidth: 1024,
        innerHeight: 768,
        // References the OUTER realm's setTimeout/clearTimeout — a vm
        // context does not automatically provide Node-added globals like
        // these, and this is deliberately captured live (not
        // pre-resolved) so it picks up node:test's mock.timers patching
        // of the real global setTimeout when a test has fake timers
        // enabled, exactly as a real browser's window.setTimeout would
        // be the one and only timer implementation in play.
        setTimeout: function (fn, delay) {
            return setTimeout(fn, delay);
        },
        clearTimeout: function (id) {
            return clearTimeout(id);
        }
    };

    var fakeDocument = {
        addEventListener: function (event, handler) {
            domListeners[event] = handler;
        },
        getElementById: function (id) {
            return id === 'aavirbhava-chat-widget' ? root : null;
        },
        createElement: function (tag) {
            return makeElement('created:' + tag, sequence);
        }
    };

    fakeWindow.document = fakeDocument;

    return {
        window: fakeWindow,
        document: fakeDocument,
        domListeners: domListeners,
        root: root,
        roleElements: roleElements,
        sequence: sequence
    };
}

/**
 * Loads the two REAL source files into one vm context sharing the fake
 * window/document, triggers the real DOMContentLoaded init, and returns
 * everything a test needs to drive it — never a reimplementation of the
 * widget's own logic, only the harness around it.
 */
function loadWidget() {
    var dom = buildFakeDom();
    var context = vm.createContext({window: dom.window, document: dom.document, console: console});

    var coreSource = fs.readFileSync(CORE_JS_PATH, 'utf8');
    vm.runInContext(coreSource, context, {filename: CORE_JS_PATH});

    var core = dom.window.AavirbhavaChatCore;
    assert.ok(core, 'chat-widget-core.js must expose window.AavirbhavaChatCore');

    var lumaSource = fs.readFileSync(LUMA_JS_PATH, 'utf8');
    vm.runInContext(lumaSource, context, {filename: LUMA_JS_PATH});

    assert.ok(dom.domListeners.DOMContentLoaded, 'chat-widget-luma.js must register a DOMContentLoaded listener');
    dom.domListeners.DOMContentLoaded();

    return {dom: dom, core: core};
}

/**
 * Simulates a real customer form submission: sets the input's value and
 * invokes the real captured `submit` handler exactly as a browser would
 * dispatch the event, then waits for chat-widget-core.js's real
 * normalizeResponse()/the widget's own `.then()` handler to finish
 * running (a single microtask flush is enough since core.sendMessage is
 * stubbed to resolve immediately, not to make a real network call).
 */
async function submitAndWaitForResponse(dom, messageText) {
    dom.roleElements.input.value = messageText;
    var submitHandler = dom.roleElements.form._listeners.submit;
    assert.ok(submitHandler, 'the form must have a real submit listener wired by init()');

    submitHandler({preventDefault: function () {}});

    // Flushes the microtask queue so core.sendMessage()'s resolved
    // Promise's .then() callback (where the real appendAssistantResponse
    // + scheduleHideIfNeeded logic lives) actually runs before this
    // function returns.
    await Promise.resolve();
    await Promise.resolve();
}

function stubSendMessage(core, response) {
    core.sendMessage = function () {
        return Promise.resolve({ok: true, status: 200, data: response});
    };
}

test('hard failure (assistant_down): message renders before the widget hides, and only after a real delay', async function () {
    var timers = require('node:test').mock;
    timers.timers.enable({apis: ['setTimeout']});

    try {
        var loaded = loadWidget();
        var dom = loaded.dom;
        var core = loaded.core;

        stubSendMessage(core, {
            message: 'Our shopping assistant is temporarily unavailable due to a technical issue.',
            reason_code: core.REASON_ASSISTANT_DOWN,
            products: [],
            follow_up_questions: [],
            awaiting_confirmation: false
        });

        await submitAndWaitForResponse(dom, 'Show me waterproof jackets.');

        var appendedToLog = dom.sequence.filter(function (entry) {
            return entry.event === 'appendChild' && entry.role === 'log';
        });
        // appendUserMessage + appendThinking + appendAssistantResponse's
        // bubble = 3 real appends to the log before any hide decision.
        assert.equal(appendedToLog.length, 3, 'the user message, the thinking bubble, and the assistant response must all be in the DOM');

        var hideEventsBeforeTick = dom.sequence.filter(function (entry) {
            return entry.event === 'style.display' && entry.value === 'none';
        });
        assert.equal(hideEventsBeforeTick.length, 0, 'the widget must NOT be hidden yet — the customer has not had time to read the message');
        assert.notEqual(dom.root.style.display, 'none');

        // Advance the fake clock by less than HIDE_DELAY_MS: still not hidden.
        timers.timers.tick(core.HIDE_DELAY_MS - 1);
        assert.notEqual(dom.root.style.display, 'none', 'must not hide even 1ms before the real delay elapses');

        // Advance past HIDE_DELAY_MS: now it hides.
        timers.timers.tick(1);
        assert.equal(dom.root.style.display, 'none', 'must hide once the real delay has genuinely elapsed');

        // And the message was rendered strictly before the hide, in
        // real chronological order — not just "both eventually happened".
        var lastAppendIndex = dom.sequence.lastIndexOf(appendedToLog[appendedToLog.length - 1]);
        var hideIndex = dom.sequence.findIndex(function (entry) {
            return entry.event === 'style.display' && entry.value === 'none';
        });
        assert.ok(lastAppendIndex < hideIndex, 'the assistant message must be appended before the widget hides, not after or simultaneously');
    } finally {
        timers.timers.reset();
    }
});

test('soft failures: a single assistant_unavailable does NOT hide the widget, but 3 consecutive ones do (after the message renders and a real delay)', async function () {
    var timers = require('node:test').mock;
    timers.timers.enable({apis: ['setTimeout']});

    try {
        var loaded = loadWidget();
        var dom = loaded.dom;
        var core = loaded.core;

        var softResponse = {
            message: "I'm having trouble answering right now. Please try again in a moment.",
            reason_code: core.REASON_ASSISTANT_UNAVAILABLE,
            products: [],
            follow_up_questions: [],
            awaiting_confirmation: false
        };

        stubSendMessage(core, softResponse);
        await submitAndWaitForResponse(dom, 'first attempt');
        timers.timers.tick(core.HIDE_DELAY_MS);
        assert.notEqual(dom.root.style.display, 'none', 'one soft failure alone must never hide the widget');

        stubSendMessage(core, softResponse);
        await submitAndWaitForResponse(dom, 'second attempt');
        timers.timers.tick(core.HIDE_DELAY_MS);
        assert.notEqual(dom.root.style.display, 'none', 'two consecutive soft failures (below the threshold of 3) must still not hide it');

        var appendCountBeforeThird = dom.sequence.filter(function (entry) {
            return entry.event === 'appendChild' && entry.role === 'log';
        }).length;

        stubSendMessage(core, softResponse);
        await submitAndWaitForResponse(dom, 'third attempt');

        // Same message-before-hide guarantee applies on the threshold-
        // triggering call too, not only the single assistant_down case.
        assert.notEqual(dom.root.style.display, 'none', 'must still not hide synchronously, even on the 3rd consecutive soft failure');
        var appendCountAfterThird = dom.sequence.filter(function (entry) {
            return entry.event === 'appendChild' && entry.role === 'log';
        }).length;
        assert.ok(appendCountAfterThird > appendCountBeforeThird, 'the 3rd message must have been rendered before any hide decision');

        timers.timers.tick(core.HIDE_DELAY_MS);
        assert.equal(dom.root.style.display, 'none', '3 consecutive soft failures must hide the widget once the real delay elapses');
    } finally {
        timers.timers.reset();
    }
});

test('a success in between resets the soft-failure counter, so 2 soft failures + 1 success + 2 more soft failures never hides the widget', async function () {
    var timers = require('node:test').mock;
    timers.timers.enable({apis: ['setTimeout']});

    try {
        var loaded = loadWidget();
        var dom = loaded.dom;
        var core = loaded.core;

        var softResponse = {
            message: 'soft failure',
            reason_code: core.REASON_ASSISTANT_UNAVAILABLE,
            products: [],
            follow_up_questions: [],
            awaiting_confirmation: false
        };
        var successResponse = {
            message: 'Here are some options.',
            reason_code: null,
            products: [],
            follow_up_questions: [],
            awaiting_confirmation: false
        };

        for (var i = 0; i < 2; i++) {
            stubSendMessage(core, softResponse);
            await submitAndWaitForResponse(dom, 'soft ' + i);
            timers.timers.tick(core.HIDE_DELAY_MS);
        }

        stubSendMessage(core, successResponse);
        await submitAndWaitForResponse(dom, 'a real success');
        timers.timers.tick(core.HIDE_DELAY_MS);

        for (var j = 0; j < 2; j++) {
            stubSendMessage(core, softResponse);
            await submitAndWaitForResponse(dom, 'soft again ' + j);
            timers.timers.tick(core.HIDE_DELAY_MS);
        }

        assert.notEqual(dom.root.style.display, 'none', 'the success in between must have reset the consecutive-failure count');
    } finally {
        timers.timers.reset();
    }
});
