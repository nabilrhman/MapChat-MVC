<?php

namespace Tests;

use App\Google;
use App\GoogleAccount;
use App\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Signing in with Google: which ID tokens are trusted, and which account
 * each Google account gets. Each test runs in a transaction that is rolled
 * back
 */
class GoogleSignInTest extends TestCase
{
    const CLIENT_ID = 'mapchat-test.apps.googleusercontent.com';
    const NONCE = 'the-nonce-this-browser-kept';
    const NOW = 1_800_000_000;

    private PDO $db;
    private string|false $clientIdBefore;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();
        $this->clientIdBefore = getenv('GOOGLE_CLIENT_ID');
        putenv('GOOGLE_CLIENT_ID=' . self::CLIENT_ID);
    }

    protected function tearDown(): void
    {
        putenv($this->clientIdBefore === false ? 'GOOGLE_CLIENT_ID' : 'GOOGLE_CLIENT_ID=' . $this->clientIdBefore);
        $this->db->rollBack();
    }

    /**
     * An ID token as Google's token endpoint sends it, with these claims
     * changed. Its signature is never read, so it's a placeholder
     *
     * @param array<string, mixed> $changes Claims to set; null removes one
     */
    private function idToken(array $changes = []): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '110248495921238986420',
            'email' => 'ann@gmail.test',
            'email_verified' => true,
            'name' => 'Ann Lee',
            'nonce' => self::NONCE,
            'exp' => self::NOW + 3600,
        ], $changes);
        $claims = array_filter($claims, fn ($value) => $value !== null);

        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']) . '.' . $encode($claims) . '.signature';
    }

    /**
     * Who Google::accountFromIdToken says signed in with this token
     */
    private function accountFrom(string $idToken): ?GoogleAccount
    {
        return Google::accountFromIdToken($idToken, self::NONCE, self::NOW);
    }

    /**
     * An account as signing up with a password makes it
     *
     * @param bool $isEmailProven Whether its activation link was opened
     */
    private function passwordAccount(string $email, bool $isEmailProven): User
    {
        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active, activation_hash)
                            VALUES ('Ann', ?, ?, ?, ?)")
            ->execute([$email, password_hash('secret123', PASSWORD_DEFAULT), (int) $isEmailProven,
                       $isEmailProven ? null : str_repeat('a', 64)]);

        return User::findByEmail($email);
    }

    public function testAGoodIdTokenSaysWhoSignedIn()
    {
        $account = $this->accountFrom($this->idToken());

        $this->assertEquals(new GoogleAccount('110248495921238986420', 'ann@gmail.test', 'Ann Lee'), $account);
    }

    public function testAnIdTokenIsRefusedUnlessItIsForThisSiteThisSignInAndStillGood()
    {
        $refused = [
            'another site' => ['aud' => 'someone-else.apps.googleusercontent.com'],
            'not Google' => ['iss' => 'https://accounts.example.com'],
            'expired' => ['exp' => self::NOW],
            'another sign-in' => ['nonce' => 'someone-elses-nonce'],
            'no nonce' => ['nonce' => null],
            'unchecked email' => ['email_verified' => false],
            'no email' => ['email' => null],
            'no id' => ['sub' => null],
        ];

        foreach ($refused as $why => $changes) {
            $this->assertNull($this->accountFrom($this->idToken($changes)), $why);
        }
        $this->assertNull($this->accountFrom('not.a-token'));
        $this->assertNull($this->accountFrom(''));
    }

    public function testTheCodeChallengeIsTheOneInThePkceStandard()
    {
        // RFC 7636, appendix B
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Google::codeChallengeOf('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function testTheFirstGoogleSignInMakesAnActiveAccountWithNoPassword()
    {
        $user = User::signInWithGoogle(new GoogleAccount('google-1', 'new@gmail.test', 'New Person'));

        $this->assertSame('New Person', $user->name);
        $this->assertSame('new@gmail.test', $user->email);
        $this->assertSame('google-1', $user->google_id);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->hasPassword());
        $this->assertNull(User::authenticate('new@gmail.test', ''));
    }

    public function testSomeoneWithNoNameOnGoogleGoesByTheirEmailsFirstPart()
    {
        $user = User::signInWithGoogle(new GoogleAccount('google-2', 'quiet.one@gmail.test', ''));

        $this->assertSame('quiet.one', $user->name);
    }

    public function testSigningInAgainGetsTheSameAccountEvenAfterItsEmailChanged()
    {
        $first = User::signInWithGoogle(new GoogleAccount('google-3', 'old@gmail.test', 'Ann'));
        $this->db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute(['changed@example.test', $first->id]);

        $again = User::signInWithGoogle(new GoogleAccount('google-3', 'old@gmail.test', 'Ann'));

        $this->assertSame($first->id, $again->id);
    }

    public function testAnAccountWithTheSameEmailIsLinkedAndKeepsItsPassword()
    {
        $existing = $this->passwordAccount('ann@gmail.test', isEmailProven: true);

        $user = User::signInWithGoogle(new GoogleAccount('google-4', 'ann@gmail.test', 'Ann Lee'));

        $this->assertSame($existing->id, $user->id);
        $this->assertSame('google-4', User::findByID($existing->id)->google_id);
        $this->assertNotNull(User::authenticate('ann@gmail.test', 'secret123'));
    }

    public function testAnUnactivatedAccountWithTheSameEmailLosesThePasswordSomeoneElseMayHaveSet()
    {
        $existing = $this->passwordAccount('ann@gmail.test', isEmailProven: false);

        User::signInWithGoogle(new GoogleAccount('google-5', 'ann@gmail.test', 'Ann Lee'));

        $linked = User::findByID($existing->id);
        $this->assertTrue($linked->is_active);
        $this->assertNull($linked->activation_hash);
        $this->assertFalse($linked->hasPassword());
        $this->assertNull(User::authenticate('ann@gmail.test', 'secret123'));
    }
}
