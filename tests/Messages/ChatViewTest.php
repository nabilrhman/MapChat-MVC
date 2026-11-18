<?php

namespace Tests\Messages;

use App\Messages\MessagesForReader;
use App\Models\Chat;
use App\Models\User;
use Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Messages as Room/chat.html draws them for one reader: whose name is a
 * button, which tab each goes in, mentions of the reader, and the place row
 */
class ChatViewTest extends TestCase
{
    const ANN_ID = 1;
    const BOB_ID = 2;

    private User $ann;

    protected function setUp(): void
    {
        $this->ann = new User(['name' => 'Ann Lee', 'email' => 'ann@test.local']);
        $this->ann->id = self::ANN_ID;
    }

    /**
     * A message row as App\Models\Chat gives it: from Bob to the room, in
     * Dhaka, unless the changes say otherwise
     *
     * @param array<string, mixed> $changes Columns to set, by name
     */
    private function message(array $changes = []): array
    {
        return $changes + [
            'id' => 10, 'body' => 'Hello room', 'timestamp' => '2026-11-17 09:30:00', 'to_email' => null,
            'user_id' => self::BOB_ID, 'name' => 'Bob Ray', 'email' => 'bob@test.local', 'avatar' => null,
            'is_demo' => false, 'nickname' => null, 'is_guest' => false,
            'city' => 'Dhaka', 'country' => 'BD', 'lng' => '90.4', 'timezone' => 'Asia/Dhaka',
            'to_user_id' => null, 'to_name' => null, 'to_nickname' => null, 'to_is_guest' => null,
        ];
    }

    /**
     * The messages as Ann's page gets them
     *
     * @param string[] $mentionNames Every way people are mentioned in them (Mention::namesIn)
     */
    private function render(array $chats, array $mentionNames = []): string
    {
        return View::getTemplate('Room/chat.html', [
            'chats' => MessagesForReader::rowsOf($chats, $this->ann),
            'current_user' => $this->ann,
            'mention_names' => $mentionNames,
        ]);
    }

    public function testSomeoneElsesNameInTheRoomIsAButtonToMessageThem(): void
    {
        $html = $this->render([$this->message()]);

        $this->assertStringContainsString('data-conversation="everyone"', $html);
        $this->assertMatchesRegularExpression('/<button class="name-button"[^>]*data-user-id="2"[^>]*title="Message Bob">Bob<\/button>/', $html);
    }

    public function testYourOwnNameIsNotAButton(): void
    {
        $html = $this->render([$this->message(['user_id' => self::ANN_ID, 'name' => 'Ann Lee', 'email' => 'ann@test.local'])]);

        $this->assertStringNotContainsString('name-button', $html);
        $this->assertStringContainsString('data-part="sender-name">Ann</h3>', $html);
    }

    public function testAPrivateMessageGoesInTheOtherPersonsTabWithoutAButtonOrPlace(): void
    {
        $toAnn = $this->message(['to_email' => 'ann@test.local', 'to_user_id' => self::ANN_ID, 'to_name' => 'Ann Lee', 'to_is_guest' => false,
            'body' => 'Hi @Ann']);

        $html = $this->render([$toAnn], ['Ann Lee', 'Ann']);

        $this->assertStringContainsString('data-conversation="2" data-partner="Bob"', $html);
        $this->assertStringNotContainsString('name-button', $html);
        $this->assertStringNotContainsString('class="location"', $html);
        $this->assertStringNotContainsString('data-mentions-me', $html);
        $this->assertStringContainsString('data-part="message-body">Hi @Ann</p>', $html);
    }

    public function testYourOwnPrivateMessageGoesInTheRecipientsTab(): void
    {
        $toBob = $this->message(['user_id' => self::ANN_ID, 'name' => 'Ann Lee', 'email' => 'ann@test.local',
            'to_email' => 'bob@test.local', 'to_user_id' => self::BOB_ID, 'to_name' => 'Bob Ray', 'to_is_guest' => false]);

        $this->assertStringContainsString('data-conversation="2" data-partner="Bob"', $this->render([$toBob]));
    }

    public function testAMentionOfYouInTheRoomIsHighlighted(): void
    {
        $html = $this->render([$this->message(['body' => 'Hi @Ann, how is it?'])], ['Ann Lee', 'Ann']);

        $this->assertStringContainsString('class="chat mentions-me', $html);
        $this->assertStringContainsString('data-mentions-me', $html);
        $this->assertStringContainsString('<span class="mention me">@Ann</span>', $html);
    }

    public function testAMessageIsEscapedAroundItsMentions(): void
    {
        $html = $this->render([$this->message(['body' => '<b>@Ann</b>'])], ['Ann Lee', 'Ann']);

        $this->assertStringContainsString('&lt;b&gt;<span class="mention me">@Ann</span>&lt;/b&gt;', $html);
    }

    public function testTheRoomShowsTheSendersCityThatSlidesToTheirCountry(): void
    {
        $html = $this->render([$this->message()]);

        $this->assertStringContainsString('class="location"', $html);
        $this->assertMatchesRegularExpression('/<button class="city line-swap" type="button" data-part="city" data-swaps>/', $html);
        $this->assertStringContainsString('Dhaka</span>', $html);
        $this->assertStringContainsString('data-part="sender-time"', $html);
    }

    public function testOnlyTheCountryShowsItsFlagAlone(): void
    {
        $html = $this->render([$this->message(['city' => null])]);

        $this->assertStringContainsString('<button class="city" type="button" data-part="city"><span class="fi fi-bd">', $html);
    }

    public function testAHiddenPlaceHasNoLocationRowNorSenderTime(): void
    {
        $html = $this->render([$this->message(['city' => null, 'country' => null, 'lng' => null, 'timezone' => null])]);

        $this->assertStringNotContainsString('class="location"', $html);
        $this->assertStringNotContainsString('data-part="sender-time"', $html);
    }

    public function testMapChatsNameIsAButtonOnlyWhileItCanWriteBack(): void
    {
        $fromMapChat = $this->message(['email' => Chat::BOT_EMAIL, 'name' => 'MapChat']);
        $groqKeyBefore = getenv('GROQ_API_KEY');

        try {
            putenv('GROQ_API_KEY');
            $this->assertStringNotContainsString('name-button', $this->render([$fromMapChat]));

            putenv('GROQ_API_KEY=test-key');
            $this->assertStringContainsString('name-button', $this->render([$fromMapChat]));
        } finally {
            $groqKeyBefore === false ? putenv('GROQ_API_KEY') : putenv('GROQ_API_KEY=' . $groqKeyBefore);
        }
    }
}
