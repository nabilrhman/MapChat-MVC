<?php

namespace App\Controllers;

use Core\View;
use App\Auth;
use App\Flash;
use App\Models\UserInfo;

/**
 * Profile controller
 */
class Profile extends Authenticated
{
    /**
     * The logged-in user
     * @var \App\Models\User
     */
    protected $user;

    /**
     * Before filter - called before each action method
     *
     * @return void
     */
    protected function before()
    {
        parent::before();

        $this->user = Auth::getUser();
    }

    /**
     * Show the profile
     *
     * @return void
     */
    public function showAction()
    {
        View::renderTemplate('Profile/show.html', [
            'user' => $this->user,
            'place' => UserInfo::getPlace($this->user->email),
            'default_avatar' => \App\Avatar::src($this->user->id, $this->user->name)
        ]);
    }

    /**
     * Update the profile
     *
     * @return void
     */
    public function updateAction()
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

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
            if (!password_verify($_POST['current_password'] ?? '', $this->user->password_hash)) {
                $this->refuse('Your current password is not right.');
            }
        }

        // Guests stay "Guest" and a number, so they don't get a nickname
        $nickname = $this->user->is_guest ? '' : ($_POST['nickname'] ?? '');

        $data = ['name' => $_POST['name'] ?? '', 'nickname' => $nickname, 'email' => $email, 'password' => $password];

        if ($this->user->updateProfile($data)) {
            Flash::clear();
            Flash::addMessage('Changes saved');
        } else {
            Flash::addMessage(implode('. ', $this->user->errors), Flash::WARNING);
        }

        $this->redirect('/profile/show');
    }

    /**
     * Update the avatar
     *
     * @return void
     */
    public function updateAvatarAction()
    {
        if (!$this->user->updateAvatar($_POST['avatar'] ?? '')) {
            http_response_code(400);
        }
    }

    /**
     * Go back to the default letter avatar (AJAX)
     *
     * @return void
     */
    public function removeAvatarAction()
    {
        if (!$this->user->updateAvatar(null)) {
            http_response_code(400);
        }
    }

    /**
     * Show why a change wasn't saved, back on the profile page
     *
     * @param string $message
     *
     * @return void
     */
    protected function refuse($message)
    {
        Flash::addMessage($message, Flash::WARNING);
        $this->redirect('/profile/show');
    }

    /**
     * Turn showing the user on the map on or off (AJAX)
     *
     * @return void
     */
    public function updateLocationSharingAction()
    {
        $this->user->setLocationSharing(!empty($_POST['share']));

        $this->json(['share_location' => !empty($_POST['share'])]);
    }
}
