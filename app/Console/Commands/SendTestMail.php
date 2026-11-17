<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Support\MailFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one test e-mail with the current .env settings and prints the mail
 * server's exact answer — for diagnosing password-reset and notification mail.
 */
class SendTestMail extends Command
{
    protected $signature = 'wcc:mail-test {to : Address that receives the test e-mail}';

    protected $description = 'Send a test e-mail using the configured mailer and show any SMTP error';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Enter a valid e-mail address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $this->line("Mailer: {$mailer}");

        if ($mailer === 'smtp') {
            $this->line(sprintf('Server: %s:%s (scheme %s), user %s, from %s',
                config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'),
                config('mail.mailers.smtp.scheme') ?: 'auto', config('mail.mailers.smtp.username') ?: '(none)', config('mail.from.address')));
        }

        try {
            Mail::raw('This is a test message from '.config('app.name').'. Your e-mail settings work.',
                fn ($message) => $message->to($to)->subject('Test e-mail from '.config('app.name')));
        } catch (Throwable $e) {
            $this->error(MailFailure::describe($e));

            return self::FAILURE;
        }

        $this->info($mailer === 'log' ? 'Written to the log (MAIL_MAILER=log): no e-mail was sent.' : "Sent to {$to}.");

        return self::SUCCESS;
    }
}
