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
                validEmail: true,
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

});
