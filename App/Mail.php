<?php

namespace App;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
     * Send a message. Does nothing when email is not configured. A failed
     * send is logged instead of thrown, so the page still loads.
     *
     * @param string $to Recipient
     * @param string $subject Subject
     * @param string $text Text-only content of the message
     * @param string $html HTML content of the message
     *
     * @return boolean True if the message was handed to the mail service
     */
    public static function send($to, $subject, $text, $html)
    {
        if (!Config::mailEnabled()) {
            return false;
        }

        $mailer = new Mailer(Transport::fromDsn(Config::get('MAILER_DSN')));

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
