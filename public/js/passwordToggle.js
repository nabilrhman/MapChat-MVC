// Eye button inside password fields: shows or hides what was typed
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('.password-toggle');
    if (!toggle) return;

    const input = toggle.parentElement.querySelector('input');
    const willShowPassword = input.type === 'password';

    input.type = willShowPassword ? 'text' : 'password';
    input.focus();
    toggle.textContent = willShowPassword ? 'visibility_off' : 'visibility';
    toggle.setAttribute('aria-label', willShowPassword ? 'Hide password' : 'Show password');
});
