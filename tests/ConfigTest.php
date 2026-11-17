<?php

namespace Tests;

use App\Config;
use PHPUnit\Framework\TestCase;

/**
 * The site's own address, which links in emails are built from
 */
class ConfigTest extends TestCase
{
    private string|false $appUrlBefore;

    /** @var array<string, mixed> */
    private array $serverBefore;

    protected function setUp(): void
    {
        $this->appUrlBefore = getenv('APP_URL');
        $this->serverBefore = $_SERVER;
    }

    protected function tearDown(): void
    {
        $this->appUrlBefore === false ? putenv('APP_URL') : putenv('APP_URL=' . $this->appUrlBefore);
        $_SERVER = $this->serverBefore;
    }

    public function testTheAddressIsAppUrlWithoutItsLastSlash(): void
    {
        putenv('APP_URL=https://mapchat.example.com/');

        $this->assertSame('https://mapchat.example.com', Config::appUrl());
    }

    public function testARequestsHostHeaderNeverBecomesTheAddress(): void
    {
        putenv('APP_URL');
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $_SERVER['HTTPS'] = 'on';

        $this->assertSame(Config::LOCAL_URL, Config::appUrl());
    }
}
