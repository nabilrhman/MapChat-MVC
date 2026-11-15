<?php

namespace App\Controllers;

use App\Auth;
use App\Config;
use Core\View;
use App\Models\Bot;
use App\Models\Chat;
use App\Models\DemoChat;
use App\Models\User;
use App\Models\UserInfo;

/**
 * The chat page and the AJAX calls it makes
 */
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
        $this->catchUpDemoChat();
        Bot::arrived(Auth::getEmail());

        $chats = Chat::getRecent(Auth::getEmail());
        $lastChatId = $chats ? end($chats)['id'] : 0;

        View::renderTemplate('App/index.html', [
            'chats' => $chats,
            'last_chat_id' => $lastChatId,
            'conversations' => Chat::getConversations(Auth::getEmail(), $lastChatId),
            'build_id' => Config::buildId()
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
        // The page reloads when this changes, so it never mixes old and new code
        header('X-Build: ' . Config::buildId());

        UserInfo::markOnline(Auth::getEmail());

        // A page from before this change doesn't send it; don't flood it with old messages
        if (!isset($_POST['after'])) {
            return;
        }

        $this->catchUpDemoChat();
        Bot::runDue();

        $chats = Chat::getAfter(Auth::getEmail(), (int) $_POST['after']);

        if ($chats) {
            View::renderTemplate('App/chat.html', [
                'chats' => $chats
            ]);
        }
    }

    /**
     * Gets the page of chats before the given id, when the reader scrolls up
     * past the oldest one shown (for AJAX)
     *
     * @return void
     */
    public function getOlderAction()
    {
        $chats = Chat::getBefore(Auth::getEmail(), (int) ($_POST['before'] ?? 0));

        if ($chats) {
            // Older messages appear at once, without the new-message ease-in
            View::renderTemplate('App/chat.html', [
                'chats' => $chats,
                'initial' => true
            ]);
        }
    }

    /**
     * Mark the private chat with a person as read up to a message id, once
     * the viewer has it open (for AJAX)
     *
     * @return void
     */
    public function markReadAction()
    {
        Chat::markRead(Auth::getEmail(), (int) ($_POST['from'] ?? 0), (int) ($_POST['up_to'] ?? 0));
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

        // A private message goes to one other person, never to yourself, and
        // to MapChat only while it can write back
        $toEmail = null;
        if (!empty($_POST['to'])) {
            $recipient = User::findByID($_POST['to']);

            if (!$recipient || $recipient->id == $user->id || ($recipient->email === Chat::BOT_EMAIL && !Bot::canReply())) {
                $this->refuse(400, 'You can\'t message this person.');
                return;
            }

            $toEmail = $recipient->email;
        }

        $chat = new Chat(['email' => $user->email, 'to_email' => $toEmail, 'body' => $body]);
        if ($chat->send()) {
            Bot::heard($user->email, (int) Chat::lastId(), $toEmail);
        }
    }

    /**
     * Let the demo people post what's due. The chat polls every second, but
     * once a minute per visitor is plenty for a slow conversation
     *
     * @return void
     */
    protected function catchUpDemoChat()
    {
        if (DemoChat::isFast() || time() - ($_SESSION['demo_chat_at'] ?? 0) >= 60) {
            $_SESSION['demo_chat_at'] = time();
            DemoChat::catchUp();
        }
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
        $this->json(['error' => $message], $status);
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
        if ($userInfo->hasIPUpdated() || $userInfo->isMissingLocation()) {
            UserInfo::locate(Auth::getEmail(), UserInfo::getRealIP());
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

        $this->json(UserInfo::getRecentPeople());
    }

    /**
     * Everyone's pin for the map (for AJAX)
     *
     * @return void
     */
    public function getLocationsAction()
    {
        $this->json(UserInfo::getAllLocations());
    }
}
