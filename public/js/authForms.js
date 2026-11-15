// Checks on the sign-up and password forms before they're sent. Each page has
// at most one of these forms; the others are skipped

$(document).ready(function () {

    $('#formSignup').validate({
        rules: {
            name: {
                required: true,
                validName: true
            },
            email: {
                required: true,
                email: true,
                remote: '/account/validate-email'
            },
            password: {
                required: true,
                minlength: 6,
                validPassword: true
            }
        },
        messages: {
            email: {
                remote: 'Email already registered.'
            }
        }
    });

    $('#formForgot').validate({
        rules: {
            email: {
                required: true,
                email: true
            }
        }
    });

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
