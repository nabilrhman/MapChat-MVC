<?php

namespace App\Controllers;

use Core\View;
use App\Models\User;
use App\Flash;

/**
 * Password controller
 */
class Password extends \Core\Controller
{
    /**
     * Show the forgotten password page
     *
     * @return void
     */
    public function forgotAction()
    {
        View::renderTemplate('Password/forgot.html');
    }

    /**
     * Send the password reset link to the supplied email
     *
     * @return void
     */
    public function requestResetAction()
    {
        User::sendPasswordReset($_POST['email'] ?? '');

        Flash::addMessage('Check your email for a reset link', Flash::INFO);
        $this->redirect('/login/new');
    }

    /**
     * Show the reset password form
     *
     * @return void
     */
    public function resetAction()
    {
        $token = $this->route_params['token'];

        $this->getUserOrExit($token);

        View::renderTemplate('Password/reset.html', [
            'token' => $token
        ]);
    }

    /**
     * Reset the user's password
     *
     * @return void
     */
    public function resetPasswordAction()
    {
        $token = $_POST['token'] ?? '';

        $user = $this->getUserOrExit($token);

        if ($user->resetPassword($_POST['password'] ?? '')) {
            Flash::addMessage('Password changed. Log in with your new password.', Flash::SUCCESS);
            $this->redirect('/login/new');
        } else {
            View::renderTemplate('Password/reset.html', [
                'token' => $token,
                'user' => $user
            ]);
        }
    }

    /**
     * Find the user model associated with the password reset token, or end the request with a message
     *
     * @param string $token Password reset token sent to user
     *
     * @return User The user, if the token is valid and hasn't expired
     */
    protected function getUserOrExit($token)
    {
        $user = User::findByPasswordReset($token);

        if (!$user) {
            Flash::addMessage('Password reset link is invalid or expired. Try again.', Flash::WARNING);
            $this->redirect('/password/forgot');
        }

        return $user;
    }
}
