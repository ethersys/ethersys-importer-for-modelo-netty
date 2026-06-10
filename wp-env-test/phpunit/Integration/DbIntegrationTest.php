<?php

declare(strict_types=1);

namespace Ethersys\NettyImport\Tests\Integration;

use Ethersys\NettyImport\Db;

class DbIntegrationTest extends WPTestCase
{
    public function test_create_tables_creates_runs_table(): void
    {
        global $wpdb;
        $table = Db::runs_table();
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        $this->assertSame($table, $exists);
    }

    public function test_create_tables_creates_logs_table(): void
    {
        global $wpdb;
        $table = Db::logs_table();
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        $this->assertSame($table, $exists);
    }

    public function test_create_tables_is_idempotent(): void
    {
        // Calling twice must not throw or produce errors.
        Db::create_tables();
        Db::create_tables();
        $this->addToAssertionCount(1); // reached = no exception
    }

    public function test_runs_table_contains_prefix(): void
    {
        global $wpdb;
        $this->assertStringStartsWith($wpdb->prefix, Db::runs_table());
    }

    public function test_logs_table_contains_prefix(): void
    {
        global $wpdb;
        $this->assertStringStartsWith($wpdb->prefix, Db::logs_table());
    }

    public function test_purge_old_runs_removes_oldest_when_over_limit(): void
    {
        global $wpdb;

        // Insérer 5 runs.
        $run_ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $wpdb->insert(Db::runs_table(), [
                'started_at'  => gmdate('Y-m-d H:i:s', time() + $i),
                'status'      => 'success',
                'source_url'  => 'https://test/',
                'counts_json' => '{}',
            ], ['%s', '%s', '%s', '%s']);
            $run_ids[] = (int) $wpdb->insert_id;

            // Ajouter un log pour chaque run.
            $wpdb->insert(Db::logs_table(), [
                'run_id'     => end($run_ids),
                'level'      => 'info',
                'action'     => 'test',
                'message'    => 'test log',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], ['%d', '%s', '%s', '%s', '%s']);
        }

        // Garder seulement 2 runs.
        Db::purge_old_runs(2);

        $remaining_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Db::runs_table());
        $this->assertSame(2, $remaining_count);
    }

    public function test_purge_old_runs_deletes_associated_logs(): void
    {
        global $wpdb;

        // Insérer 3 runs avec logs.
        for ($i = 1; $i <= 3; $i++) {
            $wpdb->insert(Db::runs_table(), [
                'started_at'  => gmdate('Y-m-d H:i:s', time() + $i),
                'status'      => 'success',
                'source_url'  => 'https://test/',
                'counts_json' => '{}',
            ], ['%s', '%s', '%s', '%s']);
            $run_id = (int) $wpdb->insert_id;

            $wpdb->insert(Db::logs_table(), [
                'run_id'     => $run_id,
                'level'      => 'info',
                'action'     => 'test',
                'message'    => 'log',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], ['%d', '%s', '%s', '%s', '%s']);
        }

        Db::purge_old_runs(1);

        $log_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Db::logs_table());
        $this->assertSame(1, $log_count); // Seul le log du run récent reste.
    }

    public function test_purge_old_runs_with_no_excess_does_nothing(): void
    {
        global $wpdb;

        $wpdb->insert(Db::runs_table(), [
            'started_at'  => gmdate('Y-m-d H:i:s'),
            'status'      => 'success',
            'source_url'  => 'https://test/',
            'counts_json' => '{}',
        ], ['%s', '%s', '%s', '%s']);

        Db::purge_old_runs(200);

        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Db::runs_table());
        $this->assertSame(1, $count);
    }
}
