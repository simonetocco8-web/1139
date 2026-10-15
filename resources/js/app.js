const restoreFilters = () => {
    document.querySelectorAll('[data-report-filter]').forEach((form) => {
        form.removeAttribute('aria-busy');
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = false;
        form.querySelector('[data-filter-status]')?.classList.add('hidden');
    });
};
const setupMenu = () => document.querySelectorAll('[data-menu-toggle]').forEach((button) => { button.hidden = false; });
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupMenu);
else setupMenu();
document.addEventListener('livewire:navigated', setupMenu);
window.addEventListener('pageshow', restoreFilters);
document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;
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
