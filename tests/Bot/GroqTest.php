<?php

namespace Tests\Bot;

use App\Bot\Groq;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Groq, with its choice of when Cerebras stands in open to the tests
 */
class GroqForTests extends Groq
{
    public static function isWorthAskingCerebrasFor(ExceptionInterface $groqError): bool
    {
        return static::isWorthAskingCerebras($groqError);
    }
}

/**
 * Cerebras writes a reply only when Groq is busy, down or out of reach,
 * and only with a key for it
 */
class GroqTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CEREBRAS_API_KEY');
    }

    public function testCerebrasStandsInWhenGroqIsBusyDownOrOutOfReach(): void
    {
        putenv('CEREBRAS_API_KEY=a-key');

        $this->assertTrue(GroqForTests::isWorthAskingCerebrasFor($this->groqErrorWithStatus(429)));
        $this->assertTrue(GroqForTests::isWorthAskingCerebrasFor($this->groqErrorWithStatus(503)));
        $this->assertTrue(GroqForTests::isWorthAskingCerebrasFor(new TransportException('Could not resolve host')));
    }

    public function testARequestGroqRefusesIsNotSentToCerebras(): void
    {
        putenv('CEREBRAS_API_KEY=a-key');

        $this->assertFalse(GroqForTests::isWorthAskingCerebrasFor($this->groqErrorWithStatus(401)));
        $this->assertFalse(GroqForTests::isWorthAskingCerebrasFor($this->groqErrorWithStatus(400)));
    }

    public function testWithoutACerebrasKeyNothingStandsIn(): void
    {
        $this->assertFalse(GroqForTests::isWorthAskingCerebrasFor($this->groqErrorWithStatus(429)));
    }

    /**
     * The error Groq's answer with this status raises, as the real request would
     */
    private function groqErrorWithStatus(int $status): ExceptionInterface
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => $status]));
        try {
            $client->request('POST', Groq::API_URL)->toArray();
        } catch (ExceptionInterface $e) {
            return $e;
        }
        $this->fail("A $status answer raised no error");
    }
}
