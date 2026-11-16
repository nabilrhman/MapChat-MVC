<?php

namespace App;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Signing in with Google (OpenID Connect): the address that sends someone
 * to Google, and reading who they are from the code Google sends them back
 * with. Off until GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are set
 */
class Google
{
    /** Where someone chooses their Google account */
    const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Where the code from the callback is swapped for an ID token */
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** The issuers Google's ID tokens name, with and without the scheme */
    const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** The longest the token request may take, in seconds */
    const TIMEOUT = 10;

    /**
     * Whether this site has a Google client to sign in with
     */
    public static function isEnabled(): bool
    {
        return Config::get('GOOGLE_CLIENT_ID') !== null && Config::get('GOOGLE_CLIENT_SECRET') !== null;
    }

    /**
     * Where Google sends people back to, as registered with the client
     */
    public static function callbackUrl(): string
    {
        return Config::appUrl() . '/login/google/callback';
    }

    /**
     * The address that asks Google who someone is
     *
     * @param string $state Comes back with the callback, to match it to this browser
     * @param string $nonce Comes back inside the ID token, to match it to this sign-in
     * @param string $codeVerifier Only its hash goes to Google; the token request proves we have it (PKCE)
     */
    public static function authorizeUrl(string $state, string $nonce, string $codeVerifier): string
    {
        return static::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => Config::get('GOOGLE_CLIENT_ID'),
            'redirect_uri' => static::callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => static::codeChallengeOf($codeVerifier),
            'code_challenge_method' => 'S256',
            // Let someone with several Google accounts pick one
            'prompt' => 'select_account',
        ]);
    }

    /**
     * The PKCE challenge for a verifier: its SHA-256, base64url without padding
     */
    public static function codeChallengeOf(string $codeVerifier): string
    {
        return static::base64UrlEncode(hash('sha256', $codeVerifier, binary: true));
    }

    /**
     * Who signed in, from the code Google's callback brought
     *
     * @return GoogleAccount|null Null when Google refused the code or its
     *                            answer can't be trusted
     */
    public static function fetchAccount(string $code, string $codeVerifier, string $nonce): ?GoogleAccount
    {
        try {
            $response = HttpClient::create()->request('POST', static::TOKEN_URL, [
                'body' => [
                    'code' => $code,
                    'client_id' => Config::get('GOOGLE_CLIENT_ID'),
                    'client_secret' => Config::get('GOOGLE_CLIENT_SECRET'),
                    'redirect_uri' => static::callbackUrl(),
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $codeVerifier,
                ],
                'max_duration' => static::TIMEOUT,
            ]);

            $idToken = $response->toArray()['id_token'] ?? '';
        } catch (ExceptionInterface $e) {
            error_log('Google sign-in failed: ' . $e->getMessage());
            return null;
        }

        return static::accountFromIdToken((string) $idToken, $nonce, time());
    }

    /**
     * Who an ID token says signed in, if it's for this site, still good,
     * for this sign-in, and its email is one Google checked. Its signature
     * isn't checked: it came straight from Google's token endpoint over
     * TLS, which OpenID Connect accepts in place of one (Core 3.1.3.7)
     *
     * @param int $now Unix time, to check it hasn't expired
     */
    public static function accountFromIdToken(string $idToken, string $nonce, int $now): ?GoogleAccount
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode(static::base64UrlDecode($parts[1]), true);
        if (!is_array($claims)) {
            return null;
        }

        $isForThisSignIn = in_array($claims['iss'] ?? '', static::ISSUERS, true)
            && ($claims['aud'] ?? '') === Config::get('GOOGLE_CLIENT_ID')
            && (int) ($claims['exp'] ?? 0) > $now
            && hash_equals($nonce, (string) ($claims['nonce'] ?? ''));

        // Google sends the flag as true, or in older tokens as "true"
        $isEmailChecked = in_array($claims['email_verified'] ?? false, [true, 'true'], true);

        $id = (string) ($claims['sub'] ?? '');
        $email = (string) ($claims['email'] ?? '');
        if (!$isForThisSignIn || !$isEmailChecked || $id === '' || $email === '') {
            return null;
        }

        return new GoogleAccount($id, $email, trim((string) ($claims['name'] ?? '')));
    }

    /**
     * Bytes as base64url without padding, as JWTs and PKCE use
     */
    protected static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Base64url without padding, decoded
     */
    protected static function base64UrlDecode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }
}
