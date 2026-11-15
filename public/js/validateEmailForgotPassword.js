$(document).ready(function () {

    $('#formForgot').validate({
        rules: {
            email: {
                required: true,
                validEmail: true
            }
        }
    });

});
