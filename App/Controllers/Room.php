<?php

namespace App\Controllers;

use App\Bot\Groq;
use App\Bot\GroqUseKind;
use App\Config;
use App\Messages\Conversation;
use App\Messages\Language;
use App\Messages\MessageText;
use App\Messages\MessagesForReader;
use App\Messages\VoiceClip;
use App\Models\Bot;
use App\Models\Chat;
use App\Models\DemoChat;
use App\Models\GroqUse;
use App\Models\Whereabouts;
use App\SignIn\Auth;
use Core\View;

/**
 * The chat page and the AJAX calls it makes when the chat socket can't connect
 */
class Room extends Authenticated
{
    /**
     * Show the chat
     */
    public function indexAction(): void
    {
        $this->refreshLocation();
        Whereabouts::markOnline(Auth::getEmail());
        $this->catchUpDemoChat();
        Bot::onArrival(Auth::getEmail());

        $chats = Chat::getRecent(Auth::getEmail());
        $lastChatId = $chats ? end($chats)['id'] : 0;

        View::renderTemplate('Room/index.html', [
            'chats' => MessagesForReader::rowsOf($chats, Auth::getUser()),
            'last_chat_id' => $lastChatId,
            'conversations' => Chat::getConversations(Auth::getEmail(), $lastChatId),
            'build_id' => Config::buildId(),
            'can_transcribe' => Groq::isEnabled(),
            'can_translate' => Groq::isEnabled(),
            'languages' => Language::choices(),
            'translate_to_first' => Language::FIRST_TARGET->choice(),
        ]);
    }

