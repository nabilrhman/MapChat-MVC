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
        $this->assertTrue(Mention::has('hey @mapchat, where are you?', 'MapChat'));
        $this->assertTrue(Mention::has('@Maya Thompson hi', 'Maya Thompson'));
        $this->assertFalse(Mention::has('@MapChatty hi', 'MapChat'));
        $this->assertFalse(Mention::has('MapChat hi', 'MapChat'));
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
        $this->assertTrue(Mention::has('hi @maya!', 'Maya Thompson'));
        $this->assertTrue(Mention::has('hi @Maya Thompson', 'Maya Thompson'));
        $this->assertFalse(Mention::has('hi @Guest', 'Guest 2189', null, true));
        $this->assertTrue(Mention::has('hi @guest 2189', 'Guest 2189', null, true));
    }

    public function testMarksTheReadersFirstNameAsTheirs()
    {
        $html = Mention::mark('@Maya and @Ann', ['Maya Thompson', 'Maya', 'Ann Lee', 'Ann'], 'Ann Lee');

        $this->assertSame('<span class="mention">@Maya</span> and <span class="mention mention--me">@Ann</span>', $html);
    }

    public function testANicknameTakesThePlaceOfTheFirstName()
    {
        $this->assertSame('zee', Mention::handle('Zainab Ali', 'zee'));
        $this->assertTrue(Mention::has('hi @Zee', 'Zainab Ali', 'zee'));
        $this->assertTrue(Mention::has('hi @Zainab Ali', 'Zainab Ali', 'zee'));
        $this->assertFalse(Mention::has('hi @Zainab', 'Zainab Ali', 'zee'));
    }

    public function testMarksTheReadersNicknameAsTheirs()
    {
        $html = Mention::mark('hi @zee', ['Zainab Ali', 'zee'], 'Zainab Ali', 'zee');

        $this->assertSame('hi <span class="mention mention--me">@zee</span>', $html);
    }
}
