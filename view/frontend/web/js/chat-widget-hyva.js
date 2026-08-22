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
        hidden: false,
        input: '',
        messages: [],
        resizing: false,
        resizeStartX: 0,
        resizeStartY: 0,
        resizeStartWidth: 0,
        resizeStartHeight: 0,
        consecutiveSoftFailures: 0,

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

        // Removes the entire widget (toggle button and panel alike —
        // `hidden` gates the whole x-data root, see widget-hyva.phtml)
        // for the rest of this visit (Task 46, replacing Task 45's
        // disable-input-only `stopped` approach: that logic was correct
        // but its live "doesn't actually work" report traced to a stale
        // compiled pub/static asset, not a logic bug — see the Task 46
        // status report). A customer genuinely confirmed unable to get
        // help from the assistant is better told plainly by the
        // widget's absence than left probing a visibly "alive" but
        // useless one. Deliberately not persisted client-side — a page
        // reload re-evaluates ChatWidget's own server-side render gate
        // (Task 44), which by then hides the widget on the SERVER side
        // too once the same confirmed-down circuit-breaker state is
        // visible there.
        //
        // Never called directly from send() (Task 47) — always
        // scheduled via scheduleHideIfNeeded() so the failure message
        // the customer is being told to react to has already been
        // pushed AND had a real chance to render first. See that
        // method's own docblock for why a synchronous call here, even
        // placed after messages.push() in source order, was still a
        // real, live-reproduced bug.
        hideWidgetEntirely: function () {
            this.hidden = true;
        },

        // A single assistant_unavailable/retrieval_unavailable is not,
        // on its own, evidence the assistant is genuinely down — it
        // might be a one-off blip a fresh request wouldn't repeat. Only
        // SOFT_FAILURE_HIDE_THRESHOLD of them IN A ROW, with no
        // successful/out-of-scope response resetting the count in
        // between, is treated the same as a single assistant_down.
        //
        // Deliberately only DECIDES here — never hides directly. Hiding
        // is scheduleHideIfNeeded()'s job, called only after the
        // response has actually been rendered (see send()).
        shouldHideWidget: function (reasonCode) {
            if (reasonCode === window.AavirbhavaChatCore.REASON_ASSISTANT_DOWN) {
                return true;
            }

            if (window.AavirbhavaChatCore.isSoftFailureReason(reasonCode)) {
                this.consecutiveSoftFailures++;
                return this.consecutiveSoftFailures >= window.AavirbhavaChatCore.SOFT_FAILURE_HIDE_THRESHOLD;
            }

            this.consecutiveSoftFailures = 0;
            return false;
        },

        // The real fix for a real, live-reproduced bug (Task 47): the
        // widget was hiding before the customer could read why, because
        // hideWidgetEntirely() ran synchronously in the same step that
        // decided to hide — even calling it AFTER messages.push() in
        // source order was not enough, since nothing forces Alpine to
        // paint the newly-pushed message before a synchronous `hidden =
        // true` change immediately after it. A real `setTimeout`
        // genuinely yields to the browser's render step before firing,
        // which plain synchronous reordering does not guarantee — and
        // HIDE_DELAY_MS gives actual reading time on top of that, not
        // just a technically-correct but imperceptibly-brief window.
        scheduleHideIfNeeded: function (shouldHide) {
            if (!shouldHide) {
                return;
            }

            var self = this;
            window.setTimeout(function () {
                self.hideWidgetEntirely();
            }, window.AavirbhavaChatCore.HIDE_DELAY_MS);
        },

        send: function (text) {
            var message = (text || '').trim();
            if (message === '' || this.loading || !window.AavirbhavaChatCore) {
                return;
            }

            this.messages.push({role: 'user', text: message, html: ''});
            this.loading = true;
            this.scrollLogToBottom();

            var self = this;
            window.AavirbhavaChatCore.sendMessage(this.sendUrl, message).then(function (result) {
                var normalized = window.AavirbhavaChatCore.normalizeResponse(result.data);
                var shouldHide = self.shouldHideWidget(normalized.reasonCode);

                self.loading = false;

                if (!result.ok && normalized.message === '') {
                    self.messages.push(self.failureMessage());
                } else {
                    self.messages.push({
                        role: 'assistant',
                        text: normalized.message,
                        html: window.AavirbhavaChatCore.renderMarkdown(normalized.message),
                        products: normalized.products,
                        followUps: normalized.followUpQuestions,
                        awaitingConfirmation: normalized.awaitingConfirmation
                    });
                }

                self.scrollLogToBottom();

                // Always scheduled AFTER the message above is actually
                // in `messages` — see scheduleHideIfNeeded()'s own
                // docblock for why this ordering, plus the real
                // setTimeout delay, both matter.
                self.scheduleHideIfNeeded(shouldHide);
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
