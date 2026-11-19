<?php

namespace App\Controllers;

use App\Config;
use App\Flash;
use App\Models\User;
use Core\View;

/**
 * Resetting a forgotten password by email
 */
class Password extends \Core\Controller
{
    /**
     * Show the forgotten password page
     */
    public function forgotAction(): void
    {
        View::renderTemplate('Password/forgot.html');
    }

    /**
     * Email a reset link to the account with the given email. The answer is
     * the same whether or not there's an account, unless the email couldn't
     * be sent
     */
    public function requestResetAction(): void
    {
        // Every email would fail, so it's said for every address alike
        if (!Config::isMailEnabled()) {
            Flash::addMessage("This site can't send email, so passwords can't be reset here.", Flash::WARNING);
            $this->redirect('/password/forgot');
        }

        if (!User::sendPasswordReset($this->stringFrom($_POST, 'email'))) {
            Flash::addMessage("We couldn't send the reset email. Try again in a moment.", Flash::WARNING);
            $this->redirect('/password/forgot');
        }

        Flash::addMessage('Check your email for a reset link.', Flash::INFO);
        $this->redirect('/login/new');
    }

    /**
     * Show the form for a new password
     */
    public function resetAction(): void
    {
        $token = $this->routeParams['token'];

        $this->getUserOrExit($token);

        View::renderTemplate('Password/reset.html', [
            'token' => $token
        ]);
    }

    /**
     * Save the new password
     */
    public function resetPasswordAction(): void
    {
        $token = $this->stringFrom($_POST, 'token');

        $user = $this->getUserOrExit($token);

        if ($user->resetPassword($this->stringFrom($_POST, 'password'))) {
            Flash::addMessage('Password changed. Log in with your new password.', Flash::SUCCESS);
            $this->redirect('/login/new');
        }

        View::renderTemplate('Password/reset.html', [
            'token' => $token,
            'user' => $user
        ]);
    }

    /**
     * The account a reset link is for, or else back to the forgotten
     * password page with a message
     *
     * @param string $token The token from the link
     */
    protected function getUserOrExit(string $token): User
    {
        $user = User::findByPasswordReset($token);

        if (!$user) {
            Flash::addMessage('Password reset link is invalid or expired. Try again.', Flash::WARNING);
            $this->redirect('/password/forgot');
        }

        return $user;
    }
}
