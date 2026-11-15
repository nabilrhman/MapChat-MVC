<?php

namespace App;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Mail
 *
 * Sends through whatever MAILER_DSN points at, e.g. any provider's SMTP:
 * smtp://user:pass@smtp.example.com:587
 * or Brevo's HTTPS API, for hosts that block SMTP ports (like Render's free plan):
 * brevo+api://API_KEY@default
 */
class Mail
{
    /**
     * Send a message. Does nothing when email is not configured.
     *
     * @param string $to Recipient
     * @param string $subject Subject
     * @param string $text Text-only content of the message
     * @param string $html HTML content of the message
     *
     * @return void
     */
    public static function send($to, $subject, $text, $html)
    {
        if (!Config::mailEnabled()) {
            return;
        }

        $mailer = new Mailer(Transport::fromDsn(Config::get('MAILER_DSN')));

        $email = (new Email())
            ->from(Config::get('MAIL_FROM', 'MapChat <no-reply@localhost>'))
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);

        $mailer->send($email);
    }
}
