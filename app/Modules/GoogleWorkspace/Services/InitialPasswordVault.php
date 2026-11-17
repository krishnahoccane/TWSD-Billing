<?php

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Models\AccountSecret;
use App\Modules\GoogleWorkspace\Models\GoogleAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * One-time delivery of a new mailbox's initial password (decision D-07).
 *
 * The password is generated server-side, sent to Google, and kept only as
 * an APP_KEY-encrypted value that the creating user may reveal exactly
 * once within 24 hours. It is never e-mailed, logged or audited, and
 * Google forces a change at first sign-in.
 */
class InitialPasswordVault
{
    public const LIFETIME_HOURS = 24;

    /** 20 characters from every class, ASCII only (Google accepts 8–100 ASCII). */
    public function generate(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_+='];
        $characters = [];

        foreach ($sets as $set) {
            $characters[] = $set[random_int(0, strlen($set) - 1)];
        }

        $all = implode('', $sets);

        while (count($characters) < 20) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Fisher–Yates with a CSPRNG.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    public function store(GoogleAccount $account, User $recipient, #[SensitiveParameter] string $password): void
    {
        // Any earlier, unviewed password for this mailbox is now wrong.
        AccountSecret::query()->where('google_account_id', $account->getKey())->delete();

        AccountSecret::query()->create([
            'uuid' => (string) Str::uuid(),
            'google_account_id' => $account->getKey(),
            'user_id' => $recipient->getKey(),
            'payload' => $password,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);
    }

    public function isAvailableTo(GoogleAccount $account, User $user): bool
    {
        return $this->pending($account, $user)->exists();
    }

    /**
     * Returns the password once and destroys it. Null when there is nothing
     * (left) to reveal for this user.
     */
    public function reveal(GoogleAccount $account, User $user): ?string
    {
        return DB::transaction(function () use ($account, $user) {
            $secret = $this->pending($account, $user)->lockForUpdate()->first();

            if ($secret === null) {
                return null;
            }

            $password = $secret->payload;
            $secret->delete();

            return $password;
        });
    }

    public function purgeExpired(): int
    {
        return AccountSecret::query()->where('expires_at', '<', now())->delete();
    }

    private function pending(GoogleAccount $account, User $user)
    {
        return AccountSecret::query()
            ->where('google_account_id', $account->getKey())
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now());
    }
}
