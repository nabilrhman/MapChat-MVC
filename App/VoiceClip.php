<?php

namespace App;

/**
 * A recording from the chat's mic button (voice.js), checked before Groq
 * turns it into text
 */
class VoiceClip
{
    /** Under PHP's default upload_max_filesize of 2M; a minute of speech is far less */
    const MAX_BYTES = 2_000_000;

    /** The types browsers record in, and the extension Groq needs to read each */
    const EXTENSIONS = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm', // Chrome's recordings, as fileinfo sees them
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4', // Safari's
        'audio/x-m4a' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    /**
     * Keeps a checked recording
     *
     * @param string $path Where the upload sits on disk
     * @param string $type Its type, from the file's own bytes
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
    ) {
    }

    /**
     * What's wrong with an upload, said for the person, or null when it's a
     * recording fromUpload can take
     *
     * @param array|null $upload One entry of $_FILES
     */
    public static function problemWith(?array $upload): ?string
    {
        if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Nothing was recorded.';
        }

        $isTooBig = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || $upload['size'] > static::MAX_BYTES;
        if ($isTooBig) {
            return 'That recording is too long. Try a shorter one.';
        }

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return 'Your recording could not be sent.';
        }

        if (!isset(static::EXTENSIONS[static::typeOf($upload)])) {
            return 'That recording is not a sound file.';
        }

        return null;
    }

    /**
     * The recording an upload holds, once problemWith found nothing wrong
     *
     * @param array $upload One entry of $_FILES
     */
    public static function fromUpload(array $upload): self
    {
        return new self($upload['tmp_name'], static::typeOf($upload));
    }

    /**
     * An upload's type, from the file's own bytes: the browser's label could say anything
     *
     * @param array $upload One entry of $_FILES
     */
    protected static function typeOf(array $upload): string
    {
        return (string) mime_content_type($upload['tmp_name']);
    }

    /**
     * The name Groq is sent it under: the extension is how Groq knows the format
     */
    public function fileName(): string
    {
        return 'voice.' . static::EXTENSIONS[$this->type];
    }
}
