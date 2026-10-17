const restoreFilters = () => {
    document.querySelectorAll('[data-report-filter]').forEach((form) => {
        form.removeAttribute('aria-busy');
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = false;
        form.querySelector('[data-filter-status]')?.classList.add('hidden');
    });
};
const setupMenu = () => document.querySelectorAll('[data-menu-toggle], [data-submenu-toggle]').forEach((button) => { button.hidden = false; });
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupMenu);
else setupMenu();
document.addEventListener('livewire:navigated', setupMenu);
window.addEventListener('pageshow', restoreFilters);
document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;
    const submenu = event.target.closest('[data-submenu-toggle]');
    if (submenu) {
        const panel = document.getElementById(submenu.getAttribute('aria-controls'));
        if (panel) {
            const open = submenu.getAttribute('aria-expanded') !== 'true';
            submenu.setAttribute('aria-expanded', String(open));
            panel.hidden = !open;
        }
        return;
    }
    const toggle = event.target.closest('[data-menu-toggle]');
    if (toggle) {
        const nav = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!nav) return;
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(open));
        nav.classList.toggle('hidden', !open);
        nav.classList.toggle('flex', open);
        return;
    }
    const link = event.target.closest('[data-report-export]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (link.getAttribute('aria-busy') === 'true') return;
    const status = document.querySelector('[data-export-status]');
    link.setAttribute('aria-busy', 'true');
    if (status) { status.setAttribute('role', 'status'); status.textContent = 'Preparazione del documento…'; }
    try {
        const response = await fetch(link.href, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        updateSessionFromResponse(response);
        if (!response.ok) {
            let message = 'Esportazione non disponibile. Riprova o verifica la sessione e i permessi.';
            const content = await response.json().catch(() => null);
            if (response.status === 422 && typeof content?.message === 'string') message = content.message;
            throw new Error(message);
        }
        const expectedType = link.dataset.format === 'pdf' ? 'application/pdf' : 'text/csv';
        if (!response.headers.get('Content-Type')?.includes(expectedType)) throw new Error('Documento non disponibile. Verifica la sessione e riprova.');
        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = 'report.' + link.dataset.format;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        if (status) status.textContent = 'Documento pronto. Download avviato.';
    } catch (error) {
        if (status) { status.setAttribute('role', 'alert'); status.textContent = error.message; }
    } finally {
        link.removeAttribute('aria-busy');
    }
});
document.addEventListener('submit', (event) => {
    if (!event.target.matches('[data-report-filter]')) return;
    event.target.setAttribute('aria-busy', 'true');
    const button = event.target.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    event.target.querySelector('[data-filter-status]')?.classList.remove('hidden');
});

document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLSelectElement && event.target.matches('[data-report-switch]')) window.location.assign(event.target.value);
});

// The clock displays server activity deadlines; it never sends keep-alive requests.
let sessionLocked = false;
const lockExpiredSession = () => {
    if (sessionLocked) return;
    sessionLocked = true;
    document.querySelectorAll('[data-private-content]').forEach((element) => element.replaceChildren());
    const notice = document.querySelector('[data-session-lock]');
    if (notice) { notice.hidden = false; notice.focus(); }
    document.title = 'Sessione scaduta';
};
let sessionClock = null;
let sessionTicker = null;
const paintSessionClock = () => {
    if (!sessionClock?.element.isConnected) return;
    const remaining = Math.max(0, Math.ceil((sessionClock.expires * 1000 - Date.now() - sessionClock.offset) / 1000));
    const minutes = Math.floor(remaining / 60);
    sessionClock.element.querySelector('[data-session-remaining]').textContent = `${String(minutes).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
    sessionClock.element.querySelector('[data-session-expired]').hidden = remaining !== 0;
    sessionClock.element.classList.toggle('text-amber-900', remaining > 0 && remaining <= 120);
    sessionClock.element.classList.toggle('text-red-800', remaining === 0);
    if (remaining === 0) lockExpiredSession();
};
const setSessionDeadline = (expires, serverNow, publish = true) => {
    if (!sessionClock || !Number.isFinite(expires) || !Number.isFinite(serverNow)) return;
    sessionClock.expires = expires;
    sessionClock.offset = serverNow * 1000 - Date.now();
    sessionClock.element.dataset.expiresAt = String(expires);
    if (publish) {
        try { localStorage.setItem(sessionClock.key, JSON.stringify({ expires, offset: sessionClock.offset })); } catch (_) { /* Storage may be disabled. */ }
    }
    paintSessionClock();
};
const setupSessionClock = () => {
    clearInterval(sessionTicker);
    const element = document.querySelector('[data-session-clock]');
    if (!element) { sessionClock = null; return; }
    sessionClock = { element, key: 'multi-loggia.session.' + element.dataset.sessionKey };
    let expires = Number(element.dataset.expiresAt);
    let serverNow = Number(element.dataset.serverNow);
    try {
        const stored = JSON.parse(localStorage.getItem(sessionClock.key));
        if (stored && Number.isFinite(stored.expires) && Number.isFinite(stored.offset) && stored.expires > expires) {
            expires = stored.expires;
            serverNow = (Date.now() + stored.offset) / 1000;
        }
    } catch (_) { /* Server validation remains authoritative. */ }
    setSessionDeadline(expires, serverNow);
    sessionTicker = setInterval(paintSessionClock, 1000);
};
const updateSessionFromResponse = (response) => {
    if (response.headers.get('X-Session-Expired') === '1' || response.status === 419) lockExpiredSession();
    const expires = response.headers.get('X-Session-Expires-At');
    const serverNow = response.headers.get('X-Session-Server-Time');
    if (expires !== null && serverNow !== null) setSessionDeadline(Number(expires), Number(serverNow));
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupSessionClock);
else setupSessionClock();
document.addEventListener('livewire:navigated', setupSessionClock);
document.addEventListener('visibilitychange', paintSessionClock);
window.addEventListener('pageshow', paintSessionClock);
window.addEventListener('storage', (event) => {
    if (!sessionClock || event.key !== sessionClock.key || !event.newValue) return;
    try {
        const next = JSON.parse(event.newValue);
        if (Number.isFinite(next.expires) && Number.isFinite(next.offset)) {
            sessionClock.expires = next.expires;
            sessionClock.offset = next.offset;
            paintSessionClock();
        }
    } catch (_) { /* Ignore malformed display state; the server still enforces expiry. */ }
});
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ respond }) => respond(({ response }) => updateSessionFromResponse(response)));
});
