<?php

declare(strict_types=1);

namespace Ethersys\NettyImport\Tests\Unit;

use Ethersys\NettyImport\Importer;
use Ethersys\NettyImport\Tests\UnitTestCase;
use Brain\Monkey\Functions;

class ImporterMappingTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // remove_accents est une fonction WP — stub identité pour les tests.
        Functions\stubs(['remove_accents', 'update_post_meta']);
    }

    // ──── Helpers ─────────────────────────────────────────────────────────────

    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(Importer::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    // ──── map_status_slug ─────────────────────────────────────────────────────

    /** @dataProvider status_slug_provider */
    public function test_map_status_slug(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->invoke('map_status_slug', $input));
    }

    /** @return array<string,array{string,string}> */
    public static function status_slug_provider(): array
    {
        return [
            'vente lowercase'   => ['vente',    'acheter'],
            'vente mixed case'  => ['Vente',    'acheter'],
            'location'          => ['location', 'louer'],
            'empty string'      => ['',         'louer'],
            'unknown value'     => ['autre',    'louer'],
            'louer passthrough' => ['louer',    'louer'],
        ];
    }

    // ──── map_property_type ───────────────────────────────────────────────────

    /** @dataProvider property_type_provider */
    public function test_map_property_type(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->invoke('map_property_type', $input));
    }

    /** @return array<string,array{string,string}> */
    public static function property_type_provider(): array
    {
        return [
            'Appartement passthrough'  => ['Appartement', 'Appartement'],
            'Maison passthrough'       => ['Maison',      'Maison'],
            'empty string'             => ['',            ''],
            'location rejected'        => ['location',    ''],
            'vente rejected'           => ['vente',       ''],
            'louer rejected'           => ['louer',       ''],
            'acheter rejected'         => ['acheter',     ''],
            'rent rejected'            => ['rent',        ''],
            'sale rejected'            => ['sale',        ''],
        ];
    }

    // ──── is_truthy_feature_value ─────────────────────────────────────────────

    /** @dataProvider truthy_feature_provider */
    public function test_is_truthy_feature_value(string $input, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('is_truthy_feature_value', $input));
    }

    /** @return array<string,array{string,bool}> */
    public static function truthy_feature_provider(): array
    {
        return [
            'empty string'    => ['',           false],
            'zero string'     => ['0',          false],
            'non lowercase'   => ['non',        false],
            'Non prefix'      => ['Non meuble', false],
            'false string'    => ['false',      false],
            'n string'        => ['n',          false],
            'no string'       => ['no',         false],
            'oui'             => ['oui',        true],
            'one string'      => ['1',          true],
            'Equipe'          => ['Equipe',     true],
            'parking string'  => ['parking',    true],
            'whitespace only' => ['   ',        false],
        ];
    }

    // ──── set_meta_number ─────────────────────────────────────────────────────

    public function test_set_meta_number_converts_comma_to_dot(): void
    {
        $captured_value = null;
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, string $value) use (&$captured_value): void {
                $captured_value = $value;
            }
        );

        $this->invoke('set_meta_number', 99, 'fave_property_size', '75,5');
        $this->assertSame('75.5', $captured_value);
    }

    public function test_set_meta_number_stores_empty_string_for_empty_input(): void
    {
        $captured_value = null;
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, string $value) use (&$captured_value): void {
                $captured_value = $value;
            }
        );

        $this->invoke('set_meta_number', 99, 'fave_property_price', '');
        $this->assertSame('', $captured_value);
    }

    public function test_set_meta_number_passthrough_for_integer_string(): void
    {
        $captured_value = null;
        Functions\when('update_post_meta')->alias(
            function (int $post_id, string $key, string $value) use (&$captured_value): void {
                $captured_value = $value;
            }
        );

        $this->invoke('set_meta_number', 99, 'fave_property_bedrooms', '3');
        $this->assertSame('3', $captured_value);
    }
}
