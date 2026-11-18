<?php

namespace App\Notify;

use App\Config;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Sends email through whatever MAILER_DSN points at, e.g. any provider's SMTP:
 * smtp://user:pass@smtp.example.com:587
 * or Brevo's HTTPS API, for hosts that block SMTP ports (like Render's free plan):
 * brevo+api://API_KEY@default
 */
class Mail
{
    /**
     * Send a message. Does nothing when email is not configured. A failed
     * send is logged instead of thrown, so the page still loads
     *
     * @param string $to The recipient's address
     * @param string $text The message as plain text
     * @param string $html The message as HTML
     *
     * @return bool Whether the mail service took it
     */
    public static function send(string $to, string $subject, string $text, string $html): bool
    {
        if (!Config::isMailEnabled()) {
            return false;
        }

        // A malformed or unsupported MAILER_DSN throws here. Only the error's
        // class is logged: the DSN can hold a password or API key
        try {
            $mailer = new Mailer(Transport::fromDsn(Config::get('MAILER_DSN')));
        } catch (MailerException $e) {
            error_log('Mail is not set up right, check MAILER_DSN: ' . get_class($e));
            return false;
        }

        $email = (new Email())
            ->from(Config::get('MAIL_FROM', 'MapChat <no-reply@localhost>'))
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            error_log('Mail to ' . $to . ' failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }
}
