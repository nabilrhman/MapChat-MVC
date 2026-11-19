<?php

namespace App\Bot;

use App\Config;
use App\Messages\VoiceClip;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Writes MapChat's and the demo people's replies (App\Models\Bot) with
 * Groq's chat API, and turns voice messages into text with its Whisper.
 * Both are on its free plan. Off until GROQ_API_KEY is set. With
 * CEREBRAS_API_KEY set too, Cerebras writes a reply when Groq is busy or
 * down: it runs the same model, behind the same kind of API
 */
class Groq
{
    /** Groq's chat API */
    const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** Used when MAPCHAT_MODEL is unset */
    const MODEL = 'openai/gpt-oss-120b';

    /** Cerebras's chat API, Groq's stand-in for replies */
    const CEREBRAS_API_URL = 'https://api.cerebras.ai/v1/chat/completions';

    /** The same model as Groq's default, by Cerebras's name for it */
    const CEREBRAS_MODEL = 'gpt-oss-120b';

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
        $messages = [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => "<chat>\n$chat\n</chat>"],
        ];

        try {
            return static::reply(static::API_URL, Config::get('GROQ_API_KEY'), Config::get('MAPCHAT_MODEL', static::MODEL), $messages);
        } catch (ExceptionInterface $e) {
            if (!static::isWorthAskingCerebras($e)) {
                error_log('MapChat could not write a reply: ' . $e->getMessage());
                return null;
            }
            error_log('Groq could not write a reply, so Cerebras will: ' . $e->getMessage());
        }

        try {
            return static::reply(static::CEREBRAS_API_URL, Config::get('CEREBRAS_API_KEY'), static::CEREBRAS_MODEL, $messages);
        } catch (ExceptionInterface $e) {
            error_log('Cerebras could not write a reply either: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * A chat API's reply to the messages
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws ExceptionInterface When the API is busy, down or refuses
     */
    protected static function reply(string $apiUrl, string $apiKey, string $model, array $messages): ?string
    {
        $request = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => 1000,
        ];

        // A short chat line needs little thinking; only the gpt-oss models take it
        if (str_contains($model, 'gpt-oss')) {
            $request['reasoning_effort'] = 'low';
        }
        // Only Groq takes this; Cerebras keeps the thinking apart from the reply anyway
        if ($apiUrl === static::API_URL && str_contains($model, 'gpt-oss')) {
            $request['include_reasoning'] = false;
        }

        $response = HttpClient::create()->request('POST', $apiUrl, [
            'auth_bearer' => $apiKey,
            'json' => $request,
            'max_duration' => static::TIMEOUT,
        ]);

        return $response->toArray()['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Whether Groq failed in a way Cerebras can make up for: it's busy (its
     * free plan used up for the minute), down, or out of reach, and there's
     * a Cerebras key. A request it refuses would be refused there too
     */
    protected static function isWorthAskingCerebras(ExceptionInterface $groqError): bool
    {
        if (Config::get('CEREBRAS_API_KEY') === null) {
            return false;
        }
        if ($groqError instanceof TransportExceptionInterface) {
            return true;
        }
        if (!$groqError instanceof HttpExceptionInterface) {
            return false;
        }

        $status = $groqError->getResponse()->getStatusCode();
        return $status === 429 || $status >= 500;
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
