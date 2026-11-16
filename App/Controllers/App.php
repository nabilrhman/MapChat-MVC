<?php

namespace App\Controllers;

use App\Auth;
use App\Config;
use Core\View;
use App\Models\Bot;
use App\Models\Chat;
use App\Models\DemoChat;
use App\Models\UserInfo;

/**
 * The chat page and the AJAX calls it makes when the chat socket can't connect
 */
class App extends Authenticated
{
    /**
     * Show the chat
     */
    public function indexAction(): void
    {
        $this->refreshLocation();
        UserInfo::markOnline(Auth::getEmail());
        $this->catchUpDemoChat();
        Bot::onArrival(Auth::getEmail());

        $chats = Chat::getRecent(Auth::getEmail());
        $lastChatId = $chats ? (int) end($chats)['id'] : 0;

        View::renderTemplate('App/index.html', [
            'chats' => $chats,
            'last_chat_id' => $lastChatId,
            'conversations' => Chat::getConversations(Auth::getEmail(), $lastChatId),
            'build_id' => Config::buildId()
        ]);
    }

    /**
     * Send the new chats (for AJAX). Each page says which message it saw
     * last, so two open tabs don't take each other's messages
     */
    public function getAction(): void
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
     * Send the page of chats before the given id, when the reader scrolls up
     * past the oldest one shown (for AJAX). With a conversation, only that
     * tab's: "everyone" for the open room, or the other person's id
     */
    public function getOlderAction(): void
    {
        $conversation = isset($_POST['conversation']) ? (string) $_POST['conversation'] : null;
        $chats = Chat::getOlder(Auth::getEmail(), $conversation, (int) ($_POST['before'] ?? 0));

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
     */
    public function markReadAction(): void
    {
        Chat::markRead(Auth::getEmail(), (int) ($_POST['from'] ?? 0), (int) ($_POST['up_to'] ?? 0));
    }

    /**
     * Send the typed chat (for AJAX). The chat socket sends with the same
     * rules (Chat::sendFrom); this is for when the socket can't connect
     */
    public function sendAction(): void
    {
        // A page from before client ids sends an empty box quietly; keep that
        if (trim($_POST['body'] ?? '') === '') {
            return;
        }

        $toUserId = empty($_POST['to']) ? null : (int) $_POST['to'];
        $clientId = empty($_POST['client_id']) ? null : (string) $_POST['client_id'];

        $result = Chat::sendFrom(Auth::getUser(), (string) $_POST['body'], $toUserId, $clientId);

        if (isset($result['error'])) {
            $this->json(['error' => $result['error']], $result['status']);
            return;
        }

        $this->json(['id' => $result['id']]);
    }

    /**
     * Let the demo people post what's due. A page on the HTTP fallback
     * polls every few seconds, but once a minute per visitor is plenty for
     * a slow conversation
     */
    protected function catchUpDemoChat(): void
    {
        if (DemoChat::isCatchUpDue($_SESSION['demo_chat_at'] ?? 0)) {
            $_SESSION['demo_chat_at'] = time();
            DemoChat::catchUp();
        }
    }

    /**
     * Look the signed-in user's location up again if their IP changed since
     * the last lookup, or if they have no location yet
     */
    protected function refreshLocation(): void
    {
        // Only ever the signed-in user's own location, from their real IP
        $ip = UserInfo::getRealIP();
        if (UserInfo::needsLocating(Auth::getEmail(), $ip)) {
            UserInfo::locate(Auth::getEmail(), $ip);
        }
    }

    /**
     * Who is online and who was here in the last day (for AJAX)
     */
    public function getPeopleAction(): void
    {
        // So others can see your local time
        UserInfo::saveTimezone(Auth::getEmail(), $_POST['timezone'] ?? null);

        // So you're in the list from the first load, not after the next check
        UserInfo::markOnline(Auth::getEmail());

        $this->json(UserInfo::getRecentPeople());
    }

    /**
     * Everyone's pin for the map (for AJAX)
     */
    public function getLocationsAction(): void
    {
        $this->json(UserInfo::getAllLocations());
    }
}
