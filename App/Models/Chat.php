<?php

namespace App\Models;

use PDO;
use \App\Token;
use \App\Mail;
use \Core\View;
use \App\Auth;

/**
 * User model
 *
 * PHP version 7.0
 */
class Chat extends \Core\Model
{
    /** Longest message allowed, in characters */
    const MAX_LENGTH = 500;

    /** Messages a signed-up user can send per minute */
    const USER_PER_MINUTE = 30;

    /** Messages a guest can send per minute */
    const GUEST_PER_MINUTE = 10;

    /**
     * How many messages this person sent in the last few seconds
     *
     * @param string $email
     * @param int $seconds
     *
     * @return int
     */
    public static function countRecent($email, $seconds)
    {
        $sql = 'SELECT COUNT(*) FROM chats
                WHERE email = :email
                  AND timestamp > NOW() - INTERVAL :seconds SECOND';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':seconds', $seconds, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Error messages
     *
     * @var array
     */
    public $errors = [];


    /**
     * Class constructor
     *
     * @param array $data Initial property values (optional)
     *
     * @return void
     */
    public function __construct($data = [])
    {
        foreach ($data as $key => $value)
        {
            $this->$key = $value;
        };


    }

    /**
     * Save the user model with the current property values
     *
     * @return boolean  True if the user was saved, false otherwise
     */
    public function send()
    {
        if (empty($this->errors))
        {

            if (!empty($this->body))
            {
                // Stored as typed; Twig escapes it when the chat is shown
                $sql = 'INSERT INTO chats (email, body)
                            VALUES (:email, :body)';

                $db = static::getDB();
                $stmt = $db->prepare($sql);

                $stmt->bindValue(':body', $this->body, PDO::PARAM_STR);
                $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);

                $stmt->execute();
            }

        }

        return false;
    }

    /**
     * Save the user model with the current property values
     *
     * @return boolean  True if the user was saved, false otherwise
     */
    public function hasChatAfter()
    {
        if (empty($this->errors))
        {

            $sql = 'SELECT COUNT(*) FROM chats
                    WHERE chats.id > :last_chat_id ';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':last_chat_id', $this->last_chat_id, PDO::PARAM_STR);
            $stmt->execute();
            $newChatNum = $stmt->fetchColumn();

            if ($newChatNum != 0)
            {
                return true;
            }
            else
            {
                return false;
            }
        }

        return false;
    }

    /**
     * Save the user model with the current property values
     *
     * @return boolean  True if the user was saved, false otherwise
     */
    public function getAll()
    {
        if (empty($this->errors))
        {

            $sql = 'SELECT chats.id, chats.body, chats.timestamp, users.id AS user_id, users.name, users.email, users.avatar,
                           IF(users.share_location, userinfo.city, NULL) AS city
                    FROM chats
                    LEFT JOIN users
                    ON chats.email = users.email
                    LEFT JOIN userinfo
                    ON users.email = userinfo.email
                    ORDER BY chats.id';

            $db = static::getDB();
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $chats = $stmt->fetchAll();

            if ($chats != false)
            {
                foreach ($chats as $row)
                {
                    $this->last_chat_id = $row['id'];
                }
            }

            return $chats;

        }
        return false;
    }


    public function getAfter()
    {
        if (empty($this->errors))
        {
            $sql = 'SELECT chats.id, chats.body, chats.timestamp, users.id AS user_id, users.name, users.email, users.avatar,
                           IF(users.share_location, userinfo.city, NULL) AS city
                    FROM chats
                    LEFT JOIN users
                    ON chats.email = users.email
                    LEFT JOIN userinfo
                    ON users.email = userinfo.email
	                WHERE chats.id > :last_chat_id
                    ORDER BY chats.id';

            $db = static::getDB();
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':last_chat_id', $this->last_chat_id, PDO::PARAM_STR);
            $stmt->execute();
            $chats = $stmt->fetchAll();

            if ($chats != false)
            {
                foreach ($chats as $row)
                {
                    $this->last_chat_id = $row['id'];
                }

            }

            return $chats;
        }
        return false;

    }

    public function getLastChatId()
    {
        return $this->last_chat_id;
    }

    /*
    public function setLastChatId($chatId)
    {
        $this->lastChatId = $chatId;
    }*/


}
