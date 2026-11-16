<?php

namespace App;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Writes MapChat's and the demo people's replies (App\Models\Bot) with
 * Groq's chat API, which has a free plan. Off until GROQ_API_KEY is set
 */
class Groq
{
    /** Groq's chat API */
    const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** Used when MAPCHAT_MODEL is unset */
    const MODEL = 'openai/gpt-oss-120b';

    /** The longest a reply may take to write, in seconds; the chat worker waits on it */
    const TIMEOUT = 20;

    /**
     * Whether a key to the API is set
     */
    public static function isEnabled(): bool
    {
        return Config::get('GROQ_API_KEY') !== null;
    }

    /**
     * The next chat message, as the instructions describe it
     *
     * @param string $instructions Who's writing and how
     * @param string $chat The chat so far, as a transcript
     *
     * @return string|null Null when the API couldn't answer
     */
    public static function write(string $instructions, string $chat): ?string
    {
        $model = Config::get('MAPCHAT_MODEL', static::MODEL);
        $request = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "<chat>\n$chat\n</chat>"],
            ],
            'max_completion_tokens' => 1000,
        ];

        // A short chat line needs little thinking; only the gpt-oss models take these
        if (str_starts_with($model, 'openai/gpt-oss')) {
            $request['reasoning_effort'] = 'low';
            $request['include_reasoning'] = false;
        }

        try {
            $response = HttpClient::create()->request('POST', static::API_URL, [
                'auth_bearer' => Config::get('GROQ_API_KEY'),
                'json' => $request,
                'max_duration' => static::TIMEOUT,
            ]);

            return $response->toArray()['choices'][0]['message']['content'] ?? null;
        } catch (ExceptionInterface $e) {
            error_log('MapChat could not write a reply: ' . $e->getMessage());
            return null;
        }
    }
}
