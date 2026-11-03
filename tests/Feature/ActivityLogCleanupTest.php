<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Activitylog\Models\Activity;

it('schedules nightly activity log cleanup and removes entries older than the retention period', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'activitylog:clean'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('15 1 * * *')
        ->and(config('activitylog.clean_after_days'))->toBe(365);

    $old = activity()->log('lama');
    $old->forceFill(['created_at' => now()->subDays(366)])->save();
    $recent = activity()->log('baru');
    $recent->forceFill(['created_at' => now()->subDays(364)])->save();

    $this->artisan('activitylog:clean')->assertSuccessful();

    expect(Activity::query()->pluck('id')->all())->toBe([$recent->id]);
});
