<?php

namespace App\Models;

use PDO;
use App\Token;
use App\Mail;
use Core\View;
use App\Config;

/**
 * User model
 */
class User extends \Core\Model
{
    /**
     * Error messages
     *
     * @var array
     */
    public $errors = [];

    /**
     * Save the user model with the current property values
     *
     * @return boolean  True if the user was saved, false otherwise
     */
    public function save()
    {
        $this->validate();

        if (empty($this->errors)) {
            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);

            $token = new Token();
            $hashed_token = $token->getHash();
            $this->activation_token = $token->getValue();

            // Without email there is no way to send an activation link, so
            // accounts start active
            $sql = 'INSERT INTO users (name, email, password_hash, activation_hash, is_active)
                    VALUES (:name, :email, :password_hash, :activation_hash, :is_active)';

            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);
            $stmt->bindValue(':activation_hash', $hashed_token, PDO::PARAM_STR);
            $stmt->bindValue(':is_active', Config::mailEnabled() ? 0 : 1, PDO::PARAM_INT);

            return $stmt->execute();
        }

        return false;
    }


    /**
     * Validate current property values, adding valiation error messages to the errors array property
     *
     * @return void
     */
    public function validate()
    {
        // Name
        $this->name = trim($this->name ?? '');
        if ($this->name == '') {
            $this->errors[] = 'Name: Required';
        } elseif (mb_strlen($this->name) > 50) {
            $this->errors[] = 'Name: 50 characters at most';
        }

        // email address
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = 'Email: Invalid';
        }
        if (static::emailExists($this->email, $this->id ?? null)) {
            $this->errors[] = 'Email:  Already registered';
        }

        // Password
        if (isset($this->password)) {
            if (strlen($this->password) < 6) {
                $this->errors[] = 'Password: Enter at least 6 characters';
            }

            if (preg_match('/.*[a-z]+.*/i', $this->password) == 0) {
                $this->errors[] = 'Password: Needs at least one letter';
            }

            if (preg_match('/.*\d+.*/i', $this->password) == 0) {
                $this->errors[] = 'Password: Needs at least one number';
            }
        }
    }

    /**
     * See if a user record already exists with the specified email
     *
     * @param string $email email address to search for
     * @param string $ignore_id Return false anyway if the record found has this ID
     *
     * @return boolean  True if a record already exists with the specified email, false otherwise
     */
    public static function emailExists($email, $ignore_id = null)
    {
        $user = static::findByEmail($email);

        if ($user) {
            if ($user->id != $ignore_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a user model by email address
     *
     * @param string $email email address to search for
     *
     * @return mixed User object if found, false otherwise
     */
    public static function findByEmail($email)
    {
        $sql = 'SELECT * FROM users WHERE email = :email';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);

        $stmt->setFetchMode(PDO::FETCH_CLASS, static::class);

        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Authenticate a user by email and password
     *
     * @param string $email email address
     * @param string $password password
     *
     * @return mixed  The user object or false if authentication fails
     */
    public static function authenticate($email, $password)
    {
        $user = static::findByEmail($email);

        if ($user && password_verify($password, $user->password_hash)) {
            return $user;
        }

        return false;
    }

    /**
     * Find a user model by ID
     *
     * @param string $id The user ID
     *
     * @return mixed User object if found, false otherwise
     */
    public static function findByID($id)
    {
        $sql = 'SELECT * FROM users WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $stmt->setFetchMode(PDO::FETCH_CLASS, static::class);

        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * Remember the login by inserting a new unique token into the remembered_logins table
     * for this user record
     *
     * @return boolean  True if the login was remembered successfully, false otherwise
     */
    public function rememberLogin()
    {
        $token = new Token();
        $hashed_token = $token->getHash();
        $this->remember_token = $token->getValue();

        $this->expiry_timestamp = time() + 60 * 60 * 24 * 30;  // 30 days from now

        $sql = 'INSERT INTO remembered_logins (token_hash, user_id, expires_at)
                VALUES (:token_hash, :user_id, :expires_at)';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $this->expiry_timestamp), PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Send password reset instructions to the user specified
     *
     * @param string $email The email address
     *
     * @return void
     */
    public static function sendPasswordReset($email)
    {
        $user = static::findByEmail($email);

        if ($user) {
            if ($user->startPasswordReset()) {
                $user->sendPasswordResetEmail();
            }
        }
    }

    /**
     * Start the password reset process by generating a new token and expiry
     *
     * @return boolean  True if the token was saved
     */
    protected function startPasswordReset()
    {
        $token = new Token();
        $hashed_token = $token->getHash();
        $this->password_reset_token = $token->getValue();

        $expiry_timestamp = time() + 60 * 60 * 2;  // 2 hours from now

        $sql = 'UPDATE users
                SET password_reset_hash = :token_hash,
                    password_reset_expires_at = :expires_at
                WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $expiry_timestamp), PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }


    /**
     * Find a user model by password reset token and expiry
     *
     * @param string $token Password reset token sent to user
     *
     * @return mixed User object if found and the token hasn't expired, null otherwise
     */
    public static function findByPasswordReset($token)
    {
        $token = new Token($token);
        $hashed_token = $token->getHash();

        $sql = 'SELECT * FROM users
                WHERE password_reset_hash = :token_hash';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':token_hash', $hashed_token, PDO::PARAM_STR);

        $stmt->setFetchMode(PDO::FETCH_CLASS, static::class);

        $stmt->execute();

        $user = $stmt->fetch();

        if ($user) {
            // Check password reset token hasn't expired
            if (strtotime($user->password_reset_expires_at) > time()) {
                return $user;
            }
        }
    }

    /**
     * Reset the password
     *
     * @param string $password The new password
     *
     * @return boolean  True if the password was updated successfully, false otherwise
     */
    public function resetPassword($password)
    {
        $this->password = $password;

        $this->validate();

        if (empty($this->errors)) {
            $password_hash = password_hash($this->password, PASSWORD_DEFAULT);

            $sql = 'UPDATE users
                    SET password_hash = :password_hash,
                        password_reset_hash = NULL,
                        password_reset_expires_at = NULL
                    WHERE id = :id';

            $db = static::getDB();
            $stmt = $db->prepare($sql);
                                                  
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);

            $stmt->execute();
            $this->forgetRememberedLogins();

            return true;
        }

        return false;
    }

    /**
     * Send an email to the user containing the activation link
     *
     * @return void
     */
    public function sendActivationEmail()
    {
        $url = Config::appUrl() . '/signup/activate/' . $this->activation_token;

        $text = View::getTemplate('Signup/activation_email.txt', ['url' => $url, 'name' => $this->name]);
        $html = View::getTemplate('Signup/activation_email.html', ['url' => $url, 'name' => $this->name]);

        Mail::send($this->email, 'Activate your MapChat account', $text, $html);
    }

    /**
     * Send password reset instructions in an email to the user
     *
     * @return void
     */
    protected function sendPasswordResetEmail()
    {
        $url = Config::appUrl() . '/password/reset/' . $this->password_reset_token;

        $text = View::getTemplate('Password/reset_email.txt', ['url' => $url, 'name' => $this->name]);
        $html = View::getTemplate('Password/reset_email.html', ['url' => $url, 'name' => $this->name]);

        Mail::send($this->email, 'Reset your MapChat password', $text, $html);
    }

    /**
     * Activate the user account with the specified activation token
     *
     * @param string $value Activation token from the URL
     *
     * @return boolean  False if the link was wrong or already used
     */
    public static function activate($value)
    {
        $token = new Token($value);
        $hashed_token = $token->getHash();

        $sql = 'UPDATE users
                SET is_active = 1,
                    activation_hash = null
                WHERE activation_hash = :hashed_token';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $stmt->bindValue(':hashed_token', $hashed_token, PDO::PARAM_STR);

        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Create a throwaway guest account, so visitors can try the chat without
     * signing up. Guests get a random name, an email address nobody can
     * receive mail at, and a password nobody knows.
     *
     * @return User The new guest
     */
    public static function createGuest()
    {
        static::deleteOldGuests();

        $sql = 'INSERT INTO users (name, email, password_hash, is_active, is_guest, guest_token_hash)
                VALUES (:name, :email, :password_hash, 1, 1, :guest_token_hash)';

        $db = static::getDB();
        $stmt = $db->prepare($sql);

        $token = new Token();

        $stmt->bindValue(':name', 'Guest ' . random_int(1000, 9999), PDO::PARAM_STR);
        $stmt->bindValue(':email', 'guest-' . bin2hex(random_bytes(8)) . '@guest.mapchat.local', PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), PDO::PARAM_STR);
        $stmt->bindValue(':guest_token_hash', $token->getHash(), PDO::PARAM_STR);
        $stmt->execute();

        $user = static::findByID($db->lastInsertId());
        $user->guest_token = $token->getValue();

        return $user;
    }

    /**
     * The guest account a browser started earlier, if it still exists, so a
     * returning guest gets their name and messages back
     *
     * @param string $value The token from the guest's cookie
     *
     * @return mixed User object if found, false otherwise
     */
    public static function findGuestByToken($value)
    {
        if ($value === '') {
            return false;
        }

        $token = new Token($value);

        $stmt = static::getDB()->prepare('SELECT * FROM users
                                          WHERE guest_token_hash = :hash AND is_guest = 1
                                            AND created_at > NOW() - INTERVAL ' . static::GUEST_LIFETIME_HOURS . ' HOUR');
        $stmt->bindValue(':hash', $token->getHash(), PDO::PARAM_STR);
        $stmt->setFetchMode(PDO::FETCH_CLASS, static::class);
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * When this guest account is deleted
     *
     * @return int Unix time
     */
    public function guestExpiresAt()
    {
        return strtotime($this->created_at . ' UTC') + static::GUEST_LIFETIME_HOURS * 3600;
    }

    /**
     * How long a guest account lasts before it is deleted, with its messages
     */
    const GUEST_LIFETIME_HOURS = 24;

    /**
     * Hours until this guest account is deleted, rounded up
     *
     * @return int
     */
    public function guestHoursLeft()
    {
        $secondsLeft = strtotime($this->created_at) + static::GUEST_LIFETIME_HOURS * 3600 - time();

        return max(1, (int) ceil($secondsLeft / 3600));
    }

    /**
     * Delete guest accounts older than GUEST_LIFETIME_HOURS. Their messages
     * and location go with them (the chats and userinfo tables cascade on
     * delete). Runs whenever a new guest signs in, so no scheduled job is
     * needed. Guests who set a password are no longer guests and are kept.
     *
     * @return void
     */
    public static function deleteOldGuests()
    {
        $db = static::getDB();
        $old = 'SELECT id FROM users
                WHERE is_guest = 1
                  AND created_at < NOW() - INTERVAL ' . (int) static::GUEST_LIFETIME_HOURS . ' HOUR';

        $db->exec('DELETE FROM remembered_logins WHERE user_id IN (SELECT id FROM (' . $old . ') AS old_guests)');
        $db->exec('DELETE FROM users WHERE id IN (SELECT id FROM (' . $old . ') AS old_guests)');
    }

    /**
     * Turn showing this user's pin and city on or off
     *
     * @param bool $share
     *
     * @return bool True if saved
     */
    public function setLocationSharing($share)
    {
        $sql = 'UPDATE users SET share_location = :share WHERE id = :id';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':share', $share ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Update the user's profile
     *
     * @param array $data Data from the edit profile form
     *
     * @return boolean  True if the data was updated, false otherwise
     */
    public function updateProfile($data)
    {
        $this->name = $data['name'];
        $this->email = $data['email'];

        // Only validate and update the password if a value provided
        if ($data['password'] != '') {
            $this->password = $data['password'];
        }

        $this->validate();

        if (empty($this->errors)) {
            $sql = 'UPDATE users
                    SET name = :name,
                        email = :email';

            // Add password if it's set. A guest who sets their own email and
            // password turns the guest account into a normal one.
            if (isset($this->password)) {
                $sql .= ', password_hash = :password_hash, is_guest = 0';
            }


            $sql .= "\nWHERE id = :id";


            $db = static::getDB();
            $stmt = $db->prepare($sql);

            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

            // Add password if it's set
            if (isset($this->password)) {
                $password_hash = password_hash($this->password, PASSWORD_DEFAULT);
                $stmt->bindValue(':password_hash', $password_hash, PDO::PARAM_STR);
            }

            $stmt->execute();

            if (isset($this->password)) {
                $this->forgetRememberedLogins();
            }

            return true;
        }

        return false;
    }

    /**
     * Set the user's photo (null for the letter avatar). Only our own
     * Cloudinary uploads are accepted
     *
     * @param string|null $avatar
     *
     * @return boolean  True if the photo was saved
     */
    public function updateAvatar($avatar)
    {
        $uploads = 'https://res.cloudinary.com/' . Config::cloudinaryCloudName() . '/';

        if ($avatar !== null && (!str_starts_with($avatar, $uploads) || strlen($avatar) > 2083)) {
            return false;
        }

        $stmt = static::getDB()->prepare('UPDATE users SET avatar = :avatar WHERE id = :id');
        $stmt->bindValue(':avatar', $avatar, PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Log out every remembered device (after a password change)
     *
     * @return void
     */
    protected function forgetRememberedLogins()
    {
        $stmt = static::getDB()->prepare('DELETE FROM remembered_logins WHERE user_id = :id');
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
