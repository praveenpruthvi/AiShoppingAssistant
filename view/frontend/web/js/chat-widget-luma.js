/**
 * Aavirbhava AI Shopping Assistant — default/Luma presentation layer.
 *
 * Vanilla JS (no jQuery/Knockout/RequireJS dependency) — imperative DOM
 * manipulation over the markup in chat/widget.phtml. All network/data
 * logic is delegated to chat-widget-core.js (window.AavirbhavaChatCore);
 * this file only ever renders what that module gives it back.
 */
(function () {
    'use strict';

    var STATE_KEY = 'aavirbhava-chat-ui-state';
    var MIN_WIDTH = 300;
    var MIN_HEIGHT = 360;
    var MAX_WIDTH_MARGIN = 32; // matches the template's calc(100vw - 2rem)
    var MAX_HEIGHT_MARGIN = 96; // matches the template's calc(100vh - 6rem)

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    /**
     * Session-scoped, best-effort UI-state persistence (open/minimized) —
     * never required for the widget to function, so any storage failure
     * (private browsing, disabled storage) just means the state doesn't
     * survive a reload, not a broken widget.
     */
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

    function init(root) {
        var core = window.AavirbhavaChatCore;
        if (!core) {
            return;
        }

        var sendUrl = root.getAttribute('data-send-url');
        var historyUrl = root.getAttribute('data-history-url');
        var toggle = root.querySelector('[data-role="toggle"]');
        var closeButton = root.querySelector('[data-role="close"]');
        var minimizeButton = root.querySelector('[data-role="minimize"]');
        var resizeHandle = root.querySelector('[data-role="resize-handle"]');
        var panel = root.querySelector('[data-role="panel"]');
        var log = root.querySelector('[data-role="log"]');
        var form = root.querySelector('[data-role="form"]');
        var input = root.querySelector('[data-role="input"]');
        var sendButton = root.querySelector('[data-role="send"]');
        var loading = false;
        var isOpen = false;
        var isMinimized = false;
        var consecutiveSoftFailures = 0;

        function persistState() {
            writePersistedState({open: isOpen, minimized: isMinimized});
        }

        function setOpen(open) {
            isOpen = open;
            panel.classList.toggle('aavirbhava-chat-panel--open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                input.focus();
            }
            persistState();
        }

        function setMinimized(minimized) {
            isMinimized = minimized;
            panel.classList.toggle('aavirbhava-chat-panel--minimized', minimized);
            minimizeButton.setAttribute('aria-label', minimized ? 'Maximize chat' : 'Minimize chat');
            minimizeButton.innerHTML = minimized ? '&#9723;' : '&minus;';
            persistState();
        }

        function setLoading(isLoading) {
            loading = isLoading;
            input.disabled = isLoading;
            sendButton.disabled = isLoading;
        }

        /**
         * Removes the entire widget — toggle button and panel alike,
         * since both live inside `root` — from the page for the rest of
         * this visit (Task 46, replacing Task 45's disable-input-only
         * approach: that JS was correct but its live "doesn't actually
         * work" report traced to a stale compiled pub/static asset, not
         * a logic bug — see the Task 46 status report). Just disabling
         * input left a visibly "alive" widget the customer could still
         * open and stare at while it did nothing useful; a customer
         * genuinely confirmed unable to get help from it is better told
         * plainly by the widget's absence than left probing a dead one.
         *
         * Deliberately not persisted client-side (no sessionStorage
         * entry) — a page reload re-evaluates ChatWidget's own
         * server-side render gate (Task 44), which by then hides the
         * widget on the SERVER side too once the same confirmed-down
         * circuit-breaker state is visible there.
         *
         * Never called directly from the response handler (Task 47) —
         * always scheduled via scheduleHideIfNeeded() so the failure
         * message the customer is being told to react to has already
         * been appended AND had a real chance to paint first. See that
         * function's own docblock for why a synchronous call here, even
         * placed after appendAssistantResponse() in source order, was
         * still a real, live-reproduced bug.
         */
        function hideWidgetEntirely() {
            root.style.display = 'none';
        }

        /**
         * A single assistant_unavailable/retrieval_unavailable is not,
         * on its own, evidence the assistant is genuinely down — it
         * might be a one-off blip a fresh request wouldn't repeat. Only
         * SOFT_FAILURE_HIDE_THRESHOLD of them IN A ROW, with no
         * successful/out-of-scope response resetting the count in
         * between, is treated the same as a single assistant_down.
         *
         * Deliberately only DECIDES here — never hides directly. Hiding
         * is scheduleHideIfNeeded()'s job, called only after the
         * response has actually been rendered (see submitMessage()).
         *
         * @param {?string} reasonCode
         * @returns {boolean} true if this response is grounds to hide
         */
        function shouldHideWidget(reasonCode) {
            if (reasonCode === core.REASON_ASSISTANT_DOWN) {
                return true;
            }

            if (core.isSoftFailureReason(reasonCode)) {
                consecutiveSoftFailures++;
                return consecutiveSoftFailures >= core.SOFT_FAILURE_HIDE_THRESHOLD;
            }

            consecutiveSoftFailures = 0;
            return false;
        }

        /**
         * The real fix for a real, live-reproduced bug (Task 47): the
         * widget was hiding before the customer could read why, because
         * hideWidgetEntirely() ran synchronously in the same step that
         * decided to hide — even calling it AFTER appendAssistantResponse()
         * in source order was not enough, since nothing forces the
         * browser to paint the newly-appended message before a
         * synchronous style change immediately after it. A real
         * `setTimeout` genuinely yields to the browser's render step
         * before firing, which plain synchronous reordering does not
         * guarantee — and HIDE_DELAY_MS gives actual reading time on
         * top of that, not just a technically-correct but
         * imperceptibly-brief window.
         *
         * @param {boolean} shouldHide
         */
        function scheduleHideIfNeeded(shouldHide) {
            if (!shouldHide) {
                return;
            }

            window.setTimeout(hideWidgetEntirely, core.HIDE_DELAY_MS);
        }

        function appendBubble(role, html) {
            var bubble = document.createElement('div');
            bubble.className = 'aavirbhava-chat-bubble aavirbhava-chat-bubble--' + role;
            bubble.innerHTML = html;
            log.appendChild(bubble);
            log.scrollTop = log.scrollHeight;
            return bubble;
        }

        function appendUserMessage(text) {
            appendBubble('user', '<p>' + escapeHtml(text) + '</p>');
        }

        function appendThinking() {
            return appendBubble('assistant aavirbhava-chat-bubble--thinking', '<p>Thinking<span class="aavirbhava-chat-thinking-dots"></span></p>');
        }

        function renderProductCard(product) {
            var priceHtml = '';
            if (product.specialPrice !== null && product.price !== null) {
                priceHtml = '<span class="aavirbhava-chat-price-was">' + escapeHtml(core.formatPrice(product.price))
                    + '</span> <span class="aavirbhava-chat-price-now">' + escapeHtml(core.formatPrice(product.specialPrice)) + '</span>';
            } else if (product.price !== null) {
                priceHtml = '<span class="aavirbhava-chat-price-now">' + escapeHtml(core.formatPrice(product.price)) + '</span>';
            }

            var nameHtml = escapeHtml(product.name);
            var titleHtml = product.url
                ? '<a href="' + escapeHtml(product.url) + '" target="_blank" rel="noopener noreferrer">' + nameHtml + '</a>'
                : nameHtml;

            var badgeHtml = product.recommendationType !== 'organic'
                ? '<span class="aavirbhava-chat-product-badge">' + escapeHtml(product.recommendationType) + '</span>'
                : '';

            var skuHtml = product.sku
                ? '<span class="aavirbhava-chat-product-sku">' + escapeHtml(product.sku) + '</span>'
                : '';

            var imageHtml = product.imageUrl
                ? '<img class="aavirbhava-chat-product-image" src="' + escapeHtml(product.imageUrl) + '" alt="" loading="lazy">'
                : '';

            return '<div class="aavirbhava-chat-product-card">'
                + imageHtml
                + '<div class="aavirbhava-chat-product-body">'
                + '<div class="aavirbhava-chat-product-title">' + titleHtml + skuHtml + badgeHtml + '</div>'
                + (priceHtml ? '<div class="aavirbhava-chat-product-price">' + priceHtml + '</div>' : '')
                + (product.reason ? '<p class="aavirbhava-chat-product-reason">' + escapeHtml(product.reason) + '</p>' : '')
                + '</div>'
                + '</div>';
        }

        function appendAssistantResponse(normalized) {
            var html = core.renderMarkdown(normalized.message);

            if (normalized.products.length > 0) {
                html += '<div class="aavirbhava-chat-products">' + normalized.products.map(renderProductCard).join('') + '</div>';
            }

            var bubble = appendBubble('assistant', html);

            if (normalized.awaitingConfirmation) {
                var confirmWrap = document.createElement('div');
                confirmWrap.className = 'aavirbhava-chat-confirm';

                var yesButton = document.createElement('button');
                yesButton.type = 'button';
                yesButton.textContent = 'Yes, go ahead';
                yesButton.addEventListener('click', function () {
                    submitMessage('Yes, please go ahead.');
                });

                var noButton = document.createElement('button');
                noButton.type = 'button';
                noButton.textContent = 'No, cancel';
                noButton.addEventListener('click', function () {
                    submitMessage('No, please cancel that.');
                });

                confirmWrap.appendChild(yesButton);
                confirmWrap.appendChild(noButton);
                bubble.appendChild(confirmWrap);
            }

            if (normalized.followUpQuestions.length > 0) {
                var followUpWrap = document.createElement('div');
                followUpWrap.className = 'aavirbhava-chat-followups';

                normalized.followUpQuestions.forEach(function (question) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = question;
                    button.addEventListener('click', function () {
                        submitMessage(question);
                    });
                    followUpWrap.appendChild(button);
                });

                bubble.appendChild(followUpWrap);
            }
        }

        function submitMessage(text) {
            var message = (text || '').trim();
            if (message === '' || loading) {
                return;
            }

            appendUserMessage(message);
            input.value = '';
            setLoading(true);
            var thinkingBubble = appendThinking();

            core.sendMessage(sendUrl, message).then(function (result) {
                thinkingBubble.remove();
                setLoading(false);
                var normalized = core.normalizeResponse(result.data);
                var shouldHide = shouldHideWidget(normalized.reasonCode);

                if (!result.ok && normalized.message === '') {
                    appendBubble('assistant', '<p>Sorry, something went wrong. Please try again.</p>');
                } else {
                    appendAssistantResponse(normalized);
                }

                // Always scheduled AFTER the message above is actually
                // in the DOM — see scheduleHideIfNeeded()'s own docblock
                // for why this ordering, plus the real setTimeout delay,
                // both matter.
                scheduleHideIfNeeded(shouldHide);
            }).catch(function () {
                thinkingBubble.remove();
                setLoading(false);
                appendBubble('assistant', '<p>Sorry, something went wrong. Please try again.</p>');
            });
        }

        function initResize() {
            var dragging = false;
            var startX = 0;
            var startY = 0;
            var startWidth = 0;
            var startHeight = 0;

            resizeHandle.addEventListener('pointerdown', function (event) {
                dragging = true;
                startX = event.clientX;
                startY = event.clientY;
                var rect = panel.getBoundingClientRect();
                startWidth = rect.width;
                startHeight = rect.height;
                if (resizeHandle.setPointerCapture) {
                    resizeHandle.setPointerCapture(event.pointerId);
                }
                event.preventDefault();
            });

            resizeHandle.addEventListener('pointermove', function (event) {
                if (!dragging) {
                    return;
                }

                // The panel is anchored by right/bottom, not left/top, so
                // growing width/height while the handle sits at the panel's
                // top-left corner naturally expands the panel upward and
                // leftward — the bottom-right corner never moves, which is
                // exactly the "resize from the top-left" behavior the
                // native CSS `resize` property can't offer (it only ever
                // drags from the bottom-right corner).
                var deltaX = startX - event.clientX;
                var deltaY = startY - event.clientY;
                var maxWidth = window.innerWidth - MAX_WIDTH_MARGIN;
                var maxHeight = window.innerHeight - MAX_HEIGHT_MARGIN;

                panel.style.width = Math.min(Math.max(startWidth + deltaX, MIN_WIDTH), maxWidth) + 'px';
                panel.style.height = Math.min(Math.max(startHeight + deltaY, MIN_HEIGHT), maxHeight) + 'px';
            });

            function stopDragging(event) {
                dragging = false;
                if (resizeHandle.releasePointerCapture && event.pointerId !== undefined) {
                    try {
                        resizeHandle.releasePointerCapture(event.pointerId);
                    } catch (error) {
                        // Capture may already be released — nothing to do.
                    }
                }
            }

            resizeHandle.addEventListener('pointerup', stopDragging);
            resizeHandle.addEventListener('pointercancel', stopDragging);
        }

        toggle.addEventListener('click', function () {
            if (isMinimized) {
                setMinimized(false);
            }
            setOpen(!isOpen);
        });

        closeButton.addEventListener('click', function () {
            setOpen(false);
            toggle.focus();
        });

        minimizeButton.addEventListener('click', function () {
            setMinimized(!isMinimized);
        });

        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen) {
                setOpen(false);
                toggle.focus();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitMessage(input.value);
        });

        initResize();

        var persisted = readPersistedState();
        if (persisted.minimized) {
            setMinimized(true);
        }
        if (persisted.open) {
            setOpen(true);
        }

        if (historyUrl) {
            core.fetchHistory(historyUrl).then(function (messages) {
                messages.forEach(function (entry) {
                    if (entry.role === 'user') {
                        appendUserMessage(entry.message);
                        return;
                    }

                    // Same rendering path a live turn's response goes
                    // through (product cards, follow-up buttons) — never
                    // awaitingConfirmation for a restored turn (see
                    // fetchHistory()'s own docblock).
                    appendAssistantResponse({
                        message: entry.message,
                        products: entry.products,
                        followUpQuestions: entry.followUpQuestions,
                        awaitingConfirmation: false
                    });
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('aavirbhava-chat-widget');
        if (root) {
            init(root);
        }
    });
})();
