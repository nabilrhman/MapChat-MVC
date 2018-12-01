setTimeout(function() {
    const fade = { opacity: 0, transition: 'opacity 0.2s' };
    $(".alert").css(fade).slideUp("normal", function() {
        $(this).remove();
    })
}, 4500);