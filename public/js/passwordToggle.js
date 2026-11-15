// Eye button inside password fields: shows or hides what was typed
$(document).on('click', '.password-toggle', function () {
    var input = $(this).siblings('input');
    var show = input.attr('type') === 'password';

    input.attr('type', show ? 'text' : 'password').focus();
    $(this)
        .text(show ? 'visibility_off' : 'visibility')
        .attr('aria-label', show ? 'Hide password' : 'Show password');
});
