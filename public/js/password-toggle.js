// Eye button inside password fields: shows or hides what was typed
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-part="password-toggle"]');
    if (!toggle) return;

    const input = toggle.closest('[data-part="field"]').querySelector('[data-part="field-input"]');
    const willShowPassword = input.type === 'password';

    input.type = willShowPassword ? 'text' : 'password';
    input.focus();
    toggle.replaceChildren(createIcon(willShowPassword ? 'eye-closed' : 'eye'));
    toggle.setAttribute('aria-label', willShowPassword ? 'Hide password' : 'Show password');
});
