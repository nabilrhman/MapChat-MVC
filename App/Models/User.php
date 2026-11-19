<?php

namespace App\Models;

use App\Config;
use App\Notify\Mail;
use App\PersonName;
use App\SignIn\GoogleAccount;
use App\SignIn\GoogleSignIn;
use App\SignIn\Token;
use Core\View;
use PDO;

/**
 * A person's account: signing up, logging in, their profile, and the
 * throwaway guest accounts
 */
class User extends \Core\Model
{
    /** How long a guest account lasts before it is deleted, with its messages */
    const GUEST_LIFETIME_HOURS = 24;

    /** How long a password reset link works, in seconds */
    const RESET_SECONDS = 60 * 60 * 2;

    /** The longest name, in characters */
    const MAX_NAME_LENGTH = 50;

    /** Wrong passwords in a row that stop any more tries for a while */
    const MAX_FAILED_LOGINS = 10;

    /** How long a run of wrong passwords counts, and stops tries once too long */
    const FAILED_LOGIN_MINUTES = 15;

    /** The domain of the demo people, the guests and MapChat's bot, so nobody signs up with it */
    const RESERVED_EMAIL_DOMAIN = 'mapchat.local';

    /** The columns, as the database has them */
    public ?int $id = null;
    public string $name = '';
    public ?string $nickname = null;
    public string $email = '';
    public string $password_hash = '';
    public ?string $password_reset_hash = null;
    /** UTC, Y-m-d H:i:s */
    public ?string $password_reset_expires_at = null;
    public ?string $activation_hash = null;
    public bool $is_active = false;
    public ?string $avatar = null;
    public bool $is_guest = false;
    public ?string $guest_token_hash = null;
    public bool $share_location = true;
    /** Google's id for the account it signs in with, or null for none */
    public ?string $google_id = null;
    /** Removed their photo, so Google's isn't put back at sign-in */
    public bool $google_photo_declined = false;
    /** UTC, Y-m-d H:i:s */
    public ?string $created_at = null;
    /** Wrong passwords since failed_logins_since */
    public int $failed_logins = 0;
    /** UTC, Y-m-d H:i:s: when the run of wrong passwords began, or null for none */
    public ?string $failed_logins_since = null;

    /** A new password, typed into a form; only its hash is stored */
    public ?string $password = null;

    /** Tokens for the links and cookie just made; only their hashes are stored */
    public ?string $activationToken = null;
    public ?string $passwordResetToken = null;
    public ?string $guestToken = null;

    /**
     * Why the last save didn't happen, as full sentences by field
     *
     * @var array<string, string[]> E.g. ['email' => ['That email is already registered.']]
     */
    public array $errors = [];

    /**
     * Save a new account, unless its details aren't valid (see errors)
     */
    public function save(): bool
    {
        $this->validate();
        if ($this->errors) {
            return false;
        }

        $token = new Token();
        $this->activationToken = $token->value;

        $sql = 'INSERT INTO users (name, email, password_hash, activation_hash, is_active)
                VALUES (:name, :email, :password_hash, :activation_hash, :is_active)';

        $db = static::getDB();
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
        $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', password_hash($this->password, PASSWORD_DEFAULT), PDO::PARAM_STR);
        $stmt->bindValue(':activation_hash', $token->getHash(), PDO::PARAM_STR);
        // Without email there is no way to send an activation link, so
        // accounts start active
        $stmt->bindValue(':is_active', !Config::isMailEnabled(), PDO::PARAM_BOOL);
        $stmt->execute();

        $this->id = (int) $db->lastInsertId();

        return true;
    }

