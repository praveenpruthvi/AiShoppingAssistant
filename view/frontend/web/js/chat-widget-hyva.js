/**
 * Aavirbhava AI Shopping Assistant — Hyva presentation layer.
 *
 * An Alpine.js data component (Hyva's own component model — no jQuery/
 * Knockout/RequireJS/UI components involved). Registered as a plain
 * global factory function referenced by the Hyva template's
 * `x-data="aavirbhavaChatWidget({...})"` — the simplest, most portable
 * way for a third-party module to add an Alpine component to a Hyva page
 * without depending on Hyva's own Alpine.data() registry timing. All
 * network/data logic is delegated to chat-widget-core.js
 * (window.AavirbhavaChatCore, the same file the Luma layer uses); this
 * file only ever holds/renders reactive state.
 */
function aavirbhavaChatWidget(config) {
    'use strict';

    var STATE_KEY = 'aavirbhava-chat-ui-state';
    var MIN_WIDTH = 300;
    var MIN_HEIGHT = 360;
    var MAX_WIDTH_MARGIN = 32; // matches the template's max-w-[calc(100vw-2rem)]
    var MAX_HEIGHT_MARGIN = 96; // matches the template's max-h-[calc(100vh-6rem)]

    function readPersistedState() {
        try {
            var raw = window.sessionStorage.getItem(STATE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (error) {
            return {};
        }
    }

    function writePersistedState(state) {
        try {
            window.sessionStorage.setItem(STATE_KEY, JSON.stringify(state));
        } catch (error) {
            // Storage unavailable — persistence is a nice-to-have only.
        }
    }

    return {
        open: false,
        minimized: false,
        loading: false,
        stopped: false,
        input: '',
        messages: [],
        resizing: false,
        resizeStartX: 0,
        resizeStartY: 0,
        resizeStartWidth: 0,
        resizeStartHeight: 0,

        init: function () {
            this.sendUrl = config && config.sendUrl ? config.sendUrl : '';
            this.historyUrl = config && config.historyUrl ? config.historyUrl : '';

            var persisted = readPersistedState();
            this.minimized = persisted.minimized === true;
            this.open = persisted.open === true;

            this.restoreHistory();
        },

        persistState: function () {
            writePersistedState({open: this.open, minimized: this.minimized});
        },

        scrollLogToBottom: function () {
            var self = this;
            this.$nextTick(function () {
                if (self.$refs.log) {
                    self.$refs.log.scrollTop = self.$refs.log.scrollHeight;
                }
            });
        },

        restoreHistory: function () {
            if (!this.historyUrl || !window.AavirbhavaChatCore) {
                return;
            }

            var self = this;
            window.AavirbhavaChatCore.fetchHistory(this.historyUrl).then(function (messages) {
                messages.forEach(function (entry) {
                    if (entry.role === 'user') {
                        self.messages.push({role: 'user', text: entry.message, html: ''});
                        return;
                    }

                    self.messages.push({
                        role: 'assistant',
                        text: entry.message,
                        html: window.AavirbhavaChatCore.renderMarkdown(entry.message),
                        products: entry.products,
                        followUps: entry.followUpQuestions,
                        awaitingConfirmation: false
                    });
                });
                self.scrollLogToBottom();
            });
        },

        toggle: function () {
            if (this.minimized) {
                this.minimized = false;
            }
            this.open = !this.open;
            this.persistState();
            this.scrollLogToBottom();
        },

        close: function () {
            this.open = false;
            this.persistState();
        },

        startResize: function (event) {
            this.resizing = true;
            this.resizeStartX = event.clientX;
            this.resizeStartY = event.clientY;
            var rect = this.$refs.panel.getBoundingClientRect();
            this.resizeStartWidth = rect.width;
            this.resizeStartHeight = rect.height;
            event.preventDefault();
        },

        // The panel is anchored by right/bottom (Tailwind's `absolute
        // right-0 bottom-16`), not left/top, so growing width/height while
        // the handle sits at the panel's top-left corner naturally expands
        // the panel upward and leftward — the bottom-right corner never
        // moves. This is the top-left resize behavior the native CSS
        // `resize` property can't offer (it only ever drags from the
        // bottom-right corner), matching the same technique the Luma
        // layer's chat-widget-luma.js uses.
        onResize: function (event) {
            if (!this.resizing) {
                return;
            }

            var deltaX = this.resizeStartX - event.clientX;
            var deltaY = this.resizeStartY - event.clientY;
            var maxWidth = window.innerWidth - MAX_WIDTH_MARGIN;
            var maxHeight = window.innerHeight - MAX_HEIGHT_MARGIN;

            this.$refs.panel.style.width = Math.min(Math.max(this.resizeStartWidth + deltaX, MIN_WIDTH), maxWidth) + 'px';
            this.$refs.panel.style.height = Math.min(Math.max(this.resizeStartHeight + deltaY, MIN_HEIGHT), maxHeight) + 'px';
        },

        stopResize: function () {
            this.resizing = false;
        },

        formatPrice: function (amount) {
            return window.AavirbhavaChatCore ? window.AavirbhavaChatCore.formatPrice(amount) : '';
        },

        submit: function () {
            this.send(this.input);
            this.input = '';
        },

        confirmYes: function () {
            this.send('Yes, please go ahead.');
        },

        confirmNo: function () {
            this.send('No, please cancel that.');
        },

        askFollowUp: function (question) {
            this.send(question);
        },

        send: function (text) {
            var message = (text || '').trim();
            if (message === '' || this.loading || this.stopped || !window.AavirbhavaChatCore) {
                return;
            }

            this.messages.push({role: 'user', text: message, html: ''});
            this.loading = true;
            this.scrollLogToBottom();

            var self = this;
            window.AavirbhavaChatCore.sendMessage(this.sendUrl, message).then(function (result) {
                var normalized = window.AavirbhavaChatCore.normalizeResponse(result.data);

                // A confirmed-down response (Task 45) ends the
                // conversation for the rest of this visit — retrying
                // cannot help, since the underlying failure is confirmed
                // to recur identically, so input/send stay disabled
                // rather than inviting the customer to keep typing. A
                // page reload re-evaluates ChatWidget's own server-side
                // render gate (Task 44), which hides the widget entirely
                // once the same confirmed-down circuit-breaker state is
                // visible there too.
                if (normalized.reasonCode === window.AavirbhavaChatCore.REASON_ASSISTANT_DOWN) {
                    self.stopped = true;
                }

                self.loading = false;

                if (!result.ok && normalized.message === '') {
                    self.messages.push(self.failureMessage());
                    self.scrollLogToBottom();
                    return;
                }

                self.messages.push({
                    role: 'assistant',
                    text: normalized.message,
                    html: window.AavirbhavaChatCore.renderMarkdown(normalized.message),
                    products: normalized.products,
                    followUps: normalized.followUpQuestions,
                    awaitingConfirmation: normalized.awaitingConfirmation
                });
                self.scrollLogToBottom();
            }).catch(function () {
                self.loading = false;
                self.messages.push(self.failureMessage());
                self.scrollLogToBottom();
            });
        },

        failureMessage: function () {
            var text = 'Sorry, something went wrong. Please try again.';

            return {
                role: 'assistant',
                text: text,
                html: window.AavirbhavaChatCore.renderMarkdown(text),
                products: [],
                followUps: [],
                awaitingConfirmation: false
            };
        }
    };
}
