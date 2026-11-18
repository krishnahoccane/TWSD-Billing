<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Stored in UTC, shown in APP_TIMEZONE. */
class DisplayTimezoneTest extends TestCase
{
    public function test_timestamps_are_shown_in_the_display_timezone_and_stored_in_utc(): void
    {
        config(['app.display_timezone' => 'Asia/Kolkata']);

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('18 Nov 2026 20:40', Carbon::parse('2026-11-18 15:10:00', 'UTC')->inDisplayTimezone()->format('d M Y H:i'));
        $this->assertSame('19 Nov 2026', CarbonImmutable::parse('2026-11-18 20:00:00', 'UTC')->inDisplayTimezone()->format('d M Y'));

        $original = Carbon::parse('2026-11-18 15:10:00', 'UTC');
        $original->inDisplayTimezone();
        $this->assertSame('UTC', $original->tzName, 'The stored value is not modified.');

        config(['app.display_timezone' => 'Not/AZone']);
        $this->assertSame('15:10', Carbon::parse('2026-11-18 15:10:00', 'UTC')->inDisplayTimezone()->format('H:i'));
    }
}