    /**
     * Delete this account for good, e.g. a sign-up whose activation email
     * never went out, or someone deleting their profile. Their messages, the
     * private ones to them, their place, push subscriptions and MapChat's
     * jobs for them go with it (those tables cascade on delete); remembered
     * logins point at the id instead, so they're deleted here
     */
    public function delete(): void
    {
        $this->forgetRememberedLogins();

        $stmt = static::getDB()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Check the name, nickname, email and any new password, noting each
     * problem in errors
     */
    public function validate(): void
    {
        $this->errors = [];

        $this->name = trim($this->name);
        if ($this->name === '') {
            $this->errors['name'][] = 'Enter your name.';
        } elseif (mb_strlen($this->name) > static::MAX_NAME_LENGTH) {
            $this->errors['name'][] = 'Your name can be ' . static::MAX_NAME_LENGTH . ' characters at most.';
        }

        // Optional, one word, as people type it after an @
        $this->nickname = trim($this->nickname ?? '');
        if ($this->nickname !== '') {
            if (!preg_match('/^[\p{L}\p{N}_.-]{2,20}$/u', $this->nickname)) {
                $this->errors['nickname'][] = 'A nickname is 2 to 20 letters, numbers, _ . or -, with no spaces.';
            } elseif (mb_strtolower($this->nickname) === mb_strtolower(Bot::NAME)
                      || static::isNicknameTaken($this->nickname, $this->id)) {
                $this->errors['nickname'][] = 'That nickname is taken.';
            }
        }

        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors['email'][] = 'Enter a valid email.';
        } elseif (static::isReservedEmail($this->email) && !$this->isOwnSavedEmail()) {
            $this->errors['email'][] = 'That email belongs to MapChat. Use your own.';
        } elseif (static::isEmailTaken($this->email, $this->id)) {
            $this->errors['email'][] = 'That email is already registered.';
        }

        // One line for every rule, as the hint under the field says it
        $isPasswordWeak = $this->password !== null
            && (strlen($this->password) < 6 || !preg_match('/[a-z]/i', $this->password) || !preg_match('/\d/', $this->password));
        if ($isPasswordWeak) {
            $this->errors['password'][] = 'Use 6 or more characters, with a letter and a number.';
        }
    }

