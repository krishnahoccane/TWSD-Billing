<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The key-file path must mean the same file for web requests (cwd public/)
 * and for queue workers / cron (cwd = the application folder).
 */
class GoogleKeyPathTest extends TestCase
{
    private function resolved(?string $value): ?string
    {
        $_SERVER['GOOGLE_SERVICE_ACCOUNT_JSON'] = $value;
        $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] = $value;

        try {
            return (require config_path('wcc.php'))['google']['service_account_json'];
        } finally {
            unset($_SERVER['GOOGLE_SERVICE_ACCOUNT_JSON'], $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON']);
        }
    }

    public function test_relative_paths_are_taken_from_the_application_folder_not_the_current_directory(): void
    {
        $this->assertSame(base_path('../etc/key.json'), $this->resolved('../etc/key.json'));
        $this->assertSame('/home/user/etc/key.json', $this->resolved('/home/user/etc/key.json'));
        $this->assertSame('C:\\keys\\key.json', $this->resolved('C:\\keys\\key.json'));
        $this->assertNull($this->resolved(''));
        $this->assertNull($this->resolved(null));
    }
}
