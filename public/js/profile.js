// The profile page. The server's values come from data attributes (see
// Profile/show.html)

$(document).ready(function () {

    var form = $('#formProfile');
    var userId = form.data('user-id');
    var isGuest = form.data('guest');

    $('#keep-account').on('click', function () {
        startEditing($('#card-account'));
        $('#inputEmail')[0].scrollIntoView({block: 'center', behavior: 'smooth'});
        $('#inputEmail').focus();
    });

    /* Privacy: saves as soon as it's switched */

    function showPlace(shared) {
        $('#profile-place').toggleClass('is-hidden', !shared);
    }
    showPlace($('#share-location').prop('checked'));

    $('#share-location').on('change', function () {
        var checkbox = this;
        postForm('/profile/update-location-sharing', {share: checkbox.checked ? 1 : 0})
            .then(function () {
                showPlace(checkbox.checked);
                M.toast({html: checkbox.checked ? 'You are shown on the map' : 'You are hidden from the map'});
            })
            .catch(function () {
                checkbox.checked = !checkbox.checked;
                M.toast({html: 'Could not save. Try again.'});
            });
    });

    /* Photo: saves as soon as it's uploaded or removed */

    var avatar = $('#avatar');
    var upload = $('#file-upload');
    var CLOUDINARY_URL = 'https://api.cloudinary.com/v1_1/' + upload.data('cloud-name') + '/image/upload';
    var CLOUDINARY_UPLOAD_PRESET = upload.data('upload-preset');

    // An uploaded photo fades in over a soft circle once loaded
    function showPhoto(src) {
        avatar.attr('src', src);
        if (avatar[0].complete || src.indexOf('data:') === 0) return;

        $('#photo-frame').addClass('is-loading');
        avatar.one('load error', function () {
            $('#photo-frame').removeClass('is-loading');
        });
    }
    showPhoto(avatar.attr('src'));

    // Upload the photo, then save it; on any failure the old one stays
    upload.on('change', function () {
        var file = this.files[0];
        if (!file) return;

        $('#photo-frame').addClass('is-uploading');

        var formData = new FormData();
        formData.append('file', file);
        formData.append('upload_preset', CLOUDINARY_UPLOAD_PRESET);

        axios.post(CLOUDINARY_URL, formData)
            .then(function (res) {
                var url = res.data.secure_url;
                return postForm('/profile/update-avatar', {avatar: url}).then(function () {
                    showPhoto(url);
                    $('#remove-photo').prop('hidden', false);
                    M.toast({html: 'Photo updated'});
                });
            })
            .catch(function () {
                M.toast({html: 'Your photo could not be saved. Try again.'});
            })
            .finally(function () {
                $('#photo-frame').removeClass('is-uploading');
            });

        this.value = '';
    });

    $('#remove-photo').on('click', function () {
        postForm('/profile/remove-avatar')
            .then(function () {
                showPhoto(avatar.data('default-src'));
                $('#remove-photo').prop('hidden', true);
                M.toast({html: 'Photo removed'});
            })
            .catch(function () {
                M.toast({html: 'Your photo could not be removed. Try again.'});
            });
    });

    /* Name, email and password: each card opens with Edit and saves with Save */

    var fields = $('#inputName, #inputEmail, #inputPassword');
    fields.each(function () {
        $(this).data('initial', this.value);
    });

    function changed(field) {
        return field.value !== $(field).data('initial');
    }

    function signInChanged() {
        return changed($('#inputEmail')[0]) || $('#inputPassword').val() !== '';
    }

    function startEditing(card) {
        card.addClass('is-editing');
        M.updateTextFields();
    }

    // Back to the saved values and out of edit mode
    function stopEditing(card) {
        card.find('input').each(function () {
            this.value = $(this).data('initial') || '';
        });
        card.find('label.error').remove();
        card.find('input.error').removeClass('error');
        card.removeClass('is-editing');
        $('#current-password').prop('hidden', !signInChanged());
        M.updateTextFields();
    }

    $('.settings-card__edit-button').on('click', function () {
        var card = $(this).closest('.settings-card');
        startEditing(card);
        card.find('.settings-card__edit input').first().focus();
    });

    $('.settings-card__cancel').on('click', function () {
        stopEditing($(this).closest('.settings-card'));
    });

    // The current password is asked for only when the email or password changes
    $('#inputEmail, #inputPassword').on('input', function () {
        $('#current-password').prop('hidden', !signInChanged());
    });

    form.validate({
        rules: {
            name: {
                required: true,
                validName: true
            },
            email: {
                // A guest only needs an email when setting a password
                required: function () {
                    return !isGuest || $('#inputPassword').val() !== '';
                },
                email: true,
                remote: {
                    url: '/account/validate-email',
                    data: {
                        ignore_id: function () {
                            return userId;
                        }
                    }
                }
            },
            password: {
                minlength: 6,
                validPassword: true
            },
            current_password: {
                required: signInChanged
            }
        },
        messages: {
            email: {
                required: isGuest ? 'Enter your email to keep your account.' : "Can't be blank.",
                remote: 'Email already registered.'
            },
            current_password: {
                required: 'Enter your current password to make this change.'
            }
        }
    });

    var saving = false;

    form.on('submit', function () {
        if ($(this).valid()) {
            saving = true;
            $(this).find('button[type=submit]').prop('disabled', true).text('Saving…');
        }
    });

    // Warn before leaving with unsaved changes
    window.addEventListener('beforeunload', function (e) {
        if (!saving && fields.toArray().some(changed)) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});
