<?php

namespace Tests;

use Core\SameOrigin;
use PHPUnit\Framework\TestCase;

/**
 * Which pages count as this site's, for posts and the chat socket
 */
class SameOriginTest extends TestCase
{
    private string|false $appUrlBefore;

    protected function setUp(): void
    {
        $this->appUrlBefore = getenv('APP_URL');
        putenv('APP_URL');
    }

    protected function tearDown(): void
    {
        $this->appUrlBefore === false ? putenv('APP_URL') : putenv('APP_URL=' . $this->appUrlBefore);
    }

    public function testAPageOnTheAddressTheRequestCameToIsThisSites()
    {
        $this->assertTrue(SameOrigin::isFromThisSite('http://localhost:8080', 'localhost:8080', false));
        $this->assertTrue(SameOrigin::isFromThisSite('https://mapchat.test', 'mapchat.test', true));
        $this->assertTrue(SameOrigin::isFromThisSite('https://mapchat.test:443', 'MapChat.test', true));
        $this->assertTrue(SameOrigin::isFromThisSite('http://localhost:8080/app?x=1', 'localhost:8080', false), 'a Referer');
    }

    public function testAnotherPortSchemeOrHostIsNot()
    {
        $this->assertFalse(SameOrigin::isFromThisSite('http://localhost:9999', 'localhost:8080', false));
        $this->assertFalse(SameOrigin::isFromThisSite('http://mapchat.test', 'mapchat.test', true));
        $this->assertFalse(SameOrigin::isFromThisSite('https://mapchat.test.evil.example', 'mapchat.test', true));
        $this->assertFalse(SameOrigin::isFromThisSite('https://evil.example', 'mapchat.test', true));
    }

    public function testNoOriginIsNotThisSites()
    {
        $this->assertFalse(SameOrigin::isFromThisSite(null, 'localhost:8080', false));
        $this->assertFalse(SameOrigin::isFromThisSite('', 'localhost:8080', false));
        $this->assertFalse(SameOrigin::isFromThisSite('null', 'localhost:8080', false));
        $this->assertFalse(SameOrigin::isFromThisSite('http://localhost:8080', null, false));
    }

    public function testAppUrlsOriginIsThisSitesWhateverTheHost()
    {
        putenv('APP_URL=https://mapchat.example.com/');

        $this->assertTrue(SameOrigin::isFromThisSite('https://mapchat.example.com', '127.0.0.1:8090', false));
        $this->assertFalse(SameOrigin::isFromThisSite('https://mapchat.example.com:8443', '127.0.0.1:8090', false));
        $this->assertFalse(SameOrigin::isFromThisSite('http://mapchat.example.com', '127.0.0.1:8090', false));
    }
}
