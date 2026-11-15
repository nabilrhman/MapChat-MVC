$(document).ready(function () {

    $('#formPassword').validate({
        rules: {
            password: {
                required: true,
                minlength: 6,
                validPassword: true
            }
        }
    });
});
