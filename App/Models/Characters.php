<?php

namespace App\Models;

/**
 * Who in the chat isn't a real person: the MapChat account and the demo
 * people. Their messages never start an answer (Bot), and they don't count
 * as someone in the room
 */
class Characters
{
    /** The demo people's email addresses end with this */
    const DEMO_DOMAIN = '@demo.mapchat.local';

    /**
     * Whether this is a real person: not MapChat and not one of the demo people
     */
    public static function isPerson(string $email): bool
    {
        return $email !== Chat::BOT_EMAIL && !static::isDemo($email);
    }

    /**
     * Whether this email is one of the demo people's
     */
    public static function isDemo(string $email): bool
    {
        return str_ends_with($email, static::DEMO_DOMAIN);
    }

    /**
     * SQL that's true for the demo people
     *
     * @param string $column The column holding each row's email
     */
    public static function isDemoSql(string $column = 'users.email'): string
    {
        return "$column LIKE '%" . static::DEMO_DOMAIN . "'";
    }

    /**
     * A condition to add to a query's WHERE that leaves the demo people out
     *
     * @param string $column The column holding each row's email
     */
    public static function notDemoSql(string $column = 'chats.email'): string
    {
        return ' AND NOT ' . static::isDemoSql($column);
    }

    /**
     * A condition to add to a query's WHERE that leaves MapChat and the demo
     * people out
     *
     * @param string $column The column holding each row's email
     */
    public static function peopleOnlySql(string $column = 'chats.email'): string
    {
        return " AND $column <> '" . Chat::BOT_EMAIL . "'" . static::notDemoSql($column);
    }
}
