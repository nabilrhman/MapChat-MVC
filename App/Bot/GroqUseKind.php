<?php

namespace App\Bot;

/**
 * What a person asked Groq for, as groq_uses.kind stores it. Each has a
 * limit a minute of its own (App\Models\GroqUse)
 */
enum GroqUseKind: string
{
    /** The message box's text put into another language */
    case Translate = 'translate';

    /** A voice typing recording turned into text */
    case Transcribe = 'transcribe';
}
