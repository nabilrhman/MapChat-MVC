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
                $this->body = htmlspecialchars($this->body);
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

            $sql = 'SELECT chats.id, chats.body, chats.timestamp, users.name, users.email, users.avatar, userinfo.city
                    FROM chats
                    LEFT JOIN users
                    ON chats.email = users.email
                    LEFT JOIN userinfo
                    ON users.email = userinfo.email';

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
            $sql = 'SELECT chats.id, chats.body, chats.timestamp, users.name, users.email, users.avatar, userinfo.city
                    FROM chats
                    LEFT JOIN users
                    ON chats.email = users.email
                    LEFT JOIN userinfo
                    ON users.email = userinfo.email
	                WHERE chats.id > :last_chat_id';

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
