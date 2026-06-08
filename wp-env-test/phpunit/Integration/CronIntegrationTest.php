<?php

declare(strict_types=1);

namespace Modelo\NettyImport\Tests\Integration;

use Modelo\NettyImport\Cron;

class CronIntegrationTest extends WPTestCase
{
    private const EVENT_HOOK = 'mnti_import_event';

    public function test_reschedule_with_valid_url_schedules_event(): void
    {
        update_option('mnti_feed_url', 'https://feed.test/netty.xml');
        Cron::reschedule_main_import();

        $next = wp_next_scheduled(self::EVENT_HOOK);
        $this->assertNotFalse($next, 'Event doit être planifié quand l\'URL est configurée.');
        $this->assertGreaterThan(time(), $next);
    }

    public function test_reschedule_without_url_does_not_schedule_event(): void
    {
        // mnti_feed_url = '' (après tearDown).
        Cron::reschedule_main_import();

        $next = wp_next_scheduled(self::EVENT_HOOK);
        $this->assertFalse($next, 'Aucun event ne doit être planifié sans URL de flux.');
    }

    public function test_double_reschedule_produces_single_event(): void
    {
        update_option('mnti_feed_url', 'https://feed.test/netty.xml');
        Cron::reschedule_main_import();
        Cron::reschedule_main_import(); // Deuxième appel.

        $crons = _get_cron_array();
        $event_count = 0;
        foreach ($crons as $timestamp => $hooks) {
            if (isset($hooks[self::EVENT_HOOK])) {
                $event_count++;
            }
        }
        $this->assertSame(1, $event_count, 'Un seul event planifié même après double reschedule.');
    }

    public function test_get_interval_seconds_for_hours(): void
    {
        update_option('mnti_schedule_interval', 2);
        update_option('mnti_schedule_unit', 'hour');

        $this->assertSame(2 * HOUR_IN_SECONDS, Cron::get_interval_seconds_from_options());
    }

    public function test_get_interval_seconds_for_minutes(): void
    {
        update_option('mnti_schedule_interval', 30);
        update_option('mnti_schedule_unit', 'minute');

        $this->assertSame(30 * MINUTE_IN_SECONDS, Cron::get_interval_seconds_from_options());
    }

    public function test_get_interval_seconds_for_days(): void
    {
        update_option('mnti_schedule_interval', 3);
        update_option('mnti_schedule_unit', 'day');

        $this->assertSame(3 * DAY_IN_SECONDS, Cron::get_interval_seconds_from_options());
    }

    public function test_get_interval_capped_at_30_days(): void
    {
        update_option('mnti_schedule_interval', 999);
        update_option('mnti_schedule_unit', 'day');

        $this->assertSame(30 * DAY_IN_SECONDS, Cron::get_interval_seconds_from_options());
    }

    public function test_get_interval_invalid_unit_falls_back_to_hour(): void
    {
        update_option('mnti_schedule_interval', 6);
        update_option('mnti_schedule_unit', 'invalid_unit');

        $this->assertSame(6 * HOUR_IN_SECONDS, Cron::get_interval_seconds_from_options());
    }
}
