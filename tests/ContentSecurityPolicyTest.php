<?php

namespace Tests;

use Core\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The policy pages are sent with: only nonced scripts run, and nothing can
 * frame the site or post its forms elsewhere
 */
class ContentSecurityPolicyTest extends TestCase
{
    public function testOnlyScriptsWithThisRequestsNonceRun(): void
    {
        $policy = ContentSecurityPolicy::headerFor('mapchat.example.com');

        $this->assertStringContainsString("script-src 'nonce-" . ContentSecurityPolicy::nonce() . "' 'strict-dynamic';", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function testTheChatSocketOnThisHostCanConnect(): void
    {
        $policy = ContentSecurityPolicy::headerFor('mapchat.example.com');

        $this->assertStringContainsString('wss://mapchat.example.com', $policy);
    }

    public function testNothingCanFrameThePageOrPostItsFormsElsewhere(): void
    {
        $policy = ContentSecurityPolicy::headerFor('mapchat.example.com');

        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'none'", $policy);
    }

    public function testTheNonceStaysTheSameForOneRequest(): void
    {
        $this->assertSame(ContentSecurityPolicy::nonce(), ContentSecurityPolicy::nonce());
        $this->assertGreaterThanOrEqual(22, strlen(ContentSecurityPolicy::nonce()));
    }
}
