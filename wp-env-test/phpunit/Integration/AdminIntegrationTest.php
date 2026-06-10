<?php

declare(strict_types=1);

namespace Ethersys\NettyImport\Tests\Integration;

use Ethersys\NettyImport\Importer;
use Ethersys\NettyImport\Cron;

class AdminIntegrationTest extends WPTestCase
{
    /**
     * Simule la sanitization de handle_save_settings sans appeler la fonction entière
     * (qui utilise check_admin_referer + exit).
     */
    private function save_settings(array $post): void
    {
        $feed_raw = isset($post['eimn_feed_url']) ? (string) $post['eimn_feed_url'] : '';
        $feed_url = $feed_raw === '' ? '' : esc_url_raw(trim($feed_raw), ['http', 'https']);

        $interval = isset($post['eimn_schedule_interval']) ? (int) $post['eimn_schedule_interval'] : 6;
        $interval = max(1, min(999, $interval));

        $unit = isset($post['eimn_schedule_unit']) ? sanitize_key((string) $post['eimn_schedule_unit']) : 'hour';
        if (! in_array($unit, ['minute', 'hour', 'day'], true)) {
            $unit = 'hour';
        }

        $agent_id = isset($post['eimn_default_agent_id']) ? (int) $post['eimn_default_agent_id'] : 0;
        if ($agent_id < 0) {
            $agent_id = 0;
        }

        update_option('eimn_feed_url', $feed_url, false);
        update_option('eimn_schedule_interval', $interval, false);
        update_option('eimn_schedule_unit', $unit, false);
        update_option('eimn_default_agent_id', $agent_id, false);

        Cron::reschedule_main_import();
    }

    public function test_valid_feed_url_is_stored_and_configured(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.example.com/flux.xml']);

        $this->assertSame('https://feed.example.com/flux.xml', get_option('eimn_feed_url'));
        $this->assertTrue(Importer::is_feed_configured());
    }

    public function test_invalid_url_stored_as_empty_string(): void
    {
        // esc_url_raw strips disallowed schemes (javascript:, ftp:…) to empty string.
        $this->save_settings(['eimn_feed_url' => 'javascript:alert(1)']);

        $this->assertSame('', get_option('eimn_feed_url'));
        $this->assertFalse(Importer::is_feed_configured());
    }

    public function test_schedule_interval_stored_correctly(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.test/', 'eimn_schedule_interval' => 12, 'eimn_schedule_unit' => 'hour']);

        $this->assertSame(12, (int) get_option('eimn_schedule_interval'));
        $this->assertSame('hour', get_option('eimn_schedule_unit'));
    }

    public function test_interval_below_1_clamped_to_1(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.test/', 'eimn_schedule_interval' => 0]);

        $this->assertSame(1, (int) get_option('eimn_schedule_interval'));
    }

    public function test_interval_above_999_clamped_to_999(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.test/', 'eimn_schedule_interval' => 1000]);

        $this->assertSame(999, (int) get_option('eimn_schedule_interval'));
    }

    public function test_invalid_unit_falls_back_to_hour(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.test/', 'eimn_schedule_unit' => 'week']);

        $this->assertSame('hour', get_option('eimn_schedule_unit'));
    }

    public function test_agent_id_stored_correctly(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.test/', 'eimn_default_agent_id' => 42]);

        $this->assertSame(42, (int) get_option('eimn_default_agent_id'));
    }

    public function test_negative_agent_id_stored_as_zero(): void
    {
        $this->save_settings(['eimn_feed_url' => 'https://feed.test/', 'eimn_default_agent_id' => -5]);

        $this->assertSame(0, (int) get_option('eimn_default_agent_id'));
    }
}