    /**
     * Whether someone else already goes by this nickname, in any case
     *
     * @param int|null $ignoreId Your own id, so keeping yours isn't a clash
     */
    public static function isNicknameTaken(string $nickname, ?int $ignoreId = null): bool
    {
        $stmt = static::getDB()->prepare('SELECT COUNT(*) FROM users WHERE nickname = :nickname AND id <> :id');
        $stmt->bindValue(':nickname', $nickname, PDO::PARAM_STR);
        $stmt->bindValue(':id', $ignoreId ?? 0, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Whether the email is at MapChat's own domain or under it, as the demo
     * people's, the guests' and the bot's are
     */
    public static function isReservedEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain === static::RESERVED_EMAIL_DOMAIN || str_ends_with($domain, '.' . static::RESERVED_EMAIL_DOMAIN);
    }

    /**
     * Whether the email is the one this account has saved now, as a guest's
     * placeholder is, so keeping it isn't refused
     */
    protected function isOwnSavedEmail(): bool
    {
        return $this->id !== null && static::findByEmail($this->email)?->id === $this->id;
    }

    /**
     * Whether someone else already has an account with this email
     *
     * @param int|null $ignoreId Your own id, so keeping yours isn't a clash
     */
    public static function isEmailTaken(string $email, ?int $ignoreId = null): bool
    {
        $user = static::findByEmail($email);

        return $user !== null && $user->id !== $ignoreId;
    }

    /**
     * The account with this email, if any
     */
    public static function findByEmail(string $email): ?static
    {
        return static::findWhere('email = :email', [':email' => $email]);
    }

    /**
     * The account with this id, if any
     */
    public static function findByID(int $id): ?static
    {
        return static::findWhere('id = :id', [':id' => $id]);
    }

    /**
     * The account this Google account signs in to, if any
     *
     * @param string $googleId Google's id for the person ("sub")
     */
    public static function findByGoogleId(string $googleId): ?static
    {
        return static::findWhere('google_id = :google_id', [':google_id' => $googleId]);
    }

    /**
     * The account a password reset link is for, while the link still works
     *
     * @param string $token The token from the link
     */
    public static function findByPasswordReset(string $token): ?static
    {
        return static::findWhere('password_reset_hash = :hash AND password_reset_expires_at > NOW()', [
            ':hash' => (new Token($token))->getHash(),
        ]);
    }

    /**
     * The guest account a browser started earlier, if it still exists, so a
     * returning guest gets their name and messages back
     *
     * @param string $token The token from the guest's cookie
     */
    public static function findGuestByToken(string $token): ?static
    {
        if ($token === '') {
            return null;
        }

        return static::findWhere('guest_token_hash = :hash AND is_guest = 1 AND NOT (' . static::expiredGuestSql() . ')', [
            ':hash' => (new Token($token))->getHash(),
        ]);
    }

    /**
     * The one account that matches a condition, if any
     *
     * @param string $condition SQL for the WHERE clause, with placeholders
     * @param array<string, int|string> $params The placeholders' values
     */
    protected static function findWhere(string $condition, array $params): ?static
    {
        $stmt = static::getDB()->prepare('SELECT * FROM users WHERE ' . $condition);
        $stmt->setFetchMode(PDO::FETCH_CLASS, static::class);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    /**
     * The account with this email and password, if they match. A wrong
     * password is counted, and after MAX_FAILED_LOGINS of them no password
     * is let in until FAILED_LOGIN_MINUTES pass, so it can't be guessed
     */
    public static function authenticate(string $email, string $password): ?static
    {
        $user = static::findByEmail($email);
        if (!$user || $user->isLoginLocked()) {
            return null;
        }

        if (!password_verify($password, $user->password_hash)) {
            $user->countFailedLogin();
            return null;
        }

        if ($user->failed_logins > 0) {
            $user->forgetFailedLogins();
        }

        return $user;
    }

    /**
     * Whether MAX_FAILED_LOGINS wrong passwords in the last
     * FAILED_LOGIN_MINUTES stop any more tries for now
     */
    public function isLoginLocked(): bool
    {
        if ($this->failed_logins < static::MAX_FAILED_LOGINS || $this->failed_logins_since === null) {
            return false;
        }

        $lockedUntil = strtotime($this->failed_logins_since . ' UTC') + static::FAILED_LOGIN_MINUTES * 60;

        return time() < $lockedUntil;
    }

    /**
     * Count a wrong password, starting a new run if the last one began
     * FAILED_LOGIN_MINUTES ago or more
     */
    protected function countFailedLogin(): void
    {
        // In SQL, so tries sent at the same time are each counted. The run's
        // start is set last, as MySQL sets columns left to right
        $isRunGoingSql = 'failed_logins_since > NOW() - INTERVAL ' . static::FAILED_LOGIN_MINUTES . ' MINUTE';
        $sql = "UPDATE users
                SET failed_logins = IF($isRunGoingSql, failed_logins + 1, 1),
                    failed_logins_since = IF($isRunGoingSql, failed_logins_since, NOW())
                WHERE id = :id";

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Start the count of wrong passwords again, after the right one
     */
    protected function forgetFailedLogins(): void
    {
        $stmt = static::getDB()->prepare('UPDATE users SET failed_logins = 0, failed_logins_since = NULL WHERE id = :id');
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();

        $this->failed_logins = 0;
        $this->failed_logins_since = null;
    }

    /**
     * A hash of what signs in to this account, its password and its Google
     * link. Each session keeps it from when it logged in, and stops working
     * once either changes (see Auth::getUser)
     */
    public function signInStamp(): string
    {
        return hash('sha256', $this->password_hash . '|' . ($this->google_id ?? ''));
    }

    /**
     * Whether the account has a password to log in with. One made by
     * signing in with Google has none until it sets one
     */
    public function hasPassword(): bool
    {
        return $this->password_hash !== '';
    }

    /**
     * Whether the account has a password its owner knows, to ask for before
     * deleting it. A guest's is random, made so nobody can log in with it
     */
    public function hasKnownPassword(): bool
    {
        return !$this->is_guest && $this->hasPassword();
    }

    /**
     * The account someone signing in with Google gets: the one already
     * linked to their Google account, else the one with their email, now
     * linked, else a new one
     */
    public static function signInWithGoogle(GoogleAccount $google): static
    {
        $user = static::findByGoogleId($google->id);
        if (!$user) {
            $user = static::findByEmail($google->email);
            $user?->linkGoogle($google);
        }
        $user ??= static::createFromGoogle($google);

        $user->takeGooglePhoto($google->picture);
        return $user;
    }

    /**
     * Show their Google photo, or its newer version, unless they uploaded
     * their own or removed their photo
     */
    protected function takeGooglePhoto(?string $picture): void
    {
        $hasOwnPhoto = $this->avatar !== null && !GoogleSignIn::isPhoto($this->avatar);
        if ($picture === null || $picture === $this->avatar || $hasOwnPhoto || $this->google_photo_declined) {
            return;
        }

        $stmt = static::getDB()->prepare('UPDATE users SET avatar = :avatar WHERE id = :id');
        $stmt->bindValue(':avatar', $picture, PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        $this->avatar = $picture;
    }

    /**
     * Link this account to a Google account with its email. Google checked
     * that email belongs to them; if this account never proved it did, a
     * password someone else set at sign-up or by changing their email to
     * it mustn't keep working, nor their sessions (the changed signInStamp
     * ends those)
     */
    protected function linkGoogle(GoogleAccount $google): void
    {
        $isEmailUnproven = $this->activation_hash !== null;

        $sql = 'UPDATE users SET google_id = :google_id, is_active = 1, activation_hash = NULL';
        if ($isEmailUnproven) {
            $sql .= ", password_hash = ''";
        }
        $sql .= ' WHERE id = :id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':google_id', $google->id, PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();

        $this->google_id = $google->id;
        $this->is_active = true;
        $this->activation_hash = null;

        if ($isEmailUnproven) {
            $this->password_hash = '';
            $this->forgetRememberedLogins();
        }
    }

    /**
     * A new, active account for someone signing in with Google, with their
     * name there and no password
     */
    protected static function createFromGoogle(GoogleAccount $google): static
    {
        // Someone who shares no name on Google goes by their email's first part
        $name = $google->name !== '' ? $google->name : strstr($google->email, '@', before_needle: true);

        $db = static::getDB();
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, is_active, google_id)
                              VALUES (:name, :email, '', 1, :google_id)");
        $stmt->bindValue(':name', mb_substr($name, 0, static::MAX_NAME_LENGTH), PDO::PARAM_STR);
        $stmt->bindValue(':email', $google->email, PDO::PARAM_STR);
        $stmt->bindValue(':google_id', $google->id, PDO::PARAM_STR);
        $stmt->execute();

        return static::findByID((int) $db->lastInsertId());
    }

    /**
     * The people "@" and their name or shown name (PersonName::shown)
     * appears for in the text
     *
     * @return PersonName[]
     */
    public static function mentionedIn(string $text): array
    {
        // LOCATE, not LIKE, so a name with % or _ in it is taken as typed
        $sql = 'SELECT DISTINCT name, nickname, is_guest FROM users
                WHERE LOCATE(CONCAT(\'@\', name), :text) > 0
                   OR LOCATE(CONCAT(\'@\', nickname), :text2) > 0
                   OR (nickname IS NULL AND NOT is_guest
                       AND LOCATE(CONCAT(\'@\', SUBSTRING_INDEX(name, \' \', 1)), :text3) > 0)';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':text', $text, PDO::PARAM_STR);
        $stmt->bindValue(':text2', $text, PDO::PARAM_STR);
        $stmt->bindValue(':text3', $text, PDO::PARAM_STR);
        $stmt->execute();
        $people = static::typedRows($stmt->fetchAll(PDO::FETCH_ASSOC), boolColumns: ['is_guest']);

        return array_map(PersonName::fromRow(...), $people);
    }

    /**
     * Email a password reset link to the account with this email, if there
     * is one
     *
     * @return bool False only when there is an account and its email
     *              couldn't be sent, so the answer never says which emails
     *              have an account
     */
    public static function sendPasswordReset(string $email): bool
    {
        $user = static::findByEmail($email);
        if (!$user) {
            return true;
        }

        $user->startPasswordReset();

        return $user->sendPasswordResetEmail();
    }

    /**
     * Make a new password reset token, good for RESET_SECONDS
     */
    protected function startPasswordReset(): void
    {
        $token = new Token();
        $this->passwordResetToken = $token->value;

        $stmt = static::getDB()->prepare('UPDATE users
                                          SET password_reset_hash = :token_hash, password_reset_expires_at = :expires_at
                                          WHERE id = :id');
        $stmt->bindValue(':token_hash', $token->getHash(), PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', gmdate('Y-m-d H:i:s', time() + static::RESET_SECONDS), PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Set a new password, unless it isn't valid (see errors), and log out
     * every browser logged in with the old one
     */
    public function resetPassword(string $password): bool
    {
        $this->password = $password;

        $this->validate();
        if ($this->errors) {
            return false;
        }

        $stmt = static::getDB()->prepare('UPDATE users
                                          SET password_hash = :password_hash,
                                              password_reset_hash = NULL,
                                              password_reset_expires_at = NULL,
                                              failed_logins = 0,
                                              failed_logins_since = NULL
                                          WHERE id = :id');
        $this->password_hash = password_hash($this->password, PASSWORD_DEFAULT);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->bindValue(':password_hash', $this->password_hash, PDO::PARAM_STR);
        $stmt->execute();

        // Sessions made before end at their next request, by the changed signInStamp
        $this->forgetRememberedLogins();

        return true;
    }

    /**
     * Email the activation link
     *
     * @return bool Whether the mail service took it
     */
    public function sendActivationEmail(): bool
    {
        $url = Config::appUrl() . '/signup/activate/' . $this->activationToken;

        $text = View::getTemplate('Signup/activation_email.txt', ['url' => $url, 'name' => $this->name]);
        $html = View::getTemplate('Signup/activation_email.html', ['url' => $url, 'name' => $this->name]);

        return Mail::send($this->email, 'Activate your MapChat account', $text, $html);
    }

    /**
     * Email a changed email the link that proves it's theirs, the same link
     * activation uses
     *
     * @return bool Whether the mail service took it
     */
    public function sendNewEmailConfirmation(): bool
    {
        $url = Config::appUrl() . '/signup/activate/' . $this->activationToken;

        $text = View::getTemplate('Profile/new_email.txt', ['url' => $url, 'name' => $this->name]);
        $html = View::getTemplate('Profile/new_email.html', ['url' => $url, 'name' => $this->name]);

        return Mail::send($this->email, 'Confirm your new MapChat email', $text, $html);
    }

    /**
     * Email the password reset link
     *
     * @return bool Whether the mail service took it
     */
    protected function sendPasswordResetEmail(): bool
    {
        $url = Config::appUrl() . '/password/reset/' . $this->passwordResetToken;

        $text = View::getTemplate('Password/reset_email.txt', ['url' => $url, 'name' => $this->name]);
        $html = View::getTemplate('Password/reset_email.html', ['url' => $url, 'name' => $this->name]);

        return Mail::send($this->email, 'Reset your MapChat password', $text, $html);
    }

    /**
     * Activate the account an activation link is for
     *
     * @param string $token The token from the link
     *
     * @return bool False if the link was wrong or already used
     */
    public static function activate(string $token): bool
    {
        $stmt = static::getDB()->prepare('UPDATE users SET is_active = 1, activation_hash = NULL WHERE activation_hash = :hash');
        $stmt->bindValue(':hash', (new Token($token))->getHash(), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Create a throwaway guest account, so visitors can try the chat without
     * signing up. Guests get a random name, an email address nobody can
     * receive mail at, and a password nobody knows
     */
    public static function createGuest(): static
    {
        static::deleteExpiredGuests();

        $token = new Token();

        $db = static::getDB();
        $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, is_active, is_guest, guest_token_hash)
                              VALUES (:name, :email, :password_hash, 1, 1, :guest_token_hash)');
        $stmt->bindValue(':name', 'Guest ' . random_int(1000, 9999), PDO::PARAM_STR);
        $stmt->bindValue(':email', 'guest-' . bin2hex(random_bytes(8)) . '@guest.mapchat.local', PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), PDO::PARAM_STR);
        $stmt->bindValue(':guest_token_hash', $token->getHash(), PDO::PARAM_STR);
        $stmt->execute();

        $guest = static::findByID((int) $db->lastInsertId());
        $guest->guestToken = $token->value;

        return $guest;
    }

    /**
     * When this guest account is deleted
     *
     * @return int Unix time
     */
    public function guestExpiresAt(): int
    {
        return strtotime($this->created_at . ' UTC') + static::GUEST_LIFETIME_HOURS * 3600;
    }

    /**
     * Hours until this guest account is deleted, rounded up
     */
    public function guestHoursLeft(): int
    {
        $secondsLeft = $this->guestExpiresAt() - time();

        return max(1, (int) ceil($secondsLeft / 3600));
    }

    /**
     * Whether this is a guest account whose GUEST_LIFETIME_HOURS are up. It
     * stops working at once, before the chat worker deletes it
     */
    public function isExpiredGuest(): bool
    {
        return $this->is_guest && $this->guestExpiresAt() <= time();
    }

    /**
     * SQL that's true for guest accounts whose GUEST_LIFETIME_HOURS are up
     *
     * @param string $table The users table's name or alias in the query
     */
    public static function expiredGuestSql(string $table = 'users'): string
    {
        return "$table.is_guest = 1 AND $table.created_at <= NOW() - INTERVAL " . static::GUEST_LIFETIME_HOURS . ' HOUR';
    }

    /**
     * Delete guest accounts whose GUEST_LIFETIME_HOURS are up. Their
     * messages and location go with them (the chats and userinfo tables
     * cascade on delete). The chat worker runs this regularly, and so does
     * each new guest. Guests who set a password are no longer guests and are
     * kept
     */
    public static function deleteExpiredGuests(): void
    {
        $db = static::getDB();
        $expired = 'SELECT id FROM (SELECT id FROM users WHERE ' . static::expiredGuestSql() . ') AS expired_guests';

        $db->exec("DELETE FROM remembered_logins WHERE user_id IN ($expired)");
        $db->exec("DELETE FROM users WHERE id IN ($expired)");
    }

    /**
     * Turn showing this user's pin and city on or off
     */
    public function setLocationSharing(bool $isShared): void
    {
        $stmt = static::getDB()->prepare('UPDATE users SET share_location = :share WHERE id = :id');
        $stmt->bindValue(':share', $isShared, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();

        $this->share_location = $isShared;
    }

    /**
     * Save the profile form, unless it isn't valid (see errors). A new
     * password logs out every other browser, and a guest who sets one keeps
     * their account. A new email counts as unproven until its activation
     * link is opened (activationToken is set for it), so signing in with
     * Google as that email can't keep this account's password
     *
     * @param array{name: string, nickname: string, email: string, password: string} $data
     *        An empty password keeps the current one
     */
    public function updateProfile(array $data): bool
    {
        $hasNewEmail = $data['email'] !== $this->email;

        $this->name = $data['name'];
        $this->nickname = $data['nickname'];
        $this->email = $data['email'];
        $this->password = $data['password'] === '' ? null : $data['password'];

        $this->validate();
        if ($this->errors) {
            return false;
        }

        $hasNewPassword = $this->password !== null;

        $sql = 'UPDATE users SET name = :name, nickname = :nickname, email = :email';
        if ($hasNewPassword) {
            $sql .= ', password_hash = :password_hash, is_guest = 0';
        }
        if ($hasNewEmail) {
            $sql .= ', activation_hash = :activation_hash';
        }
        $sql .= ' WHERE id = :id';

        $stmt = static::getDB()->prepare($sql);
        $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
        // Empty means none, so it doesn't clash with anyone else's empty one
        $stmt->bindValue(':nickname', $this->nickname === '' ? null : $this->nickname, PDO::PARAM_STR);
        $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        if ($hasNewPassword) {
            $this->password_hash = password_hash($this->password, PASSWORD_DEFAULT);
            $stmt->bindValue(':password_hash', $this->password_hash, PDO::PARAM_STR);
        }
        if ($hasNewEmail) {
            $token = new Token();
            $this->activationToken = $token->value;
            $this->activation_hash = $token->getHash();
            $stmt->bindValue(':activation_hash', $this->activation_hash, PDO::PARAM_STR);
        }
        $stmt->execute();

        if ($hasNewPassword) {
            $this->forgetRememberedLogins();
        }

        return true;
    }

    /**
     * Set the user's photo, or null for the letter avatar. Only our own
     * Cloudinary uploads are accepted; Google's photo comes at sign-in
     *
     * @return bool Whether it was saved
     */
    public function updateAvatar(?string $avatar): bool
    {
        $uploads = 'https://res.cloudinary.com/' . Config::cloudinaryCloudName() . '/';

        if ($avatar !== null && (!str_starts_with($avatar, $uploads) || strlen($avatar) > 2083)) {
            return false;
        }

        // Removing a photo means the letters, even after signing in with Google again
        $sql = $avatar === null
            ? 'UPDATE users SET avatar = NULL, google_photo_declined = TRUE WHERE id = :id'
            : 'UPDATE users SET avatar = :avatar WHERE id = :id';
        $stmt = static::getDB()->prepare($sql);
        if ($avatar !== null) {
            $stmt->bindValue(':avatar', $avatar, PDO::PARAM_STR);
        }
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Log out every remembered browser: on a password change, on deleting
     * the account, or when someone else proves the email is theirs
     */
    protected function forgetRememberedLogins(): void
    {
        $stmt = static::getDB()->prepare('DELETE FROM remembered_logins WHERE user_id = :id');
        $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
