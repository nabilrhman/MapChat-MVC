<?php

namespace App\Controllers;

use App\Auth;
use \Core\View;
use \App\Models\Chat;
use \App\Models\User;
use \App\Models\UserInfo;


/**
 * Items controller (example)
 *
 * PHP version 7.0
 */
//class Items extends \Core\Controller
class App extends Authenticated
{

    /**
     * App index
     *
     * @return void
     */
    public function indexAction()
    {
        $this->refreshLocation();
        UserInfo::markOnline(Auth::getEmail());

        $chats = Chat::getRecent(Auth::getEmail());

        View::renderTemplate('App/index.html', [
            'chats' => $chats,
            'last_chat_id' => $chats ? end($chats)['id'] : 0
        ]);
    }

    /**
     * Gets new chats (for AJAX). Each page says which message it saw last, so
     * two open tabs don't take each other's messages
     *
     * @return void
     */
    public function getAction()
    {
        UserInfo::markOnline(Auth::getEmail());

        // A page from before this change doesn't send it; don't flood it with old messages
        if (!isset($_POST['after']))
        {
            return;
        }

        $chats = Chat::getAfter(Auth::getEmail(), (int) $_POST['after']);

        if ($chats)
        {
            View::renderTemplate('App/chat.html', [
                'chats' => $chats
            ]);
        }
    }

    /**
     * Send the typed chat (for AJAX)
     *
     * @return void
     */
    public function sendAction()
    {
        $user = Auth::getUser();
        $body = trim($_POST['body'] ?? '');

        if ($body === '') {
            return;
        }

        if (mb_strlen($body) > Chat::MAX_LENGTH) {
            $this->refuse(400, 'Messages can be up to ' . Chat::MAX_LENGTH . ' characters.');
            return;
        }

        // Keep one person from flooding the room; guests get a lower limit
        $limit = $user->is_guest ? Chat::GUEST_PER_MINUTE : Chat::USER_PER_MINUTE;
        if (Chat::countRecent($user->email, 60) >= $limit) {
            $this->refuse(429, 'You are sending messages too fast. Wait a moment and try again.');
            return;
        }

        // A private message goes to one other person, never to yourself or the bot
        $toEmail = null;
        if (!empty($_POST['to'])) {
            $recipient = User::findByID($_POST['to']);

            if (!$recipient || $recipient->id == $user->id || $recipient->email === Chat::BOT_EMAIL) {
                $this->refuse(400, 'You can\'t message this person.');
                return;
            }

            $toEmail = $recipient->email;
        }

        $chat = new Chat(['email' => $user->email, 'to_email' => $toEmail, 'body' => $body]);
        $chat->send();
    }

    /**
     * Reply to an AJAX request with an error message
     *
     * @param int $status HTTP status
     * @param string $message Shown to the user
     *
     * @return void
     */
    protected function refuse($status, $message)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
    }

    /**
     * Look the signed-in user's location up again if their IP changed since the
     * last lookup, or if they were located before countries were saved
     *
     * @return void
     */
    protected function refreshLocation()
    {
        // Only ever update the signed-in user's own location, from their real IP
        $userInfo = new UserInfo(['email' => Auth::getEmail(), 'ip' => UserInfo::getRealIP()]);
        if ($userInfo->hasIPUpdated() || $userInfo->isMissingCountry())
        {
            $userInfo->saveIP();
            $userInfo->findLocationFromIP();
            $userInfo->saveLocation();
        }
    }

    /**
     * Who is online and who was here in the last day (for AJAX)
     *
     * @return void
     */
    public function getPeopleAction()
    {
        // The page sends its time zone along, so others can see your local time
        $timezone = $_POST['timezone'] ?? '';
        if (in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            UserInfo::saveTimezone(Auth::getEmail(), $timezone);
        }

        // So you're in the list from the first load, not after the next check
        UserInfo::markOnline(Auth::getEmail());

        header('Content-Type: application/json');
        echo json_encode(UserInfo::getRecentPeople());
    }

    public function getLocationsAction()
    {
        $userInfo = new UserInfo();
        $locations = $userInfo->getAllLocations();

        header('Content-Type: application/json');
        echo json_encode($locations);


        //print_r($userInfo->getAllLocations());
        //echo $locations[1]['lat'];

    }


}
