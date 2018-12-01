


$(document).ready(function () {

    M.updateTextFields();

    /**
     * Validate the form
     */
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


    /**
     * Show password toggle button
     */
    $('#inputPassword').hideShowPassword({
        show: false,
        innerToggle: 'focus',
        // Options specific to the wrapper element, created
        // when the innerToggle is initialized to help with
        // positioning of that element.

        // Event to trigger whenever the element is toggled.
        // For example, if 'focus' it will focus the cursor in the
        // input element after toggling.
        triggerOnToggle: false,
        wrapper: {
            // Class name of element.
            className: 'inputPassword',
            // If true, the width of the wrapper will be set
            // unless it is already the same width as the inner
            // element. If false, the width will never be set. Any
            // other value will be used as the width.
            enforceWidth: true,
            // Styles to add to the wrapper element. Does not
            // include inherited styles or width if enforceWidth
            // is not false.
            styles: {position: 'relative'},
            // Styles to "inherit" from the input element, allowing
            // the wrapper to avoid disrupting page styles.
            inheritStyles: [
                'verticalAlign',
                'marginTop',
                'marginRight',
                'marginBottom',
                'marginLeft',
                'width'
            ],
        },
    });

    $("input[name='password']").on("keyup click", (function () {
        $('#passwordLabel').addClass('active');
    }));

    $("input[name='name']").on("click", (function () {
        if ($("input[name='password']").val() == "") {
            if ($('#passwordLabel').hasClass('active')) {
                $('#passwordLabel').removeClass('active');
            }
        }
    }));

    $("input[name='email']").on("click", (function () {
        if ($("input[name='password']").val() == "") {
            if ($('#passwordLabel').hasClass('active')) {
                $('#passwordLabel').removeClass('active');
            }
        }
    }));

    $("input[name='password']").keydown(function () {
        if ($("input[name='password']").val() == "") {
            if ($('#passwordLabel').hasClass('active')) {
                $('#passwordLabel').removeClass('active');
            }
        }
    });

});