// Checks on the sign-up and password forms before they're sent. Each page has
// at most one of these forms; the others are skipped
const PASSWORD_CHECKS = [
    FIELD_CHECKS.required(),
    FIELD_CHECKS.minLength(6),
    FIELD_CHECKS.letterAndNumber,
];

const AUTH_FORM_CHECKS = {
    'signup-form': {
        name: [FIELD_CHECKS.required()],
        email: [FIELD_CHECKS.required(), FIELD_CHECKS.email, FIELD_CHECKS.emailIsFree()],
        password: PASSWORD_CHECKS,
    },
    'forgot-form': {
        email: [FIELD_CHECKS.required(), FIELD_CHECKS.email],
    },
    'reset-password-form': {
        password: PASSWORD_CHECKS,
    },
};

Object.entries(AUTH_FORM_CHECKS).forEach(([formId, checksByFieldName]) => {
    const form = document.getElementById(formId);
    if (form) setUpFormChecks(form, checksByFieldName);
});
