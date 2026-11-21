<?php

namespace App\Controllers;

use App\AvatarPicture;
use App\Config;
use App\Flash;
use App\Models\User;
use App\Models\Whereabouts;
use App\SignIn\Auth;
use Core\View;

/**
 * The profile page: name, nickname, photo, email and password, whether
 * you're on the map, and deleting the profile
 */
class Profile extends Authenticated
{
    /** The logged-in user, set before each action */
    protected User $user;

    /**
     * Require a login, and keep who it is
     */
    protected function before(): ?bool
    {
        parent::before();

        $this->user = Auth::getUser();

        return null;
    }

    /**
     * Show the profile
     */
    public function showAction(): void
    {
        View::renderTemplate('Profile/show.html', [
            'user' => $this->user,
            'place' => Whereabouts::getPlace($this->user->email),
            'default_avatar' => AvatarPicture::src($this->user->id, $this->user->name),
            'are_photo_uploads_on' => Config::arePhotoUploadsOn(),
            'cloudinary_cloud_name' => Config::cloudinaryCloudName(),
            'cloudinary_upload_preset' => Config::cloudinaryUploadPreset(),
            'guest_time_left' => $this->user->is_guest ? $this->guestTimeLeft() : '',
            'shown_password' => $this->user->hasKnownPassword() ? '••••••••' : 'Not set',
            'password_field' => $this->passwordField(),
            'asks_for_current_password' => !$this->user->is_guest && $this->user->hasPassword(),
        ]);
    }

    /**
     * How long until the guest account is deleted, in words: "1 hour", "5 hours"
     */
    protected function guestTimeLeft(): string
    {
        $hoursLeft = $this->user->guestHoursLeft();

        return $hoursLeft === 1 ? '1 hour' : "$hoursLeft hours";
    }

    /**
     * The label and hint of the Account card's password field, which
     * depend on what the account has now
     *
     * @return array{label: string, hint: string}
     */
    protected function passwordField(): array
    {
        if ($this->user->is_guest) {
            return ['label' => 'Password', 'hint' => 'At least 6 characters, with a letter and a number, to keep your account.'];
        }
        // Made by signing in with Google, so there's no current password to ask for
        if (!$this->user->hasPassword()) {
            return ['label' => 'Password', 'hint' => 'Set one to log in with your email too.'];
        }

        return ['label' => 'New password', 'hint' => 'Leave blank to keep your current password.'];
    }

    /**
     * Save the profile form
     */
    public function updateAction(): void
    {
        $email = trim($this->stringFrom($_POST, 'email'));
        $password = $this->stringFrom($_POST, 'password');

        if ($this->user->is_guest) {
            // A guest's email field starts empty; leaving it empty keeps the placeholder
            // email, but keeping the account (setting a password) needs a real one
            if ($email === '') {
                if ($password !== '') {
                    $this->refuse('Enter your email to keep your account.', '/profile/show');
                }
                $email = $this->user->email;
            }
        } elseif ($this->user->hasPassword() && ($email !== $this->user->email || $password !== '')) {
            // Changing how you sign in needs your current password. An
            // account made with Google has none to give, and Google vouched for it
            if (!password_verify($this->stringFrom($_POST, 'current_password'), $this->user->password_hash)) {
                $this->refuse('Your current password is not right.', '/profile/show');
            }
        }

        // Guests stay "Guest" and a number, so they don't get a nickname
        $nickname = $this->user->is_guest ? '' : $this->stringFrom($_POST, 'nickname');

        $data = ['name' => $this->stringFrom($_POST, 'name'), 'nickname' => $nickname, 'email' => $email, 'password' => $password];

        if ($this->user->updateProfile($data)) {
            // A new password ends every session made before it, but not this one
            Auth::stayLoggedIn($this->user);

            Flash::clear();
            Flash::addMessage($this->sendNewEmailLink());
        } else {
            // The cards show their saved details, not the form, so the problems go in one message
            Flash::addMessage(implode(' ', array_merge(...array_values($this->user->errors))), Flash::WARNING);
        }

        $this->redirect('/profile/show');
    }

    /**
     * Email a new email the link that proves it's theirs, and give the
     * message that says the profile is saved
     */
    protected function sendNewEmailLink(): string
    {
        $hasNewEmail = $this->user->activationToken !== null;
        if (!$hasNewEmail || !Config::isMailEnabled()) {
            return 'Changes saved.';
        }

        if (!$this->user->sendNewEmailConfirmation()) {
            return "Changes saved. We couldn't send the link that confirms your new email.";
        }

        return 'Changes saved. Check your new email for a link to confirm it.';
    }

    /**
     * Save the uploaded photo (for AJAX). Refused while the site has no
     * Cloudinary account, so no photo from anyone else's gets in
     */
    public function updateAvatarAction(): void
    {
        if (!Config::arePhotoUploadsOn() || !$this->user->updateAvatar($this->stringFrom($_POST, 'avatar'))) {
            http_response_code(400);
        }
    }

    /**
     * Go back to the drawn avatar (for AJAX)
     */
    public function removeAvatarAction(): void
    {
        if (!$this->user->updateAvatar(null)) {
            http_response_code(400);
        }
    }

    /**
     * Turn showing the user on the map on or off (for AJAX)
     */
    public function updateLocationSharingAction(): void
    {
        $isShared = !empty($_POST['share']);
        $this->user->setLocationSharing($isShared);

        $this->json(['share_location' => $isShared]);
    }

    /**
     * Save the time zone the browser reports, so others see the user's
     * local time (for AJAX). The chat socket's hello saves it too
     */
    public function updateTimezoneAction(): void
    {
        Whereabouts::saveTimezone($this->user->email, $this->stringFrom($_POST, 'timezone'));
    }

    /**
     * Delete the account and everything in it, then sign out. An account
     * with a password needs it, as changing the email does
     */
    public function deleteAction(): void
    {
        $password = $this->stringFrom($_POST, 'current_password');
        if ($this->user->hasKnownPassword() && !password_verify($password, $this->user->password_hash)) {
            $this->refuse('Your password is not right. Your profile is kept.', '/profile/show');
        }

        $this->user->delete();
        Auth::logout();

        $this->redirect('/login/show-deleted-message');
    }
}
