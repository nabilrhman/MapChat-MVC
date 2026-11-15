<?php

namespace App\Controllers;

use App\Auth;
use \Core\View;
use \App\Models\Chat;
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
        $chat = new Chat($_POST);
        $chats = $chat->getall();


        View::renderTemplate('App/index.html', [
            'chats' => $chats
        ]);

        Auth::setLastChatId($chat->getLastChatId());
    }

    /**
     * Gets new chats (for AJAX)
     *
     * @return void
     */
    public function getAction()
    {

        if(Auth::hasLastChatId())
        {

                $_POST['last_chat_id'] = Auth::getLastChatId();
                $chat = new Chat($_POST);

                if ($chat->hasChatAfter())
                {
                    $chats = $chat->getAfter(Auth::getLastChatId());

                    View::renderTemplate('App/chat.html', [
                        'chats' => $chats
                    ]);

                    Auth::setLastChatId($chat->getLastChatId());
                }

        }
        else
        {
            Auth::setLastChatId(0);

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

        $chat = new Chat(['email' => $user->email, 'body' => $body]);
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
     * Gets new chats (for AJAX)
     *
     * @return void
     */
    public function updatelocationAction()
    {
        // Only ever update the signed-in user's own location, from their real IP
        $userInfo = new UserInfo(['email' => Auth::getEmail(), 'ip' => UserInfo::getRealIP()]);
        if ($userInfo->hasIPUpdated())
        {
            $userInfo->saveIP();
            $userInfo->findLocationFromIP();
            $userInfo->saveLocation();
        }
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
