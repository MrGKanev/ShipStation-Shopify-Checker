//
document.querySelectorAll('[data-print-window]').forEach((button) => {
    button.addEventListener('click', () => window.print());
});
