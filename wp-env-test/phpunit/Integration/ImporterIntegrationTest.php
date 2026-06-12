<?php

declare(strict_types=1);

namespace Ethersys\NettyImport\Tests\Integration;

use Ethersys\NettyImport\Importer;

class ImporterIntegrationTest extends WPTestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = EIMN_FIXTURE_DIR;
        update_option('eimn_feed_url', 'https://feed.test/netty.xml');
    }

    // ──── Happy path ──────────────────────────────────────────────────────────

    public function test_import_creates_property_post(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);

        $result = Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $this->assertSame(0, $result['counts']['errors']);
        $this->assertSame(1, $result['counts']['created']);
        $this->assertSame(0, $result['counts']['updated']);

        $post_id = $this->find_property_by_ref('REF-001');
        $this->assertNotNull($post_id, 'Post REF-001 doit exister après import.');
    }

    public function test_import_sets_core_houzez_metas(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $this->assertNotNull($post_id);

        $this->assertSame('69001', get_post_meta($post_id, 'fave_property_zip', true));
        $this->assertSame('45', get_post_meta($post_id, 'fave_property_size', true));
        $this->assertSame('1', get_post_meta($post_id, 'fave_property_bedrooms', true));
        $this->assertSame('800', get_post_meta($post_id, 'fave_property_price', true));
        $this->assertStringStartsWith('mois', (string) get_post_meta($post_id, 'fave_property_price_postfix', true));
    }

    public function test_import_sets_eimn_metas(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $this->assertNotNull($post_id);

        $this->assertSame('REF-001', get_post_meta($post_id, Importer::META_REF, true));
        $this->assertSame('50', get_post_meta($post_id, 'eimn_charges', true));
        $this->assertSame('location', get_post_meta($post_id, 'eimn_type_annonce', true));
    }

    public function test_reimport_updates_existing_property(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id_first = $this->find_property_by_ref('REF-001');

        // Deuxième import.
        $hook2  = $this->stub_feed($xml);
        $result = Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook2);

        $this->assertSame(0, $result['counts']['created']);
        $this->assertSame(1, $result['counts']['updated']);

        $post_id_second = $this->find_property_by_ref('REF-001');
        $this->assertSame($post_id_first, $post_id_second, 'Même post ID, pas de doublon.');
    }

    public function test_delete_missing_removes_absent_property(): void
    {
        // Premier import : 2 biens.
        $xml_two = (string) file_get_contents($this->fixturesDir . '/feed-two-properties.xml');
        $hook    = $this->stub_feed($xml_two);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $this->assertNotNull($this->find_property_by_ref('REF-001'));
        $this->assertNotNull($this->find_property_by_ref('REF-002'));

        // Deuxième import : seul REF-001, avec delete_missing.
        $xml_one = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook2   = $this->stub_feed($xml_one);
        $result  = Importer::run(['sync_images' => false, 'delete_missing' => true]);
        $this->unstub_feed($hook2);

        $this->assertSame(1, $result['counts']['deleted']);
        $this->assertNotNull($this->find_property_by_ref('REF-001'), 'REF-001 doit rester.');
        $this->assertNull($this->find_property_by_ref('REF-002'), 'REF-002 doit être supprimé.');
    }

    public function test_delete_missing_false_preserves_all_properties(): void
    {
        $xml_two = (string) file_get_contents($this->fixturesDir . '/feed-two-properties.xml');
        $hook    = $this->stub_feed($xml_two);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $xml_one = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook2   = $this->stub_feed($xml_one);
        $result  = Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook2);

        $this->assertSame(0, $result['counts']['deleted']);
        $this->assertNotNull($this->find_property_by_ref('REF-001'));
        $this->assertNotNull($this->find_property_by_ref('REF-002'));
    }

    public function test_price_postfix_mois_cc_when_charges_zero(): void
    {
        // Feed avec loyer et charges = 0.
        $xml  = '<flux><bien><reference_technique>REF-CC</reference_technique>' .
                '<type_annonce>location</type_annonce><titre>T</titre><loyer>600</loyer><charges>0</charges></bien></flux>';
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-CC');
        $this->assertNotNull($post_id);
        $this->assertSame('mois CC', get_post_meta($post_id, 'fave_property_price_postfix', true));
    }

    public function test_vente_uses_prix_field_and_empty_postfix(): void
    {
        $xml  = '<flux><bien><reference_technique>REF-VENTE</reference_technique>' .
                '<type_annonce>vente</type_annonce><titre>T</titre><prix>350000</prix></bien></flux>';
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-VENTE');
        $this->assertNotNull($post_id);
        $this->assertSame('350000', get_post_meta($post_id, 'fave_property_price', true));
        $this->assertSame('', get_post_meta($post_id, 'fave_property_price_postfix', true));
    }

    // ──── Error paths ─────────────────────────────────────────────────────────

    public function test_import_fails_when_feed_url_not_configured(): void
    {
        delete_option('eimn_feed_url');
        $result = Importer::run(['sync_images' => false]);

        $this->assertSame(1, $result['counts']['errors']);

        global $wpdb;
        $run = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . \Ethersys\NettyImport\Db::runs_table() . ' WHERE id=%d', $result['run_id']),
            ARRAY_A
        );
        $this->assertSame('failed', $run['status']);

        $log = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . \Ethersys\NettyImport\Db::logs_table() . ' WHERE run_id=%d AND action=%s',
                $result['run_id'], 'no_feed_url'
            ),
            ARRAY_A
        );
        $this->assertNotNull($log, 'Log no_feed_url attendu.');
    }

    public function test_import_fails_on_malformed_xml(): void
    {
        $hook   = $this->stub_feed('<flux><broken>');
        $result = Importer::run(['sync_images' => false]);
        $this->unstub_feed($hook);

        $this->assertSame(1, $result['counts']['errors']);

        global $wpdb;
        $run = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . \Ethersys\NettyImport\Db::runs_table() . ' WHERE id=%d', $result['run_id']),
            ARRAY_A
        );
        $this->assertSame('failed', $run['status']);
    }

    public function test_dry_run_creates_no_posts(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false, 'dry_run' => true]);
        $this->unstub_feed($hook);

        $this->assertNull($this->find_property_by_ref('REF-001'), 'Dry run ne doit pas créer de post.');
    }

    public function test_dry_run_logs_dry_run_action(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        $result = Importer::run(['sync_images' => false, 'delete_missing' => false, 'dry_run' => true]);
        $this->unstub_feed($hook);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . \Ethersys\NettyImport\Db::logs_table() . ' WHERE run_id=%d AND action=%s',
                $result['run_id'], 'dry_run'
            )
        );
        $this->assertGreaterThan(0, $count, 'Au moins un log dry_run attendu.');
    }

    public function test_missing_reference_technique_logs_error(): void
    {
        $xml  = '<flux><bien><titre>Bien sans ref</titre><type_annonce>location</type_annonce></bien></flux>';
        $hook = $this->stub_feed($xml);
        $result = Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $this->assertSame(1, $result['counts']['errors']);

        global $wpdb;
        $log = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . \Ethersys\NettyImport\Db::logs_table() . ' WHERE run_id=%d AND action=%s',
                $result['run_id'], 'missing_reference'
            ),
            ARRAY_A
        );
        $this->assertNotNull($log);
    }

    // ──── Taxonomies & features ───────────────────────────────────────────────

    public function test_property_status_louer_assigned_for_location(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml'); // type_annonce=location
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $terms   = wp_get_post_terms($post_id, 'property_status', ['fields' => 'slugs']);
        $this->assertContains('louer', (array) $terms);
    }

    public function test_property_status_acheter_assigned_for_vente(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-two-properties.xml'); // REF-002 = vente
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-002');
        $terms   = wp_get_post_terms($post_id, 'property_status', ['fields' => 'slugs']);
        $this->assertContains('acheter', (array) $terms);
    }

    public function test_property_type_term_created_and_assigned(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml'); // type_prod=Appartement
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $terms   = wp_get_post_terms($post_id, 'property_type', ['fields' => 'names']);
        $this->assertContains('Appartement', (array) $terms);
    }

    public function test_property_city_term_created_and_assigned(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml'); // ville=Lyon
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $terms   = wp_get_post_terms($post_id, 'property_city', ['fields' => 'names']);
        $this->assertContains('Lyon', (array) $terms);
    }

    public function test_cave_oui_adds_cave_feature(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-full.xml'); // cave=oui
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-FULL');
        $terms   = wp_get_post_terms($post_id, 'property_feature', ['fields' => 'names']);
        $this->assertContains('Cave', (array) $terms);
    }

    public function test_piscine_non_does_not_add_piscine_feature(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-full.xml'); // piscine=non
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-FULL');
        $terms   = wp_get_post_terms($post_id, 'property_feature', ['fields' => 'names']);
        $this->assertNotContains('Piscine', (array) $terms);
    }

    public function test_manually_added_feature_preserved_on_reimport(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        // Ajout manuel d'un terme non géré par le mapping.
        wp_set_object_terms($post_id, ['Jardin'], 'property_feature', true);

        // Deuxième import.
        $hook2 = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook2);

        $terms = wp_get_post_terms($post_id, 'property_feature', ['fields' => 'names']);
        $this->assertContains('Jardin', (array) $terms, 'Terme manuel doit être conservé après re-import.');
    }

    // ──── DPE / ImmoWP ────────────────────────────────────────────────────────

    public function test_dpe_effectue_stores_real_values(): void
    {
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-full.xml'); // dpe_etat=Effectué
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-FULL');
        $this->assertSame('120', get_post_meta($post_id, 'dpeNumber', true));
        $this->assertSame('15', get_post_meta($post_id, 'gesNumber', true));
    }

    public function test_dpe_non_effectue_stores_zeros(): void
    {
        $xml  = '<flux><bien><reference_technique>REF-DPE-NO</reference_technique>' .
                '<type_annonce>location</type_annonce><titre>T</titre>' .
                '<dpe_etat>Non effectué</dpe_etat><valeur_energie>200</valeur_energie><valeur_ges>30</valeur_ges>' .
                '</bien></flux>';
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-DPE-NO');
        $this->assertSame('0', get_post_meta($post_id, 'dpeNumber', true));
        $this->assertSame('0', get_post_meta($post_id, 'gesNumber', true));
    }

    // ──── Description normalization ───────────────────────────────────────────

    public function test_br_tags_converted_in_post_content(): void
    {
        $xml  = '<flux><bien><reference_technique>REF-DESC</reference_technique>' .
                '<type_annonce>location</type_annonce><titre>T</titre>' .
                '<description>Ligne 1&lt;br&gt;Ligne 2</description></bien></flux>';
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-DESC');
        $post    = get_post($post_id);
        $this->assertStringContainsString('Ligne 1', $post->post_content);
        $this->assertStringContainsString('Ligne 2', $post->post_content);
        $this->assertStringNotContainsString('<br>', $post->post_content);
    }

    // ──── Agent par défaut ────────────────────────────────────────────────────

    public function test_no_agent_configured_uses_author_info(): void
    {
        // eimn_default_agent_id = 0 (par défaut après tearDown).
        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $this->assertSame('author_info', get_post_meta($post_id, 'fave_agent_display_option', true));
    }

    public function test_agent_id_configured_assigns_agent_to_property(): void
    {
        $agent_id = (int) wp_insert_post([
            'post_type'   => 'houzez_agent',
            'post_title'  => 'Agent Test',
            'post_status' => 'publish',
        ]);
        update_option('eimn_default_agent_id', $agent_id);

        $xml  = (string) file_get_contents($this->fixturesDir . '/feed-minimal.xml');
        $hook = $this->stub_feed($xml);
        Importer::run(['sync_images' => false, 'delete_missing' => false]);
        $this->unstub_feed($hook);

        $post_id = $this->find_property_by_ref('REF-001');
        $this->assertSame('agent_info', get_post_meta($post_id, 'fave_agent_display_option', true));

        $agents = get_post_meta($post_id, 'fave_agents', false);
        $this->assertContains((string) $agent_id, array_map('strval', (array) $agents));

        wp_delete_post($agent_id, true);
    }
}
