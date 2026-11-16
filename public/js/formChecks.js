// Checks a form's fields before it's sent and shows each field's problem
// under it. A field is checked when you leave it, then again as you type
// once it has been checked; every field is checked on submit. Fields that
// aren't shown (a closed card, say) are skipped

// Each check is a test and the message shown when it fails. Tests that only
// apply to a filled-in field pass an empty one, so "required" speaks first
const FIELD_CHECKS = {
    required: (message = "Can't be blank.") => ({
        message,
        test: (value) => value.trim() !== '',
    }),
    email: {
        message: 'Enter a valid email.',
        test: (value, field) => !field.validity.typeMismatch,
    },
    minLength: (length) => ({
        message: `Enter at least ${length} characters.`,
        test: (value) => value === '' || value.length >= length,
    }),
    letterAndNumber: {
        message: 'Enter at least one letter and one number.',
        test: (value) => value === '' || (/[a-z]/i.test(value) && /\d/.test(value)),
    },
    // Asks the server; the id is your own account's, which may keep its email
    emailIsFree: (getOwnUserId = () => '') => ({
        message: 'Email already registered.',
        test: (value) => value === '' || isEmailFree(value, getOwnUserId()),
    }),
};

// A check that gets no answer by then lets the email through
const EMAIL_CHECK_TIMEOUT = 8000;
const emailFreeAnswers = new Map();

async function isEmailFree(email, ownUserId) {
    const query = new URLSearchParams({email, ignore_id: ownUserId});
    if (!emailFreeAnswers.has(query.toString())) {
        const answer = fetch(`/account/validate-email?${query}`, {signal: AbortSignal.timeout(EMAIL_CHECK_TIMEOUT)})
            .then((response) => response.json())
            // The server decides again on submit, so a failed check lets it through
            .catch(() => true);
        emailFreeAnswers.set(query.toString(), answer);
    }
    return emailFreeAnswers.get(query.toString());
}

function setUpFormChecks(form, checksByFieldName) {
    const checkedFieldNames = new Set();
    let haveChecksPassed = false;

    const getCheckedFields = () => Object.keys(checksByFieldName)
        .map((name) => form.elements[name])
        .filter((field) => field && isShown(field));

    async function getFirstProblem(field) {
        for (const check of checksByFieldName[field.name]) {
            // One at a time: a later check (asking the server) may not be needed
            if (!(await check.test(field.value, field))) return check.message;
        }
        return null;
    }

    // Beer CSS shows the field's error output in place of its hint while the
    // field box is .invalid (see _form_fields.html)
    function showProblem(field, problem) {
        const hasProblem = problem !== null;
        const fieldBox = field.closest('.field');
        const errorOutput = fieldBox.querySelector('output.invalid');

        fieldBox.classList.toggle('invalid', hasProblem);
        errorOutput.textContent = problem ?? '';
        if (hasProblem) {
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', errorOutput.id);
        } else {
            field.removeAttribute('aria-invalid');
            field.removeAttribute('aria-describedby');
        }
    }

    async function checkField(field) {
        checkedFieldNames.add(field.name);
        const problem = await getFirstProblem(field);
        showProblem(field, problem);
        return problem === null;
    }

    function isFieldWithChecks(element) {
        return element.name in checksByFieldName && isShown(element);
    }

    form.addEventListener('focusout', (event) => {
        const field = event.target;
        if (!isFieldWithChecks(field)) return;
        // Tabbing past an empty field you haven't typed in isn't a mistake yet
        if (field.value === '' && !checkedFieldNames.has(field.name)) return;
        checkField(field);
    });

    form.addEventListener('input', (event) => {
        const field = event.target;
        if (isFieldWithChecks(field) && checkedFieldNames.has(field.name)) {
            checkField(field);
        }
    });

    // The checks can ask the server, so the first submit is held back; once
    // everything passes, the form is sent again and this lets it through
    form.addEventListener('submit', async (event) => {
        if (haveChecksPassed) return;
        event.preventDefault();

        const fields = getCheckedFields();
        const results = await Promise.all(fields.map(checkField));
        const firstInvalidField = fields[results.indexOf(false)];

        if (firstInvalidField) {
            firstInvalidField.focus();
            return;
        }
        haveChecksPassed = true;
        form.requestSubmit(event.submitter);
    });

    return {
        // Takes away the problems shown in part of the form, e.g. a card being cancelled
        clearProblems(container) {
            container.querySelectorAll('.field.invalid input').forEach((field) => showProblem(field, null));
        },
    };
}
