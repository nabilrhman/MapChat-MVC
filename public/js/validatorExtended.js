jQuery.extend(jQuery.validator.messages, {
    required: "Can't be blank.",
    remote: "Please fix this field.",
    email: "Enter a valid email.",
    url: "Enter a valid URL.",
    date: "Enter a valid date.",
    dateISO: "Enter a valid date (ISO).",
    number: "Enter a valid number.",
    digits: "Enter only digits.",
    creditcard: "Enter a valid credit card number.",
    equalTo: "Enter the same value again.",
    accept: "Enter a value with a valid extension.",
    maxlength: jQuery.validator.format("Enter no more than {0} characters."),
    minlength: jQuery.validator.format("Enter at least {0} characters."),
    rangelength: jQuery.validator.format("Enter a value between {0} and {1} characters long."),
    range: jQuery.validator.format("Enter a value between {0} and {1}."),
    max: jQuery.validator.format("Enter a value less than or equal to {0}."),
    min: jQuery.validator.format("Enter a value greater than or equal to {0}.")
});

/**
 * Add jQuery Validation plugin method for a valid password
 *
 * Valid passwords contain at least one letter and one number.
 */
$.validator.addMethod('validPassword',
    function(value, element, param) {

        if (value != '') {
            if (value.match(/.*[a-z]+.*/i) == null) {
                return false;
            }
            if (value.match(/.*\d+.*/) == null) {
                return false;
            }
        }

        return true;
    },
    'Enter at least one letter and one number.'
);

/**
 * Add jQuery Validation plugin method for a valid name
 *
 * Valid name contain at least one character (no blank space).
 */
$.validator.addMethod('validName',
    function(value, element, param) {

        // Any script counts, not only Latin letters
        if (value != '') {
            if (value.match(/\S/) == null) {
                return false;
            }
        }

        return true;
    },
    'Can\'t be blank.'
);

$.validator.setDefaults({
    // Each message goes in the span under its field
    errorPlacement: function(error, element) {
        error.appendTo('#error-msg-' + element.attr('name'));
    }
});

