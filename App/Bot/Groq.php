<?php

namespace App\Bot;

use App\Config;
use App\Messages\VoiceClip;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Writes MapChat's and the demo people's replies (App\Models\Bot) with
 * Groq's chat API, and turns voice messages into text with its Whisper.
 * Both are on its free plan. Off until GROQ_API_KEY is set
 */
class Groq
{
    /** Groq's chat API */
    const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** Used when MAPCHAT_MODEL is unset */
    const MODEL = 'openai/gpt-oss-120b';

    /** The longest a reply may take to write, in seconds; the chat worker waits on it */
    const TIMEOUT = 20;

    /** Groq's speech to text API */
    const TRANSCRIBE_URL = 'https://api.groq.com/openai/v1/audio/transcriptions';

    /** The fastest and cheapest Whisper Groq runs */
    const TRANSCRIBE_MODEL = 'whisper-large-v3-turbo';

    /** The longest a recording may take to turn into text, in seconds; the person waits on it */
    const TRANSCRIBE_TIMEOUT = 20;

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

    /**
     * What was said in a recording, in the language it was said in
     *
     * @return string|null Null when the API couldn't answer, as when the free
     *                     plan's limit for the minute or the day is used up
     */
    public static function transcribe(VoiceClip $clip): ?string
    {
        $form = new FormDataPart([
            'model' => static::TRANSCRIBE_MODEL,
            'response_format' => 'json',
            'file' => DataPart::fromPath($clip->path, $clip->fileName(), $clip->type),
        ]);

        try {
            $response = HttpClient::create()->request('POST', static::TRANSCRIBE_URL, [
                'auth_bearer' => Config::get('GROQ_API_KEY'),
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'max_duration' => static::TRANSCRIBE_TIMEOUT,
            ]);

            return trim($response->toArray()['text'] ?? '');
        } catch (ExceptionInterface $e) {
            error_log('MapChat could not turn a recording into text: ' . $e->getMessage());
            return null;
        }
    }
}
