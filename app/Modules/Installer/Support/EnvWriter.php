<?php

namespace App\Modules\Installer\Support;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Renders a .env file from the .env.example template: existing keys get the new
 * values (comments and layout are kept), unknown keys are appended. The result
 * is parsed back and compared, so a value that would be misread by the .env
 * parser (quotes, $, #) is caught before the file is written.
 */
class EnvWriter
{
    /** @param array<string, string|int|bool|null> $values */
    public function render(string $template, array $values): string
    {
        $lines = preg_split('/\R/', $template);
        $pending = $values;

        foreach ($lines as $i => $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m)) {
                continue;
            }

            if (array_key_exists($m[1], $pending)) {
                $lines[$i] = $m[1].'='.$this->encode($pending[$m[1]]);
                unset($pending[$m[1]]);
            }
        }

        if ($pending !== []) {
            $lines[] = '';
            foreach ($pending as $key => $value) {
                $lines[] = $key.'='.$this->encode($value);
            }
        }

        $content = rtrim(implode("\n", $lines))."\n";
        $this->verify($content, $values);

        return $content;
    }

    public function write(string $path, string $content): void
    {
        $temporary = $path.'.tmp';

        if (file_put_contents($temporary, $content, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Could not write '.$path.'. Check that the web server user can write to that folder.');
        }

        @chmod($path, 0640);
    }

    public function encode(string|int|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.:\/@+\-]+$/', $value)) {
            return $value;
        }

        // Single quotes are literal (no ${} interpolation, no escapes).
        if (! str_contains($value, "'") && ! preg_match('/[\r\n]/', $value)) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /** @param array<string, string|int|bool|null> $expected */
    private function verify(string $content, array $expected): void
    {
        $parsed = Dotenv::parse($content);

        foreach ($expected as $key => $value) {
            $want = match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };

            if (($parsed[$key] ?? null) !== $want) {
                throw new RuntimeException("The value for {$key} cannot be stored safely in .env. Please avoid unusual characters in it.");
            }
        }
    }
}
