<?php

declare(strict_types=1);

namespace Modelo\NettyImport\Tests\Integration;

use Modelo\NettyImport\Db;
use PHPUnit\Framework\TestCase;

abstract class WPTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::create_tables();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Vider les tables custom du plugin.
        $wpdb->query('TRUNCATE TABLE ' . Db::logs_table());
        $wpdb->query('TRUNCATE TABLE ' . Db::runs_table());

        // Supprimer tous les posts de type "property".
        $ids = get_posts([
            'post_type'   => 'property',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }

        // Réinitialiser les options plugin.
        foreach (['mnti_feed_url', 'mnti_schedule_interval', 'mnti_schedule_unit', 'mnti_default_agent_id'] as $opt) {
            delete_option($opt);
        }
        delete_transient('mnti_import_lock');
        wp_clear_scheduled_hook('mnti_import_event');

        wp_cache_flush();
        parent::tearDown();
    }

    /**
     * Retourne l'ID d'un post "property" par sa référence technique, ou null si absent.
     */
    protected function find_property_by_ref(string $ref): ?int
    {
        $q = new \WP_Query([
            'post_type'      => 'property',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => \Modelo\NettyImport\Importer::META_REF, 'value' => $ref],
            ],
        ]);
        return ! empty($q->posts[0]) ? (int) $q->posts[0] : null;
    }

    /**
     * Stub le feed HTTP avec $xml_content.
     * Retourne le hook à passer à unstub_feed().
     */
    protected function stub_feed(string $xml_content): \Closure
    {
        $hook = function ($preempt, array $args, string $url) use ($xml_content) {
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => $xml_content,
                'headers'  => [],
                'cookies'  => [],
            ];
        };
        add_filter('pre_http_request', $hook, 10, 3);
        return $hook;
    }

    protected function unstub_feed(\Closure $hook): void
    {
        remove_filter('pre_http_request', $hook, 10);
    }
}
