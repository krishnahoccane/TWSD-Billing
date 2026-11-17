<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * An SMTP failure (wrong key, blocked port, unverified sender) must never be a
 * 500: the page explains it, and the error is still logged.
 */
class MailFailureTest extends TestCase
{
    private function failingSmtp(string $serverMessage): void
    {
        Mail::extend('failing', fn () => new class($serverMessage) extends AbstractTransport
        {
            public function __construct(private string $serverMessage)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException($this->serverMessage);
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });

        config([
            'mail.default' => 'failing',
            'mail.mailers.failing' => ['transport' => 'failing'],
            'mail.mailers.smtp.host' => 'smtp-relay.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.password' => 'xsmtp-secret-key',
        ]);
    }

    public function test_admin_sending_a_password_link_sees_the_smtp_reason_instead_of_a_500(): void
    {
        $this->failingSmtp('Failed to authenticate on SMTP server with username "u" using "LOGIN": Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed xsmtp-secret-key".');
        $target = User::factory()->create();

        $response = $this->actingAs($this->superAdmin())
            ->from('/users')
            ->post(route('users.password-link', $target));

        $response->assertRedirect('/users');
        $response->assertSessionHas('error', fn (string $m) => str_contains($m, 'rejected the SMTP username or password')
            && str_contains($m, 'SMTP key')
            && ! str_contains($m, 'xsmtp-secret-key'));
    }

    public function test_blocked_smtp_port_is_explained(): void
    {
        $this->failingSmtp('Connection could not be established with host "smtp-relay.example.test:587": stream_socket_client(): Unable to connect (Connection timed out)');
        $target = User::factory()->create();

        $this->actingAs($this->superAdmin())->from('/users')->post(route('users.password-link', $target))
            ->assertRedirect('/users')
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'smtp-relay.example.test:587 could not be reached'));
    }

    public function test_guest_forgot_password_gets_a_generic_message_without_server_details(): void
    {
        $this->failingSmtp('Connection could not be established with host "smtp-relay.example.test:587"');
        $user = User::factory()->create();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'could not be sent') && ! str_contains($m, 'smtp-relay'));
    }

    public function test_mail_test_command_prints_the_reason(): void
    {
        $this->failingSmtp('Expected response code "250" but got code "550", with message "550 Sender not verified".');

        $this->artisan('wcc:mail-test', ['to' => 'someone@example.test'])
            ->expectsOutputToContain('Make sure this sender address or its domain is verified')
            ->assertExitCode(1);
    }

    public function test_mail_test_command_succeeds_with_a_working_mailer(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('wcc:mail-test', ['to' => 'someone@example.test'])
            ->expectsOutputToContain('Sent to someone@example.test')
            ->assertExitCode(0);
    }
}
