


$(document).ready(function () {

    M.updateTextFields();

    /**
     * Validate the form
     */
    $('#formForgot').validate({
        rules: {

            email: {
                required: true,
                validEmail: true,
            }
        },
    });

    $("input[name='email']").on("click", (function () {
        if ($("input[name='password']").val() == "") {
            if ($('#passwordLabel').hasClass('active')) {
                $('#passwordLabel').removeClass('active');
            }
        }
    }));


});