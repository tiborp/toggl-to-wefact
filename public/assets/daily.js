// Submit the existing local GET filter so search parameters and no-JS behavior stay server-owned.
document.addEventListener('change', (event) => {
    if (!event.target.matches('[data-project-archive-toggle]')) return;
    const form = event.target.form;
    if (!form?.matches('[data-project-catalog-filter]') || form.dataset.submitting === 'true') return;
    form.requestSubmit();
});
// Searching hides choices only; keep the current selection visible and leave billing data untouched.
document.addEventListener('input', (event) => {
    if (!event.target.matches('[data-project-search]')) return;
    const select = document.getElementById('project-filter');
    const query = event.target.value.trim().toLocaleLowerCase('nl');
    for (const option of select.options) {
        option.hidden = option.value !== '' && !option.selected && !option.textContent.toLocaleLowerCase('nl').includes(query);
    }
});
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('[data-project-catalog-filter]')) {
        if (form.dataset.submitting === 'true') event.preventDefault();
        else form.dataset.submitting = 'true';
        return;
    }
    if (!form.matches('[data-confirm-invoice]')) return;
    if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
    if (event.submitter?.value !== 'confirm') return;
    // Keep the clicked action in POST before disabling buttons (disabled controls are omitted).
    const action = document.createElement('input');
    action.type = 'hidden'; action.name = 'action'; action.value = 'confirm';
    form.appendChild(action);
    form.dataset.submitting = 'true';
    form.querySelector('[data-confirm-status]').hidden = false;
    for (const button of form.querySelectorAll('button')) button.disabled = true;
});
// Back/forward cache may restore disabled buttons and submit guards from a previous visit.
// Reset only client UI state; server-side tokens still decide whether an action is allowed.
window.addEventListener('pageshow', () => {
    for (const form of document.querySelectorAll('[data-project-catalog-filter]')) {
        delete form.dataset.submitting;
    }
    for (const form of document.querySelectorAll('[data-confirm-invoice]')) {
        delete form.dataset.submitting;
        for (const action of form.querySelectorAll('input[name="action"][type="hidden"]')) action.remove();
        for (const button of form.querySelectorAll('button')) button.disabled = false;
        form.querySelector('[data-confirm-status]').hidden = true;
    }
    const preview = document.getElementById('preview-selection');
    if (preview) delete preview.dataset.submitting;
});
