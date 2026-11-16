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
}
