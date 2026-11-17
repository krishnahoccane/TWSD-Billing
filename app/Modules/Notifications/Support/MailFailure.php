<?php

namespace App\Modules\Notifications\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an SMTP failure into a message an administrator can act on.
 * The SMTP password is never included.
 */
class MailFailure
{
    public static function describe(Throwable $e): string
    {
        $raw = (string) $e->getMessage();
        $host = (string) config('mail.mailers.smtp.host');
        $port = (string) config('mail.mailers.smtp.port');
        $from = (string) config('mail.from.address');

        $hint = match (true) {
            Str::contains($raw, ['Connection could not be established', 'Connection refused', 'timed out', 'Network is unreachable', 'getaddrinfo'], true) => "The mail server {$host}:{$port} could not be reached. Many shared hosts block outgoing SMTP — ask your host to allow it, or try port 2525 or 465 (smtps).",
            Str::contains($raw, ['535', 'Authentication', 'authenticate', 'Username and Password not accepted'], true) => 'The mail server rejected the SMTP username or password. For Brevo, the password is an SMTP key (Settings → SMTP & API), not your login password or API key.',
            Str::contains($raw, ['sender', 'Sender', '550', '553', '554'], true) => "The mail server refused the message from {$from}. Make sure this sender address or its domain is verified with your mail provider.",
            Str::contains($raw, ['certificate', 'SSL', 'TLS', 'crypto'], true) => 'The secure (TLS) connection to the mail server failed. Port 587 uses MAIL_SCHEME=smtp; port 465 uses MAIL_SCHEME=smtps.',
            default => 'The e-mail could not be sent.',
        };

        return $hint.' Server said: '.self::clean($raw);
    }

    private static function clean(string $message): string
    {
        $password = (string) config('mail.mailers.smtp.password');

        if ($password !== '') {
            $message = str_replace($password, '********', $message);
        }

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 300);
    }
}
