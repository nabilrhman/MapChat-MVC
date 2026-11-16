<?php

namespace App\Controllers;

use Core\View;
use App\Auth;
use App\Avatar;
use App\Config;
use App\Flash;
use App\Models\User;
use App\Models\UserInfo;

/**
 * The profile page: name, nickname, photo, email and password, and whether
 * you're on the map
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
            'place' => UserInfo::getPlace($this->user->email),
            'default_avatar' => Avatar::src($this->user->id, $this->user->name),
            'are_photo_uploads_on' => Config::arePhotoUploadsOn(),
        ]);
    }

    /**
     * Save the profile form
     */
    public function updateAction(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($this->user->is_guest) {
            // A guest's email field starts empty; leaving it empty keeps the placeholder
            // email, but keeping the account (setting a password) needs a real one
            if ($email === '') {
                if ($password !== '') {
                    $this->refuse('Enter your email to keep your account.');
                }
                $email = $this->user->email;
            }
        } elseif ($email !== $this->user->email || $password !== '') {
            // Changing how you sign in needs your current password
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $this->user->password_hash)) {
                $this->refuse('Your current password is not right.');
            }
        }

        // Guests stay "Guest" and a number, so they don't get a nickname
        $nickname = $this->user->is_guest ? '' : (string) ($_POST['nickname'] ?? '');

        $data = ['name' => (string) ($_POST['name'] ?? ''), 'nickname' => $nickname, 'email' => $email, 'password' => $password];

        if ($this->user->updateProfile($data)) {
            Flash::clear();
            Flash::addMessage('Changes saved.');
        } else {
            // The cards show their saved details, not the form, so the problems go in one message
            Flash::addMessage(implode(' ', array_merge(...array_values($this->user->errors))), Flash::WARNING);
        }

        $this->redirect('/profile/show');
    }

    /**
     * Save the uploaded photo (for AJAX). Refused while the site has no
     * Cloudinary account, so no photo from anyone else's gets in
     */
    public function updateAvatarAction(): void
    {
        if (!Config::arePhotoUploadsOn() || !$this->user->updateAvatar((string) ($_POST['avatar'] ?? ''))) {
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
     * Show why a change wasn't saved, back on the profile page
     *
     * @param string $message A full sentence
     */
    protected function refuse(string $message): never
    {
        Flash::addMessage($message, Flash::WARNING);
        $this->redirect('/profile/show');
    }
}
