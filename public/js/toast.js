// Small messages in the corner, styled like the notices on the log-in pages:
// tinted by type (success, info or warning) with a matching icon.
function showToast(text, type) {
    type = type || 'success';
    var icons = {success: 'check_circle', info: 'info_outline', warning: 'error_outline'};

    var html = $('<div>')
        .append($('<i class="material-icons toast__icon">').text(icons[type] || icons.info))
        .append($('<span>').text(text))
        .html();

    M.toast({html: html, classes: 'toast--' + type, displayLength: 4500});
}
