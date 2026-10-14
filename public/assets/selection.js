// Limit bulk selection to this project and eligible inputs; totals are recalculated by the server.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-select-project]');
    if (!button) return;
    const section = button.closest('.hours-group');
    section.querySelectorAll('input[data-entry-selection]:not(:disabled)').forEach((input) => {
        input.checked = button.dataset.selectProject === 'all';
    });
});

// Reuse the server form, including CSRF/dataset/selection, instead of duplicating calculations.
// preview_target lets the response anchor return to the project whose layout changed.
document.addEventListener('change', (event) => {
    const input = event.target.closest('input[data-preview-project]');
    if (!input || !input.checked) return;
    const form = input.form;
    if (!form || form.id !== 'preview-selection' || form.dataset.submitting === 'true' || typeof form.requestSubmit !== 'function') return;
    form.elements.namedItem('preview_target').value = input.dataset.previewProject;
    form.requestSubmit();
});

// Prevent overlapping preview requests; browser-back resets this guard in daily.js.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.id !== 'preview-selection') return;
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = 'true';
});
