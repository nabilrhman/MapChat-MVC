<?php

namespace Tests;

use App\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sending email when MAILER_DSN is set up wrong, or right
 */
class MailTest extends TestCase
{
    private string $log;
    private string|false $dsnBefore;

    protected function setUp(): void
    {
        $this->dsnBefore = getenv('MAILER_DSN');
        $this->log = tempnam(sys_get_temp_dir(), 'mail-log');
    }

    protected function tearDown(): void
    {
        $this->dsnBefore === false ? putenv('MAILER_DSN') : putenv('MAILER_DSN=' . $this->dsnBefore);
        unlink($this->log);
    }

    /**
     * Send with this DSN, logging to $this->log. Set here, not in setUp:
     * PHPUnit points error_log elsewhere while each test runs
     */
    private function send(string $dsn): bool
    {
        putenv('MAILER_DSN=' . $dsn);
        $logBefore = ini_set('error_log', $this->log);

        try {
            return Mail::send('ann@test.local', 'Hi', 'Hello', '<p>Hello</p>');
        } finally {
            ini_set('error_log', (string) $logBefore);
        }
    }

    /**
     * MAILER_DSN values that are set up wrong, each with a secret in it
     */
    public static function brokenDsns(): array
    {
        return [
            'not a DSN' => ['not a dsn s3cret'],
            'no host' => ['smtp://user:s3cret@'],
            'unknown scheme' => ['nope://user:s3cret@default'],
            'bad keyword' => ['failover(smtp://user:s3cret@localhost smtp://user:s3cret@localhost'],
        ];
    }

    #[DataProvider('brokenDsns')]
    public function testABrokenDsnFailsQuietlyAndKeepsTheSecretOutOfTheLog(string $dsn)
    {
        $this->assertFalse($this->send($dsn));

        $logged = file_get_contents($this->log);
        $this->assertStringContainsString('check MAILER_DSN', $logged);
        $this->assertStringNotContainsString('s3cret', $logged);
    }

    public function testAWorkingDsnStillSends()
    {
        $this->assertTrue($this->send('null://null'));
        $this->assertSame('', file_get_contents($this->log));
    }
}
