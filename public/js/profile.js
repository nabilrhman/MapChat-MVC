// The profile page. The server's values come from data attributes (see
// Profile/show.html)

const profileForm = document.getElementById('formProfile');
const ownUserId = profileForm.dataset.userId;
const isGuest = profileForm.dataset.guest === 'true';

const nameInput = document.getElementById('inputName');
const emailInput = document.getElementById('inputEmail');
const passwordInput = document.getElementById('inputPassword');
const currentPasswordField = document.getElementById('current-password');

/* Privacy: saves as soon as it's switched */

const shareLocationSwitch = document.getElementById('share-location');

function showPlaceNote(isLocationShared) {
    document.getElementById('profile-place')?.classList.toggle('is-hidden', !isLocationShared);
}
showPlaceNote(shareLocationSwitch.checked);

shareLocationSwitch.addEventListener('change', () => {
    const isLocationShared = shareLocationSwitch.checked;
    postForm('/profile/update-location-sharing', {share: isLocationShared ? 1 : 0})
        .then(() => {
            showPlaceNote(isLocationShared);
            showToast(isLocationShared ? 'You are shown on the map' : 'You are hidden from the map', 'info');
        })
        .catch(() => {
            shareLocationSwitch.checked = !isLocationShared;
            showToast('Could not save. Try again.', 'warning');
        });
});

/* Photo: saves as soon as it's uploaded or removed */

const avatar = document.getElementById('avatar');
const photoFrame = document.getElementById('photo-frame');
const photoUpload = document.getElementById('file-upload');
const removePhotoButton = document.getElementById('remove-photo');
const CLOUDINARY_URL = `https://api.cloudinary.com/v1_1/${photoUpload.dataset.cloudName}/image/upload`;
const CLOUDINARY_UPLOAD_PRESET = photoUpload.dataset.uploadPreset;

// An uploaded photo fades in over a soft circle once loaded
function showPhoto(src) {
    avatar.src = src;
    if (avatar.complete || src.startsWith('data:')) return;

    photoFrame.classList.add('is-loading');
    const onPhotoSettled = () => photoFrame.classList.remove('is-loading');
    avatar.addEventListener('load', onPhotoSettled, {once: true});
    avatar.addEventListener('error', onPhotoSettled, {once: true});
}
showPhoto(avatar.src);

async function uploadPhoto(file) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('upload_preset', CLOUDINARY_UPLOAD_PRESET);

    const response = await axios.post(CLOUDINARY_URL, formData);
    return response.data.secure_url;
}

// Upload the photo, then save it; on any failure the old one stays
photoUpload.addEventListener('change', async () => {
    const file = photoUpload.files[0];
    if (!file) return;
    photoUpload.value = '';
    photoFrame.classList.add('is-uploading');

    try {
        const photoUrl = await uploadPhoto(file);
        await postForm('/profile/update-avatar', {avatar: photoUrl});
        showPhoto(photoUrl);
        removePhotoButton.hidden = false;
        showToast('Photo updated');
    } catch {
        showToast('Your photo could not be saved. Try again.', 'warning');
    } finally {
        photoFrame.classList.remove('is-uploading');
    }
});

removePhotoButton.addEventListener('click', () => {
    postForm('/profile/remove-avatar')
        .then(() => {
            showPhoto(avatar.dataset.defaultSrc);
            removePhotoButton.hidden = true;
            showToast('Photo removed');
        })
        .catch(() => {
            showToast('Your photo could not be removed. Try again.', 'warning');
        });
});

/* Name, email and password: each card opens with Edit and saves with Save */

const editableFields = [nameInput, emailInput, passwordInput];
const savedValues = new Map(editableFields.map((field) => [field, field.value]));

const hasChanged = (field) => field.value !== savedValues.get(field);
const isSignInChanging = () => hasChanged(emailInput) || passwordInput.value !== '';

// The current password is asked for only when the email or password changes
function showCurrentPasswordIfNeeded() {
    if (currentPasswordField) currentPasswordField.hidden = !isSignInChanging();
}

const formChecks = setUpFormChecks(profileForm, {
    name: [FIELD_CHECKS.required()],
    email: [
        {
            // A guest only needs an email when setting a password
            message: isGuest ? 'Enter your email to keep your account.' : "Can't be blank.",
            test: (value) => (isGuest && passwordInput.value === '') || value.trim() !== '',
        },
        FIELD_CHECKS.email,
        FIELD_CHECKS.emailIsFree(() => ownUserId),
    ],
    password: [FIELD_CHECKS.minLength(6), FIELD_CHECKS.letterAndNumber],
    current_password: [FIELD_CHECKS.required('Enter your current password to make this change.')],
});

function startEditing(card) {
    card.classList.add('is-editing');
}

// Back to the saved values and out of edit mode
function stopEditing(card) {
    card.querySelectorAll('input').forEach((input) => {
        input.value = savedValues.get(input) ?? '';
    });
    formChecks.clearProblems(card);
    card.classList.remove('is-editing');
    showCurrentPasswordIfNeeded();
}

document.querySelectorAll('.settings-card__edit-button').forEach((editButton) => {
    editButton.addEventListener('click', () => {
        const card = editButton.closest('.settings-card');
        startEditing(card);
        card.querySelector('.settings-card__edit input').focus();
    });
});

document.querySelectorAll('.settings-card__cancel').forEach((cancelButton) => {
    cancelButton.addEventListener('click', () => stopEditing(cancelButton.closest('.settings-card')));
});

document.getElementById('keep-account')?.addEventListener('click', () => {
    startEditing(document.getElementById('card-account'));
    emailInput.scrollIntoView({block: 'center', behavior: 'smooth'});
    emailInput.focus();
});

emailInput.addEventListener('input', showCurrentPasswordIfNeeded);
passwordInput.addEventListener('input', showCurrentPasswordIfNeeded);

// Runs after the checks, which hold back a submit until everything passes
let isSaving = false;

profileForm.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    isSaving = true;
    profileForm.querySelectorAll('button[type=submit]').forEach((saveButton) => {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
    });
});

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', (event) => {
    if (!isSaving && editableFields.some(hasChanged)) {
        event.preventDefault();
    }
});
