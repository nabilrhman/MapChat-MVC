<?php

namespace App;

/**
 * Messages shown once, as a toast on the next page: kept in the session,
 * since most come just before a redirect
 */
class Flash
{
    /** The kinds of message, each shown in its own colour */
    const SUCCESS = 'success';
    const INFO = 'info';
    const WARNING = 'warning';

    /**
     * Keep a message for the next page shown
     *
     * @param string $message A full sentence, or more than one
     * @param string $type SUCCESS, INFO or WARNING
     */
    public static function addMessage(string $message, string $type = self::SUCCESS): void
    {
        $_SESSION['flash_notifications'][] = ['body' => $message, 'type' => $type];
    }

    /**
     * Take the waiting messages, so they show only once
     *
     * @return array<array{body: string, type: string}>
     */
    public static function getMessages(): array
    {
        $messages = $_SESSION['flash_notifications'] ?? [];
        unset($_SESSION['flash_notifications']);

        return $messages;
    }

    /**
     * Drop the waiting messages
     */
    public static function clear(): void
    {
        unset($_SESSION['flash_notifications']);
    }
}
