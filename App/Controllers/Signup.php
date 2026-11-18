<?php

namespace App\Controllers;

use App\Config;
use App\Flash;
use App\Models\User;
use Core\View;

/**
 * Signing up, and activating the account from the emailed link
 */
class Signup extends \Core\Controller
{
    /**
     * Show the signup page
     */
    public function newAction(): void
    {
        View::renderTemplate('Signup/new.html');
    }

    /**
     * Sign up a new user, or show the form again with what to fix
     */
    public function createAction(): void
    {
        // Only the form's fields
        $user = new User([
            'name' => (string) ($_POST['name'] ?? ''),
            'email' => (string) ($_POST['email'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
        ]);

        if ($user->save()) {
            $this->sendActivation($user);
        }

        View::renderTemplate('Signup/new.html', ['user' => $user]);
    }

    /**
     * Email a new account's activation link and say so, or, without email,
     * go straight on: save() made the account active. If the email can't go
     * out the account could never be activated, so it's removed and the
     * form says to try again
     */
    protected function sendActivation(User $user): void
    {
        if (!Config::isMailEnabled()) {
            $this->redirect('/signup/activated');
        }

        if ($user->sendActivationEmail()) {
            $this->redirect('/signup/success');
        }

        $user->delete();
        $user->errors['email'][] = "We couldn't send the activation email. Please try again.";
    }

    /**
     * Say the account was made, on the login page
     */
    public function successAction(): void
    {
        Flash::addMessage('Account created. Check your email to activate it.', Flash::SUCCESS);
        $this->redirect('/login');
    }

    /**
     * Activate a new account from the emailed link
     */
    public function activateAction(): void
    {
        if (!User::activate($this->routeParams['token'])) {
            Flash::addMessage('That activation link is wrong or was already used. Try logging in.', Flash::WARNING);
            $this->redirect('/login');
        }

        $this->redirect('/signup/activated');
    }

    /**
     * Say the account is active, on the login page
     */
    public function activatedAction(): void
    {
        Flash::addMessage('Your account is active. Log in to start chatting.', Flash::SUCCESS);
        $this->redirect('/login');
    }
}
