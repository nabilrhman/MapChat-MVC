<?php

namespace App\Messages;

/**
 * The languages the message box can translate from and to, by the code
 * the page sends. Each case's name is the language's name in English, as
 * the lists show it
 */
enum Language: string
{
    case Arabic = 'ar';
    case Bangla = 'bn';
    case Chinese = 'zh';
    case Dutch = 'nl';
    case English = 'en';
    case French = 'fr';
    case German = 'de';
    case Hindi = 'hi';
    case Indonesian = 'id';
    case Italian = 'it';
    case Japanese = 'ja';
    case Korean = 'ko';
    case Malay = 'ms';
    case Nepali = 'ne';
    case Persian = 'fa';
    case Polish = 'pl';
    case Portuguese = 'pt';
    case Russian = 'ru';
    case Spanish = 'es';
    case Swahili = 'sw';
    case Tamil = 'ta';
    case Thai = 'th';
    case Turkish = 'tr';
    case Urdu = 'ur';
    case Vietnamese = 'vi';

    /** What To starts on, before the person picks */
    const self FIRST_TARGET = self::English;

    /**
     * The language as the From and To lists show it
     *
     * @return array{code: string, name: string}
     */
    public function choice(): array
    {
        return ['code' => $this->value, 'name' => $this->name];
    }

    /**
     * Every language, as the From and To lists show them
     *
     * @return list<array{code: string, name: string}>
     */
    public static function choices(): array
    {
        return array_map(fn (self $language): array => $language->choice(), self::cases());
    }
}
