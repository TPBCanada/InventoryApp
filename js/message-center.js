(function () {
    'use strict';

    const launch = document.getElementById('messageLaunchBtn');
    const drawer = document.getElementById('messageDrawer');
    const closeBtn = document.getElementById('messageCloseBtn');
    const backdrop = document.getElementById('messageBackdrop');

    const list = document.getElementById('messageList');
    const status = document.getElementById('messageStatus');
    const counter = document.getElementById('messageCounter');

    const totalCount = document.getElementById('messageCount');
    const newBadge = document.getElementById('messageCountBadge');

    const openTab = document.getElementById('messageOpenTab');
    const readTab = document.getElementById('messageReadTab');
    const oldTab = document.getElementById('messageOldTab');

    const openCount = document.getElementById('messageOpenCount');
    const readCount = document.getElementById('messageReadCount');

    let currentView = 'open';

    if (!launch || !drawer || !list) {
        return;
    }

    const endpoint = window.MESSAGE_CENTER_ENDPOINT;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }


    function formatDate(value) {
        if (!value) return '';

        const date = new Date(value + 'T00:00:00');

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    function formatDateTime(value) {

        if (!value) {
            return '';
        }

        const normalized = String(value).replace(' ', 'T');

        const date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString(
            undefined,
            {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            }
        );
    }

    function setOpen(open) {
        drawer.hidden = !open;
        drawer.inert = !open;
        drawer.dataset.open = open ? 'true' : 'false';
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        launch.setAttribute('aria-expanded', open ? 'true' : 'false');

        requestAnimationFrame(() => {
            const style = getComputedStyle(drawer);
            const panel = drawer.querySelector('.message-drawer__panel');
            const rect = panel?.getBoundingClientRect();
        });

        if (open) {
            setActiveTab('open');
            loadMessages();
            loadReadCount();
        }
    }


    function setActiveTab(view = currentView) {

        currentView = view;
        openTab?.classList.toggle('is-active', view === 'open');
        readTab?.classList.toggle('is-active', view === 'read');
        oldTab?.classList.toggle('is-active', view === 'old');
    }

    function render(messages) {

        currentView = 'open';
        setActiveTab();
        const total = messages.length;

        if (openCount) {
            openCount.textContent = String(total);
        }

        /*
         * Main FAB number
         */
        if (totalCount) {
            totalCount.textContent = String(total);
        }

        /*
         * Drawer header
         */
        if (counter) {
            counter.textContent = `${total} message${total === 1 ? '' : 's'}`;
        }

        /*
         * Do not use the small badge as "new" yet.
         * True new/unread tracking comes in the next phase.
         */
        if (newBadge) {
            newBadge.hidden = true;
        }


        /*
         * Empty state
         */
        if (!total) {
            status.hidden = false;
            status.textContent = 'No active messages.';
            list.innerHTML = '';
            return;
        }

        status.hidden = true;




        /*
         * Render task-style message list
         */
        list.innerHTML = messages.map(message => `
            <article class="message-card" data-message-id="${Number(message.id)}">
                <div class="message-card__content">
                    <div class="message-card__date">
                        ${escapeHtml(formatDate(message.note_date))}
                    </div>

                    <div class="message-card__text">
                        ${escapeHtml(
            message.description ||
            message.title ||
            'Message'
        )}
                    </div>
                </div>

                <button
                    type="button"
                    class="message-card__done"
                    title="Mark as read"
                    aria-label="Mark as read">
                    ✓
                </button>
            </article>
        `).join('');
    }


    function renderReadMessages(messages) {

        currentView = 'read';
        setActiveTab();

        if (readCount) {
            readCount.textContent = String(messages.length);
        }


        if (!messages.length) {

            status.hidden = false;

            status.textContent =
                'No read messages from the last 30 days.';

            list.innerHTML = '';

            return;
        }


        status.hidden = true;


        list.innerHTML =
            messages.map(message => `

            <article
                class="message-card message-card--read">

                <div class="message-card__content">

                    <div class="message-card__date">
                        ${escapeHtml(
                formatDate(
                    message.note_date
                )
            )}
                    </div>


                    <div class="message-card__text">
                        ${escapeHtml(
                message.description ||
                message.title ||
                'Message'
            )}
                    </div>


                    <div class="message-card__read-date">
                        Read:
                        ${escapeHtml(
                formatDateTime(
                    message.read_at
                )
            )}
                    </div>

                </div>

            </article>

        `).join('');
    }


    function renderStoredMessages(messages, view) {

        const isOld = view === 'old';
        const label = isOld ? 'old' : 'read';

        setActiveTab(view);

        if (counter) {
            counter.textContent =
                `${messages.length} ${label} message` +
                `${messages.length === 1 ? '' : 's'}`;
        }


        if (!messages.length) {

            status.hidden = false;
            status.textContent = isOld ? 'No old messages.' : 'No recently read messages.';
            list.innerHTML = '';
            return;
        }

        status.hidden = true;

        list.innerHTML = messages.map(message => `

            <article
                class="message-card message-card--read">
                <div class="message-card__content">
                    <div class="message-card__date">
                        ${escapeHtml(
            formatDate(message.note_date)
        )}

                    </div>
                    <div class="message-card__text">
                        ${escapeHtml(message.description || message.title || 'Message')}
                    </div>


                    <div class="message-card__read-date">
                        Read:
                        ${escapeHtml(formatDateTime(message.read_at)
        )}

                    </div>
                </div>
            </article>
        `).join('');
    }


    // --------------------
    //  click handler
    // --------------------

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('.message-card__done');

        if (!button) return;

        const card = button.closest('.message-card');

        if (!card) {
            return;
        }

        const messageId = Number(card.dataset.messageId || 0);

        if (!messageId) {
            return;
        }

        button.disabled = true;

        try {

            const response = await fetch(
                endpoint,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/json','Accept':'application/json'},
                    body: JSON.stringify({action: 'mark_read', id: messageId})
                }
            );


            const text = await response.text();
            let payload;

            try {
                payload = JSON.parse(text);
            } catch (_) {
                throw new Error('Server returned non-JSON response.');
            }

            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || 'Unable to mark message read.');
            }

            await loadMessages();
            await loadReadCount();

        } catch (_) {
            button.disabled = false;
        }

    });


    async function loadReadCount() {

        if (!endpoint || !readCount) {
            return;
        }

        try {
            const response = await fetch(
                `${endpoint}?view=read_messages`,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json'
                    }
                }
            );


            const payload = await response.json();

            if (response.ok && payload.ok !== false) {
                readCount.textContent = String(Number(payload.count || 0));
            }

        } catch (_) {
            // Do not block Message Center
            // just because the count failed.
        }
    }

    async function loadStoredMessages(view) {

        if (!endpoint) {
            return;
        }


        const apiView =
            view === 'old'
                ? 'old_messages'
                : 'read_messages';


        setActiveTab(view);

        status.hidden = false;

        status.textContent =
            view === 'old'
                ? 'Loading old messages...'
                : 'Loading read messages...';


        try {

            const response = await fetch(
                `${endpoint}?view=${apiView}`,
                {
                    credentials: 'same-origin',

                    headers: {
                        Accept: 'application/json'
                    }
                }
            );


            const text =
                await response.text();


            let payload;


            try {

                payload =
                    JSON.parse(text);

            } catch (_) {

                throw new Error(
                    'Message service returned invalid JSON.'
                );
            }


            if (
                !response.ok ||
                payload.ok === false
            ) {

                throw new Error(
                    payload.error ||
                    'Unable to load messages.'
                );
            }


            const messages =
                Array.isArray(payload.messages)
                    ? payload.messages
                    : [];


            renderStoredMessages(
                messages,
                view
            );


        } catch (error) {

            status.hidden = false;
            status.textContent = error.message || 'Unable to load messages.';
            list.innerHTML = '';
        }
    }

    async function loadMessages() {
        if (!endpoint) {
            status.hidden = false;
            status.textContent = 'Message Center endpoint is not configured.';
            return;
        }

        status.hidden = false;
        status.textContent = 'Loading messages...';

        try {

            const response = await fetch(
                `${endpoint}?view=message_center`,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json'
                    }
                }
            );


            const text = await response.text();
            let payload;

            try {
                payload = JSON.parse(text);
            } catch (_) {
                throw new Error('Message service returned invalid JSON.');
            }

            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || 'Unable to load messages.');
            }

            /*
             * Message Center endpoint now returns:
             *
             * {
             *   ok: true,
             *   messages: [...]
             * }
             */

            const messages = Array.isArray(payload.messages) ? payload.messages : [];
            render(messages);

        } catch (error) {
            status.hidden = false;
            status.textContent = error && error.message ? error.message : 'Unable to load messages.';
            list.innerHTML = '';
        }
    }


    async function loadReadMessages() {

        if (!endpoint) {
            return;
        }

        currentView = 'read';
        setActiveTab();
        status.hidden = false;
        status.textContent = 'Loading read messages...';

        try {

            const response = await fetch(
                `${endpoint}?view=read_messages`,
                {
                    credentials: 'same-origin',

                    headers: {
                        Accept: 'application/json'
                    }
                }
            );


            const text = await response.text();
            let payload;

            try {
                payload = JSON.parse(text);

            } catch (_) {
                throw new Error('Message service returned invalid JSON.');
            }


            if (
                !response.ok || payload.ok === false) {

                throw new Error(payload.error || 'Unable to load read messages.');
            }

            const messages = Array.isArray(payload.messages) ? payload.messages : [];
            renderReadMessages(messages);

        } catch (error) {

            status.hidden = false;
            status.textContent = error.message || 'Unable to load read messages.';
            list.innerHTML = '';
        }
    }

    openTab?.addEventListener(
        'click', () => {
            loadMessages();
        }
    );

    readTab?.addEventListener(
        'click', () => {
            loadReadMessages();
        }
    );


    launch.addEventListener('click', () => {
        const isClosed = drawer.getAttribute('aria-hidden') === 'true';
        setOpen(isClosed);
    });


    if (closeBtn) {
        closeBtn.addEventListener(
            'click', () => setOpen(false)
        );
    }


    if (backdrop) {
        backdrop.addEventListener(
            'click', () => setOpen(false)
        );
    }


    document.addEventListener(
        'keydown', event => {
            if (event.key === 'Escape' && drawer.getAttribute('aria-hidden') === 'false') {
                setOpen(false);
            }
        }
    );

    loadMessages();

})();
