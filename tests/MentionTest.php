<?php

namespace Tests;

use App\Messages\Mention;
use App\PersonName;
use PHPUnit\Framework\TestCase;

/**
 * Finding @mentions and showing them as chips
 */
class MentionTest extends TestCase
{
    public function testFindsTheWholeNameInAnyCase(): void
    {
        $this->assertTrue(Mention::isMentionedIn('hey @mapchat, where are you?', new PersonName('MapChat')));
        $this->assertTrue(Mention::isMentionedIn('@Maya Thompson hi', new PersonName('Maya Thompson')));
        $this->assertFalse(Mention::isMentionedIn('@MapChatty hi', new PersonName('MapChat')));
        $this->assertFalse(Mention::isMentionedIn('MapChat hi', new PersonName('MapChat')));
    }

    public function testMarksMentionsAsChipsAndEscapesTheRest(): void
    {
        $html = Mention::htmlWithChips('<b>hi</b> @maya and @Ann', ['Maya', 'Ann'], new PersonName('Ann'));

        $this->assertSame(
            '&lt;b&gt;hi&lt;/b&gt; <span class="mention">@maya</span> and <span class="mention me">@Ann</span>',
            $html
        );
    }

    public function testPrefersTheLongerName(): void
    {
        $html = Mention::htmlWithChips('@Maya Thompson hi', ['Maya', 'Maya Thompson'], new PersonName('Ann'));

        $this->assertSame('<span class="mention">@Maya Thompson</span> hi', $html);
    }

    public function testNamesWithSymbolsAreMatchedAsTyped(): void
    {
        $html = Mention::htmlWithChips('hi @Tom & Jerry', ['Tom & Jerry'], new PersonName('Tom & Jerry'));

        $this->assertSame('hi <span class="mention me">@Tom &amp; Jerry</span>', $html);
    }

    public function testFirstNamesCountExceptForGuests(): void
    {
        $this->assertTrue(Mention::isMentionedIn('hi @maya!', new PersonName('Maya Thompson')));
        $this->assertTrue(Mention::isMentionedIn('hi @Maya Thompson', new PersonName('Maya Thompson')));
        $this->assertFalse(Mention::isMentionedIn('hi @Guest', new PersonName('Guest 2189', isGuest: true)));
        $this->assertTrue(Mention::isMentionedIn('hi @guest 2189', new PersonName('Guest 2189', isGuest: true)));
    }

    public function testMarksTheReadersFirstNameAsTheirs(): void
    {
        $html = Mention::htmlWithChips('@Maya and @Ann', ['Maya Thompson', 'Maya', 'Ann Lee', 'Ann'], new PersonName('Ann Lee'));

        $this->assertSame('<span class="mention">@Maya</span> and <span class="mention me">@Ann</span>', $html);
    }

    public function testANicknameTakesThePlaceOfTheFirstName(): void
    {
        $this->assertSame('zee', Mention::shownName('Zainab Ali', 'zee'));
        $this->assertTrue(Mention::isMentionedIn('hi @Zee', new PersonName('Zainab Ali', 'zee')));
        $this->assertTrue(Mention::isMentionedIn('hi @Zainab Ali', new PersonName('Zainab Ali', 'zee')));
        $this->assertFalse(Mention::isMentionedIn('hi @Zainab', new PersonName('Zainab Ali', 'zee')));
    }

    public function testGuestsKeepTheirGuestName(): void
    {
        $this->assertSame('Guest 2189', Mention::shownName('Guest 2189', 'sunny', isGuest: true));
        $this->assertFalse(Mention::isMentionedIn('hi @sunny', new PersonName('Guest 2189', 'sunny', isGuest: true)));
    }

    public function testMarksTheReadersNicknameAsTheirs(): void
    {
        $html = Mention::htmlWithChips('hi @zee', ['Zainab Ali', 'zee'], new PersonName('Zainab Ali', 'zee'));

        $this->assertSame('hi <span class="mention me">@zee</span>', $html);
    }
}
