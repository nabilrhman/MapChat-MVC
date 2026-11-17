<?php

namespace App\Controllers;

use App\Auth;
use App\Flash;
use App\Google as GoogleSignIn;
use App\Models\User;
use App\Models\UserInfo;

/**
 * Signing in with Google: off to Google, and back with who it says you are.
 * The first time makes the account, so there's no separate sign-up
 */
class Google extends \Core\Controller
{
    /**
     * Send the browser to Google to choose an account. A link, not a form:
     * the page's form-action policy would block a form's redirect to Google
     */
    public function startAction(): void
    {
        if (!GoogleSignIn::isEnabled()) {
            $this->redirect('/login');
        }

        // Kept for the callback, which must bring the same state back
        $signIn = [
            'state' => bin2hex(random_bytes(16)),
            'nonce' => bin2hex(random_bytes(16)),
            'code_verifier' => bin2hex(random_bytes(32)),
        ];
        $_SESSION['google_sign_in'] = $signIn;

        header('Location: ' . GoogleSignIn::authorizeUrl($signIn['state'], $signIn['nonce'], $signIn['code_verifier']), true, 303);
        exit;
    }

    /**
     * Log in as whoever Google says came back, making their account the
     * first time
     */
    public function callbackAction(): void
    {
        // Each sign-in's state is used once, so a callback can't be replayed
        $signIn = $_SESSION['google_sign_in'] ?? null;
        unset($_SESSION['google_sign_in']);

        // Someone who pressed Cancel at Google comes back with an error
        if (isset($_GET['error'])) {
            $this->refuse('Google sign-in was cancelled.', '/login');
        }

        $isThisBrowsersSignIn = is_array($signIn) && hash_equals($signIn['state'], (string) ($_GET['state'] ?? ''));
        if (!$isThisBrowsersSignIn) {
            $this->refuse("That Google sign-in didn't come from this browser. Please try again.", '/login');
        }

        $google = GoogleSignIn::fetchAccount((string) ($_GET['code'] ?? ''), $signIn['code_verifier'], $signIn['nonce']);
        if (!$google) {
            $this->refuse("We couldn't sign you in with Google. Please try again.", '/login');
        }

        $user = User::signInWithGoogle($google);

        // Google keeps people signed in, so MapChat does too
        Auth::login($user, isRemembered: true);
        Flash::addMessage('Logged in.');
        UserInfo::locate($user->email, UserInfo::getRealIP());

        $this->redirect(Auth::getReturnToPage());
    }
}
