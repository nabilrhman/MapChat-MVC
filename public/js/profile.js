// The profile page. The server's values come from data attributes (see
// Profile/show.html)

const profileForm = document.getElementById('formProfile');
const ownUserId = profileForm.dataset.userId;
const isGuest = profileForm.dataset.guest === 'true';

const nameInput = document.getElementById('inputName');
const nicknameInput = document.getElementById('inputNickname');
const emailInput = document.getElementById('inputEmail');
const passwordInput = document.getElementById('inputPassword');
const currentPasswordField = document.getElementById('current-password');

/* Privacy: saves as soon as it's switched */

const shareLocationSwitch = document.getElementById('share-location');

function showPlaceNote(isLocationShared) {
    document.getElementById('profile-place')?.classList.toggle('is-hidden', !isLocationShared);
}
showPlaceNote(shareLocationSwitch.checked);

shareLocationSwitch.addEventListener('change', async () => {
    const isLocationShared = shareLocationSwitch.checked;
    try {
        await postForm('/profile/update-location-sharing', {share: isLocationShared ? 1 : 0});
    } catch {
        shareLocationSwitch.checked = !isLocationShared;
        showToast('Could not save. Try again.', 'warning');
        return;
    }
    showPlaceNote(isLocationShared);
    showToast(isLocationShared ? 'You are shown on the map' : 'You are hidden from the map', 'info');
});

/* Notifications: this browser's own setting, not saved to the account */

const notificationsSwitch = document.getElementById('notifications');
const notificationsHint = document.getElementById('notifications-hint');

// The hint under the switch: the first that applies. Blocked or
// unsupported, only the browser's site settings can change that; with Web
// Push set up, they come with MapChat closed too
const NOTIFICATIONS_HINTS = [
    {appliesNow: () => !isNotifySupported(), text: "This browser can't show notifications"},
    {appliesNow: () => Notification.permission === 'denied', text: "Blocked in your browser's site settings"},
    {appliesNow: isPushSupported, text: 'On this browser, even when MapChat is closed'},
    {appliesNow: () => true, text: 'On this browser, when MapChat is open in a tab'},
];

function showNotificationsState() {
    const isBlocked = !isNotifySupported() || Notification.permission === 'denied';
    notificationsSwitch.checked = areNotificationsOn();
    notificationsSwitch.disabled = isBlocked;
    notificationsHint.textContent = NOTIFICATIONS_HINTS.find(({appliesNow}) => appliesNow()).text;
}
showNotificationsState();
keepPushUpToDate();

// Changed in the browser's site settings while this page is open
navigator.permissions?.query({name: 'notifications'}).then((permission) => {
    permission.addEventListener('change', () => {
        showNotificationsState();
        keepPushUpToDate();
    });
}).catch(() => {});

notificationsSwitch.addEventListener('change', async () => {
    if (!notificationsSwitch.checked) {
        turnNotificationsOff();
        showToast('Notifications are off', 'info');
        return;
    }
    const isOn = await turnNotificationsOn();
    showNotificationsState();
    if (isOn) showToast('Notifications are on');
});

/* Photo: saves as soon as it's uploaded or removed */

const avatar = document.getElementById('avatar');
const photoFrame = document.getElementById('photo-frame');
// Missing when the site has no Cloudinary settings, so uploads are off
const photoUpload = document.getElementById('file-upload');
const removePhotoButton = document.getElementById('remove-photo');
// A photo takes longer than a form to send on a slow connection
const PHOTO_UPLOAD_TIMEOUT = 60000;

// An uploaded photo fades in over a soft circle once loaded
function showPhoto(src) {
    avatar.src = src;
    fadeInPhotos([avatar]);
}
showPhoto(avatar.src);

async function uploadPhoto(file) {
    const {cloudName, uploadPreset} = photoUpload.dataset;
    const formData = new FormData();
    formData.append('file', file);
    formData.append('upload_preset', uploadPreset);

    const uploadUrl = `https://api.cloudinary.com/v1_1/${cloudName}/image/upload`;
    const response = await axios.post(uploadUrl, formData, {timeout: PHOTO_UPLOAD_TIMEOUT});
    return response.data.secure_url;
}

// Upload the photo, then save it; on any failure the old one stays
photoUpload?.addEventListener('change', async () => {
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

removePhotoButton.addEventListener('click', async () => {
    try {
        await postForm('/profile/remove-avatar');
    } catch {
        showToast('Your photo could not be removed. Try again.', 'warning');
        return;
    }
    showPhoto(avatar.dataset.defaultSrc);
    removePhotoButton.hidden = true;
    showToast('Photo removed');
});

/* Name, email and password: each card opens with Edit and saves with Save */

// Guests have no nickname field
const editableFields = [nameInput, nicknameInput, emailInput, passwordInput].filter(Boolean);
const savedValues = new Map(editableFields.map((field) => [field, field.value]));

const hasChanged = (field) => field.value !== savedValues.get(field);
const isSignInChanging = () => hasChanged(emailInput) || passwordInput.value !== '';

// The current password is asked for only when the email or password changes
function showCurrentPasswordIfNeeded() {
    if (currentPasswordField) currentPasswordField.hidden = !isSignInChanging();
}

const formChecks = setUpFormChecks(profileForm, {
    name: [FIELD_CHECKS.required()],
    // As the server checks it (User::validate); whether it's taken, the server says
    nickname: [{
        message: '2 to 20 letters, numbers, _ . or -, with no spaces.',
        test: (value) => value.trim() === '' || /^[\p{L}\p{N}_.-]{2,20}$/u.test(value.trim()),
    }],
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

document.querySelectorAll('[data-part="edit"]').forEach((editButton) => {
    editButton.addEventListener('click', () => {
        const card = editButton.closest('[data-part="settings-card"]');
        startEditing(card);
        card.querySelector('[data-part="fields"] input').focus();
    });
});

document.querySelectorAll('[data-part="cancel"]').forEach((cancelButton) => {
    cancelButton.addEventListener('click', () => stopEditing(cancelButton.closest('[data-part="settings-card"]')));
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

/* Deleting the profile: the dialog asks first, and its form does the rest */

const deleteProfileDialog = document.getElementById('delete-profile-dialog');

document.getElementById('delete-profile').addEventListener('click', () => {
    deleteProfileDialog.showModal();
});

// Closed with Cancel or Escape, so a typed password doesn't wait there for the next opening
deleteProfileDialog.addEventListener('close', () => {
    deleteProfileDialog.querySelector('form').reset();
});
