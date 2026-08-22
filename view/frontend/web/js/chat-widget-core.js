/**
 * Aavirbhava AI Shopping Assistant — shared chat widget core.
 *
 * Dependency-free vanilla JS (no jQuery/Knockout/RequireJS/Alpine
 * requirement of its own) so the exact same network call and response
 * normalization logic is shared by both the default/Luma presentation
 * layer (chat-widget-luma.js, imperative DOM) and the Hyva presentation
 * layer (chat-widget-hyva.js, an Alpine.js data component) — only actual
 * DOM/reactive rendering differs between the two, since Luma's imperative
 * DOM manipulation and Hyva's declarative Alpine bindings are different
 * enough paradigms that unifying rendering itself would force one theme
 * to use the other's idioms. This file owns none of that: it only talks
 * to Controller\Chat\Send and shapes its JSON into a plain view-model.
 *
 * Loaded as a plain <script> tag (not an AMD module), so it works
 * identically regardless of whether RequireJS is present on the page.
 */
(function (global) {
    'use strict';

    var LOG_PREFIX = '[Aavirbhava AI Assistant]';

    /**
     * Mirrors ChatEntryPipeline::REASON_ASSISTANT_DOWN (Task 45) — a
     * provider failure confirmed to recur identically on every next
     * message (an exhausted quota, an invalid/revoked API key), as
     * opposed to a merely transient one. Both presentation layers hide
     * the widget entirely for the rest of the visit on this reason code
     * (Task 46) rather than inviting the customer to keep typing into a
     * conversation that cannot proceed; a reload re-evaluates the
     * widget's own server-side render gate (Task 44), which will hide it
     * entirely on the SERVER side too once the same confirmed-down
     * circuit-breaker state is visible there.
     */
    var REASON_ASSISTANT_DOWN = 'assistant_down';

    /**
     * Mirrors ChatEntryPipeline::REASON_ASSISTANT_UNAVAILABLE — a
     * provider failure expected to be momentary (a slow response, a
     * dropped connection, one malformed reply). A single one of these is
     * not, on its own, evidence the assistant is genuinely down (see
     * REASON_RETRIEVAL_UNAVAILABLE below for the sibling backend-failure
     * code) — both presentation layers only hide the widget after
     * several of these happen in a row with nothing else in between
     * (SOFT_FAILURE_HIDE_THRESHOLD), not on the first one.
     */
    var REASON_ASSISTANT_UNAVAILABLE = 'assistant_unavailable';

    /**
     * Mirrors ChatEntryPipeline::REASON_RETRIEVAL_UNAVAILABLE — the same
     * "momentary, not yet confirmed down" meaning as
     * REASON_ASSISTANT_UNAVAILABLE above, just for a retrieval/embedding-
     * provider failure instead of an LLM one. Counted identically toward
     * the same consecutive-soft-failure threshold — from the customer's
     * point of view, either one means "the assistant didn't answer this
     * time," the specific backend that failed is an implementation
     * detail only the debug log needs.
     */
    var REASON_RETRIEVAL_UNAVAILABLE = 'retrieval_unavailable';

    /**
     * How many consecutive soft-failure responses (assistant_unavailable/
     * retrieval_unavailable, with no successful or out-of-scope response
     * in between resetting the count) are treated as confirmed-down
     * evidence, same as a single assistant_down response. Matches this
     * module's own default `fallback/failure_threshold` admin setting
     * (3) for familiarity, though the two are independent: this counts
     * customer-visible response outcomes across a session, the backend
     * setting counts raw provider call failures within a single request.
     */
    var SOFT_FAILURE_HIDE_THRESHOLD = 3;

    /**
     * How long the failure message stays genuinely visible on screen
     * before the widget hides itself (Task 47) — long enough to read a
     * short one/two-sentence message. Deciding to hide and actually
     * hiding are deliberately two separate steps, each presentation
     * layer's own responsibility to sequence correctly: append/render
     * the message bubble FIRST, only THEN schedule the hide via a real
     * `setTimeout(..., HIDE_DELAY_MS)` — never hide synchronously in the
     * same step that decides to, even after the message has been
     * appended to the DOM/reactive state, since a synchronous hide
     * immediately after gives the browser no real chance to paint the
     * message before it disappears (a real, live-reproduced bug: the
     * widget was hiding before the customer could read why).
     */
    var HIDE_DELAY_MS = 5000;

    /**
     * Always-on console.debug logging of each request/response cycle —
     * this module has no `general.debug_logging` admin toggle threaded to
     * the frontend (checked; it doesn't exist), and unlike customer-
     * visible UI text, browser console output carries no customer-facing
     * harm, so it isn't gated behind one.
     *
     * @param {string} endpointUrl
     * @param {string} message
     * @returns {Promise<{ok: boolean, status: number, data: Object}>}
     */
    function sendMessage(endpointUrl, message) {
        console.debug(LOG_PREFIX, 'sending message', {message: message});

        return fetch(endpointUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({message: message})
        }).then(function (response) {
            return response.json()
                .catch(function () {
                    return {};
                })
                .then(function (data) {
                    console.debug(LOG_PREFIX, 'response received', {
                        status: response.status,
                        ok: response.ok,
                        reasonCode: data && data.reason_code,
                        metadata: data && data.metadata,
                        awaitingConfirmation: data && data.awaiting_confirmation,
                        response: data
                    });

                    return {ok: response.ok, status: response.status, data: data};
                });
        }).catch(function (error) {
            console.debug(LOG_PREFIX, 'request failed', error);

            throw error;
        });
    }

    /**
     * Restores the visible transcript from Controller\Chat\History (GET
     * /aichat/chat/history) after a page reload or a fresh tab — as long
     * as the browser still holds the same session cookie the conversation
     * was started under, the backend still remembers it (Task 8's
     * conversation history, scoped by ChatSession's conversationId), this
     * just brings the widget's own UI state back in sync with it. Always
     * resolves, never rejects — a restore is a nice-to-have; any failure
     * (network, non-2xx, malformed body) degrades to "nothing to
     * restore," exactly like a first-time visitor, never a broken widget.
     *
     * Each restored assistant entry's products are normalized through the
     * exact same normalizeProduct() a live turn's response already goes
     * through (Task 20) — real prices/URLs/SKUs the backend already
     * live-revalidated at the time, not re-derived or guessed here — so a
     * restored turn's product cards render through the identical code a
     * live turn's do. followUpQuestions is carried through too, but never
     * awaitingConfirmation: a restored turn never re-offers a confirm/
     * cancel affordance for a token from a past page load, since that
     * token is short-lived server-side and offering it again would just
     * invite a confusing, already-expired confirmation attempt.
     *
     * @param {string} historyUrl
     * @returns {Promise<list<{role: string, message: string, products: Array, followUpQuestions: Array}>>}
     */
    function fetchHistory(historyUrl) {
        return fetch(historyUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            });
        }).then(function (data) {
            var messages = data && Array.isArray(data.messages) ? data.messages : [];

            console.debug(LOG_PREFIX, 'restored history', {count: messages.length});

            return messages
                .filter(function (entry) {
                    return entry && (entry.role === 'user' || entry.role === 'assistant') && isNonEmptyString(entry.message);
                })
                .map(function (entry) {
                    return {
                        role: entry.role,
                        message: entry.message,
                        products: Array.isArray(entry.products) ? entry.products.map(normalizeProduct) : [],
                        followUpQuestions: Array.isArray(entry.follow_up_questions)
                            ? entry.follow_up_questions.filter(isNonEmptyString)
                            : []
                    };
                });
        }).catch(function (error) {
            console.debug(LOG_PREFIX, 'history restore failed', error);

            return [];
        });
    }

    function isNonEmptyString(value) {
        return typeof value === 'string' && value.trim() !== '';
    }

    /**
     * True for either "momentary" backend-failure reason code — see
     * REASON_ASSISTANT_UNAVAILABLE/REASON_RETRIEVAL_UNAVAILABLE's own
     * docblocks above. Centralized here so both presentation layers
     * count consecutive soft failures identically rather than each
     * re-implementing (and risking drifting) the same OR check.
     *
     * @param {?string} reasonCode
     * @returns {boolean}
     */
    function isSoftFailureReason(reasonCode) {
        return reasonCode === REASON_ASSISTANT_UNAVAILABLE || reasonCode === REASON_RETRIEVAL_UNAVAILABLE;
    }

    /**
     * Normalizes Controller\Chat\Send's JSON body (see
     * Model\Chat\ChatResponseSerializer) into a plain object the
     * presentation layer can render directly. Every product field here is
     * exactly what the backend already sent — this function invents
     * nothing (no price/URL/image is ever fabricated client-side).
     */
    function normalizeResponse(raw) {
        raw = raw && typeof raw === 'object' ? raw : {};

        return {
            message: isNonEmptyString(raw.message) ? raw.message : '',
            reasonCode: isNonEmptyString(raw.reason_code) ? raw.reason_code : null,
            products: Array.isArray(raw.products) ? raw.products.map(normalizeProduct) : [],
            followUpQuestions: Array.isArray(raw.follow_up_questions)
                ? raw.follow_up_questions.filter(isNonEmptyString)
                : [],
            awaitingConfirmation: raw.awaiting_confirmation === true
        };
    }

    function normalizeProduct(product) {
        product = product && typeof product === 'object' ? product : {};

        return {
            sku: isNonEmptyString(product.sku) ? product.sku : '',
            name: isNonEmptyString(product.name) ? product.name : product.sku || '',
            price: typeof product.price === 'number' ? product.price : null,
            specialPrice: typeof product.special_price === 'number' ? product.special_price : null,
            url: isNonEmptyString(product.url) ? product.url : null,
            imageUrl: isNonEmptyString(product.image_url) ? product.image_url : null,
            reason: isNonEmptyString(product.reason) ? product.reason : '',
            recommendationType: isNonEmptyString(product.recommendation_type)
                ? product.recommendation_type
                : 'organic'
        };
    }

    function formatPrice(amount) {
        return typeof amount === 'number' ? '$' + amount.toFixed(2) : '';
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    /**
     * Minimal, dependency-free markdown-to-HTML for the handful of
     * patterns actually seen in real assistant responses: **bold**,
     * *italic*, bullet lists (-/*), numbered lists (1.), and paragraph/
     * line breaks. Not a general markdown parser — no links, headings,
     * code blocks, etc., since none of those appear in real responses
     * this module generates (the response contract's own message field
     * is prose, never markdown links or code).
     *
     * Safety: $text ultimately originates from the LLM — treated as
     * untrusted for escaping purposes, the same discipline this module
     * applies to every other LLM-sourced string. The raw text is
     * HTML-escaped FIRST via escapeHtml(), and every tag this function
     * itself injects afterward is a fixed, hardcoded string (`<strong>`,
     * `<em>`, `<ul>`, `<li>`, `<p>`, `<br>`) — never text captured from a
     * regex group is used as a *tag name* or *attribute*, only ever
     * placed between existing tags as already-escaped, inert content.
     * This mirrors renderMarkdown's own regex-only, non-eval approach:
     * nothing the model writes can introduce real HTML, only the literal
     * `**`/`*`/`-`/`1.` characters this function recognizes as
     * formatting.
     *
     * Bold is converted BEFORE italic, and only bold's regex consumes
     * `**` pairs — the classic single-asterisk-matches-inside-`**bold**`
     * trap this order avoids: by the time the italic regex runs, every
     * `**...**` sequence has already become a real `<strong>` tag with no
     * asterisks left in it, so a lone `*...*` pair is the only thing left
     * for italic to find, whether it appears alongside bold in the same
     * message or entirely on its own.
     *
     * @param {string} text
     * @returns {string} safe HTML
     */
    function renderMarkdown(text) {
        var escaped = escapeHtml(text);

        // Bold: **text** -> <strong>text</strong>. Operates on the
        // already-escaped string, so a literal '*' the model wrote is the
        // only thing this can ever match — HTML entities like &amp;/&lt;
        // never contain '*'.
        escaped = escaped.replace(/\*\*([^\n*]+)\*\*/g, '<strong>$1</strong>');

        // Italic: *text* -> <em>text</em>. Runs after bold specifically so
        // it only ever sees single-asterisk pairs (see the docblock above).
        escaped = escaped.replace(/\*([^\n*]+)\*/g, '<em>$1</em>');

        var blocks = [];
        var paragraphLines = [];
        var listItems = null;
        var listTag = null;

        function flushParagraph() {
            if (paragraphLines.length > 0) {
                blocks.push('<p>' + paragraphLines.join('<br>') + '</p>');
                paragraphLines = [];
            }
        }

        function flushList() {
            if (listItems !== null) {
                blocks.push('<' + listTag + '>' + listItems.join('') + '</' + listTag + '>');
                listItems = null;
                listTag = null;
            }
        }

        escaped.split('\n').forEach(function (line) {
            var trimmed = line.trim();
            var bulletMatch = /^[-*]\s+(.+)$/.exec(trimmed);
            var numberedMatch = /^\d+\.\s+(.+)$/.exec(trimmed);

            if (bulletMatch) {
                flushParagraph();
                if (listTag !== 'ul') {
                    flushList();
                    listTag = 'ul';
                    listItems = [];
                }
                listItems.push('<li>' + bulletMatch[1] + '</li>');
                return;
            }

            if (numberedMatch) {
                flushParagraph();
                if (listTag !== 'ol') {
                    flushList();
                    listTag = 'ol';
                    listItems = [];
                }
                listItems.push('<li>' + numberedMatch[1] + '</li>');
                return;
            }

            flushList();

            if (trimmed === '') {
                flushParagraph();
                return;
            }

            paragraphLines.push(trimmed);
        });

        flushList();
        flushParagraph();

        return blocks.length > 0 ? blocks.join('') : '<p></p>';
    }

    global.AavirbhavaChatCore = {
        REASON_ASSISTANT_DOWN: REASON_ASSISTANT_DOWN,
        REASON_ASSISTANT_UNAVAILABLE: REASON_ASSISTANT_UNAVAILABLE,
        REASON_RETRIEVAL_UNAVAILABLE: REASON_RETRIEVAL_UNAVAILABLE,
        SOFT_FAILURE_HIDE_THRESHOLD: SOFT_FAILURE_HIDE_THRESHOLD,
        HIDE_DELAY_MS: HIDE_DELAY_MS,
        isSoftFailureReason: isSoftFailureReason,
        sendMessage: sendMessage,
        fetchHistory: fetchHistory,
        normalizeResponse: normalizeResponse,
        formatPrice: formatPrice,
        escapeHtml: escapeHtml,
        renderMarkdown: renderMarkdown
    };
})(window);
