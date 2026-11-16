<?php

namespace Tests;

use App\Mention;
use PHPUnit\Framework\TestCase;

/**
 * Finding @mentions and showing them as chips
 */
class MentionTest extends TestCase
{
    public function testFindsTheWholeNameInAnyCase()
    {
        $this->assertTrue(Mention::isMentionedIn('hey @mapchat, where are you?', 'MapChat'));
        $this->assertTrue(Mention::isMentionedIn('@Maya Thompson hi', 'Maya Thompson'));
        $this->assertFalse(Mention::isMentionedIn('@MapChatty hi', 'MapChat'));
        $this->assertFalse(Mention::isMentionedIn('MapChat hi', 'MapChat'));
    }

    public function testMarksMentionsAsChipsAndEscapesTheRest()
    {
        $html = Mention::mark('<b>hi</b> @maya and @Ann', ['Maya', 'Ann'], 'Ann');

        $this->assertSame(
            '&lt;b&gt;hi&lt;/b&gt; <span class="mention">@maya</span> and <span class="mention mention--me">@Ann</span>',
            $html
        );
    }

    public function testPrefersTheLongerName()
    {
        $html = Mention::mark('@Maya Thompson hi', ['Maya', 'Maya Thompson'], 'Ann');

        $this->assertSame('<span class="mention">@Maya Thompson</span> hi', $html);
    }

    public function testNamesWithSymbolsAreMatchedAsTyped()
    {
        $html = Mention::mark('hi @Tom & Jerry', ['Tom & Jerry'], 'Tom & Jerry');

        $this->assertSame('hi <span class="mention mention--me">@Tom &amp; Jerry</span>', $html);
    }

    public function testFirstNamesCountExceptForGuests()
    {
        $this->assertTrue(Mention::isMentionedIn('hi @maya!', 'Maya Thompson'));
        $this->assertTrue(Mention::isMentionedIn('hi @Maya Thompson', 'Maya Thompson'));
        $this->assertFalse(Mention::isMentionedIn('hi @Guest', 'Guest 2189', null, true));
        $this->assertTrue(Mention::isMentionedIn('hi @guest 2189', 'Guest 2189', null, true));
    }

    public function testMarksTheReadersFirstNameAsTheirs()
    {
        $html = Mention::mark('@Maya and @Ann', ['Maya Thompson', 'Maya', 'Ann Lee', 'Ann'], 'Ann Lee');

        $this->assertSame('<span class="mention">@Maya</span> and <span class="mention mention--me">@Ann</span>', $html);
    }

    public function testANicknameTakesThePlaceOfTheFirstName()
    {
        $this->assertSame('zee', Mention::shownName('Zainab Ali', 'zee'));
        $this->assertTrue(Mention::isMentionedIn('hi @Zee', 'Zainab Ali', 'zee'));
        $this->assertTrue(Mention::isMentionedIn('hi @Zainab Ali', 'Zainab Ali', 'zee'));
        $this->assertFalse(Mention::isMentionedIn('hi @Zainab', 'Zainab Ali', 'zee'));
    }

    public function testGuestsKeepTheirGuestName()
    {
        $this->assertSame('Guest 2189', Mention::shownName('Guest 2189', 'sunny', true));
        $this->assertFalse(Mention::isMentionedIn('hi @sunny', 'Guest 2189', 'sunny', true));
    }

    public function testMarksTheReadersNicknameAsTheirs()
    {
        $html = Mention::mark('hi @zee', ['Zainab Ali', 'zee'], 'Zainab Ali', 'zee');

        $this->assertSame('hi <span class="mention mention--me">@zee</span>', $html);
    }
}
