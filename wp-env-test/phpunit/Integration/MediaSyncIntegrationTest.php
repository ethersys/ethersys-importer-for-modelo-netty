<?php

declare(strict_types=1);

namespace Ethersys\NettyImport\Tests\Integration;

use Ethersys\NettyImport\MediaSync;

class MediaSyncIntegrationTest extends WPTestCase
{
    private int $post_id;
    /** @var \Closure|null */
    private $image_filter = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer un post "property" de test.
        $this->post_id = (int) wp_insert_post([
            'post_type'   => 'property',
            'post_title'  => 'Test MediaSync',
            'post_status' => 'publish',
        ]);

        // Stub du téléchargement : MediaSync télécharge via curl_multi (filtre
        // eimn_pre_download_urls). Pour toute URL https://images.test/*, on écrit
        // fake-image.jpg dans un fichier temp et on retourne la map url => chemin tmp
        // attendue par sync_gallery (qui unlink ensuite ces fichiers).
        $fake_jpg = (string) file_get_contents(EIMN_FIXTURE_DIR . '/fake-image.jpg');
        $this->image_filter = function ($pre, array $urls, int $timeout) use ($fake_jpg) {
            $out = is_array($pre) ? $pre : [];
            foreach ($urls as $url) {
                if (isset($out[$url])) {
                    continue;
                }
                if (strpos($url, 'https://images.test/') === 0) {
                    $tmp = tempnam(sys_get_temp_dir(), 'eimn_test_');
                    file_put_contents($tmp, $fake_jpg);
                    $out[$url] = $tmp;
                }
            }
            return $out;
        };
        add_filter('eimn_pre_download_urls', $this->image_filter, 10, 3);
    }

    protected function tearDown(): void
    {
        if ($this->image_filter !== null) {
            remove_filter('eimn_pre_download_urls', $this->image_filter, 10);
            $this->image_filter = null;
        }

        // Supprimer les attachments liés au post de test.
        $att_ids = get_posts([
            'post_type'   => 'attachment',
            'post_parent' => $this->post_id,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($att_ids as $id) {
            wp_delete_attachment((int) $id, true);
        }

        parent::tearDown();
    }

    public function test_new_image_creates_attachment_with_source_url_meta(): void
    {
        $result = MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/photo1.jpg']);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['deleted']);

        $att_id = $result['featured_attachment_id'];
        $this->assertNotNull($att_id);
        $this->assertSame('https://images.test/photo1.jpg', get_post_meta($att_id, MediaSync::ATT_SOURCE_URL_META, true));
    }

    public function test_first_image_set_as_thumbnail(): void
    {
        MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/photo1.jpg', 'https://images.test/photo2.jpg']);

        $thumb_id = (int) get_post_thumbnail_id($this->post_id);
        $this->assertGreaterThan(0, $thumb_id);

        $source = get_post_meta($thumb_id, MediaSync::ATT_SOURCE_URL_META, true);
        $this->assertSame('https://images.test/photo1.jpg', $source);
    }

    public function test_resync_same_url_keeps_existing_attachment(): void
    {
        // Premier sync.
        $first  = MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/photo1.jpg']);
        $att_id = $first['featured_attachment_id'];

        // Deuxième sync même URL.
        $second = MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/photo1.jpg']);

        $this->assertSame(0, $second['added']);
        $this->assertSame(1, $second['kept']);
        $this->assertSame($att_id, $second['featured_attachment_id'], 'Même attachment, pas de re-téléchargement.');
    }

    public function test_removed_url_deletes_attachment(): void
    {
        // Sync initial avec 2 images.
        $first = MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', [
            'https://images.test/photo1.jpg',
            'https://images.test/photo2.jpg',
        ]);
        $this->assertSame(2, $first['added']);

        // Re-sync avec seulement la première.
        $second = MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/photo1.jpg']);

        $this->assertSame(0, $second['added']);
        $this->assertSame(1, $second['deleted']);
    }

    public function test_delete_all_attached_media_removes_all_attachments(): void
    {
        MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/photo1.jpg', 'https://images.test/photo2.jpg']);

        $deleted = MediaSync::delete_all_attached_media(0, $this->post_id, 'REF-TEST');
        $this->assertSame(2, $deleted);

        $remaining = get_posts([
            'post_type'   => 'attachment',
            'post_parent' => $this->post_id,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $this->assertCount(0, $remaining);
    }

    public function test_download_failure_does_not_add_attachment(): void
    {
        // Filtre prioritaire (15, après le filtre général à 10) : remplace le résultat de
        // broken.jpg par une WP_Error et nettoie le tmp éventuellement créé en amont.
        $error_filter = function ($pre, array $urls, int $timeout) {
            $out = is_array($pre) ? $pre : [];
            if (isset($out['https://images.test/broken.jpg']) && is_string($out['https://images.test/broken.jpg'])) {
                @unlink($out['https://images.test/broken.jpg']);
            }
            if (in_array('https://images.test/broken.jpg', $urls, true)) {
                $out['https://images.test/broken.jpg'] = new \WP_Error('http_request_failed', 'Connexion refusée');
            }
            return $out;
        };
        add_filter('eimn_pre_download_urls', $error_filter, 15, 3);

        $result = MediaSync::sync_gallery(0, $this->post_id, 'REF-TEST', ['https://images.test/broken.jpg']);

        remove_filter('eimn_pre_download_urls', $error_filter, 15);

        $this->assertSame(0, $result['added']);
        $this->assertNull($result['featured_attachment_id']);
    }
}