    /**
     * Send the messages after the one the page saw last, so two open tabs
     * don't take each other's (for AJAX). It's the HTTP fallback's poll, so
     * it also keeps the person online
     */
    public function getNewAction(): void
    {
        // The page reloads when this changes, so it never mixes old and new code
        header('X-Build: ' . Config::buildId());

        // Without it, every message the reader may see would come at once
        $after = $this->stringFrom($_GET, 'after');
        if (!ctype_digit($after)) {
            $this->json(['error' => 'Say which message the page has last.'], 400);
            return;
        }

        Whereabouts::markOnline(Auth::getEmail());

        // Not Bot::runDue: the chat worker runs the replies every second, and a
        // reply can wait on Groq longer than a poll should be held
        $this->catchUpDemoChat();

        $chats = Chat::getAfter(Auth::getEmail(), (int) $after);

        if ($chats) {
            View::renderTemplate('Room/chat.html', [
                'chats' => MessagesForReader::rowsOf($chats, Auth::getUser()),
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
        // Without one, all the reader may see
        $conversation = Conversation::fromPageName($this->stringFrom($_GET, 'conversation'));
        $chats = Chat::getOlder(Auth::getEmail(), $conversation, (int) ($_GET['before'] ?? 0));

        if ($chats) {
            // Older messages appear at once, without the new-message ease-in
            View::renderTemplate('Room/chat.html', [
                'chats' => MessagesForReader::rowsOf($chats, Auth::getUser()),
                'initial' => true,
            ]);
        }
    }

    /**
     * Mark the private chat with a person as read up to a message id, once
     * the viewer has it open (for AJAX)
     */
    public function markReadAction(): void
    {
        $conversation = Conversation::privateFromPageName($_POST['conversation'] ?? null);
        $upTo = $this->stringFrom($_POST, 'up_to');
        if ($conversation === null || !ctype_digit($upTo)) {
            $this->json(['error' => 'Only a private chat can be marked read, up to a message.'], 400);
            return;
        }

        Chat::markRead(Auth::getEmail(), $conversation->partnerId, (int) $upTo);
    }

    /**
     * Send the typed chat (for AJAX). The chat socket sends with the same
     * rules (Chat::sendFrom); this is for when the socket can't connect
     */
    public function sendAction(): void
    {
        // A list in place of text (body[]=…) is no page's, and casting it would throw
        if (isset($_POST['body']) && !is_string($_POST['body'])) {
            $this->json(['error' => 'Your message could not be sent.'], 400);
            return;
        }
        $body = $this->stringFrom($_POST, 'body');
        $toUserId = empty($_POST['to']) ? null : (int) $_POST['to'];
        $clientId = $this->stringFrom($_POST, 'client_id') ?: null;
        $originalBody = $this->stringFrom($_POST, 'original') ?: null;

        $result = Chat::sendFrom(Auth::getUser(), new MessageText($body, $originalBody), $toUserId, $clientId);

        if (!$result->isSent()) {
            $this->json(['error' => $result->error], $result->status);
            return;
        }

        $this->json(['id' => $result->id]);
    }

    /**
     * Turn the mic button's recording into text for the message box (for
     * AJAX). The person reads it over before sending
     */
    public function transcribeAction(): void
    {
        if (!Groq::isEnabled()) {
            $this->json(['error' => 'Voice typing is off.'], 503);
            return;
        }

        // Like translating, so recording without sending can't run up Groq's limits
        if ($this->isMinuteLimitUsedUp(GroqUseKind::Transcribe)) {
            $this->json(['error' => 'You are using voice typing too fast. Wait a moment and try again.'], 429);
            return;
        }

        $upload = $_FILES['audio'] ?? null;
        $problem = VoiceClip::problemWith($upload);
        if ($problem !== null) {
            $this->json(['error' => $problem], 422);
            return;
        }

        // Counted once it goes to Groq: a refused request uses none of the limit
        if (!$this->claimGroqTurn(GroqUseKind::Transcribe)) {
            $this->json(['error' => 'You are using voice typing too fast. Wait a moment and try again.'], 429);
            return;
        }
        $text = Groq::transcribe(VoiceClip::fromUpload($upload));
        if ($text === null) {
            // voice.js hears this and has the browser listen instead
            $this->json(['error' => 'Voice typing is busy.'], 503);
            return;
        }

        $this->json(['text' => $text]);
    }

    /**
     * Translate the message box's text before it's sent (for AJAX). The
     * page then sends the translation, with what was typed as its original
     */
    public function translateAction(): void
    {
        if (!Groq::isEnabled()) {
            $this->json(['error' => 'Translation is off.'], 503);
            return;
        }

        // So translating without sending can't run up Groq's limits
        if ($this->isMinuteLimitUsedUp(GroqUseKind::Translate)) {
            $this->json(['error' => 'You are translating too fast. Wait a moment and try again.'], 429);
            return;
        }

        $text = trim($this->stringFrom($_POST, 'text'));
        // An empty From means the language is told from the text
        $from = Language::tryFrom($this->stringFrom($_POST, 'from'));
        $to = Language::tryFrom($this->stringFrom($_POST, 'to'));

        if ($text === '' || mb_strlen($text) > Chat::MAX_LENGTH || $to === null) {
            $this->json(['error' => 'This message could not be translated.'], 422);
            return;
        }

        // Counted once it goes to Groq: a refused request uses none of the limit
        if (!$this->claimGroqTurn(GroqUseKind::Translate)) {
            $this->json(['error' => 'You are translating too fast. Wait a moment and try again.'], 429);
            return;
        }
        $translation = Groq::translate($text, $from, $to);
        if ($translation === null || $translation === '') {
            $this->json(['error' => 'Translation is busy. Try again in a moment.'], 503);
            return;
        }

        if (mb_strlen($translation) > Chat::MAX_LENGTH) {
            $this->json(['error' => 'The translation is over ' . Chat::MAX_LENGTH . ' characters. Shorten the message.'], 422);
            return;
        }

        $this->json(['text' => $translation]);
    }

    /**
     * Whether this person has asked Groq for this as many times in the last
     * minute as a person can send messages; guests get fewer
     */
    protected function isMinuteLimitUsedUp(GroqUseKind $kind): bool
    {
        return GroqUse::isUsedUp(Auth::getUser()->id, $kind, $this->groqLimitPerMinute());
    }

    /**
     * Take one of this person's turns at Groq for the minute, then let the
     * session go: Groq can take many seconds, and a held session would keep
     * the person's other requests waiting
     *
     * @return bool Whether there was a turn left
     */
    protected function claimGroqTurn(GroqUseKind $kind): bool
    {
        $hasTurn = GroqUse::claim(Auth::getUser()->id, $kind, $this->groqLimitPerMinute());
        session_write_close();

        return $hasTurn;
    }

    /**
     * Turns at Groq a person gets a minute: as many as they can send messages
     */
    protected function groqLimitPerMinute(): int
    {
        return Auth::getUser()->is_guest ? Chat::GUEST_PER_MINUTE : Chat::USER_PER_MINUTE;
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
        $ip = Whereabouts::getRealIP();
        if (Whereabouts::needsLocating(Auth::getEmail(), $ip)) {
            Whereabouts::locate(Auth::getEmail(), $ip);
        }
    }

    /**
     * Who is online and who was here in the last day (for AJAX). The chat
     * page marked them online as it loaded, so they're in it from the first
     */
    public function getPeopleAction(): void
    {
        $this->json(Whereabouts::getRecentPeople());
    }

    /**
     * Everyone's pin for the map (for AJAX)
     */
    public function getPinsAction(): void
    {
        $this->json(Whereabouts::getAllLocations());
    }
}
