<?php

declare(strict_types=1);

namespace Modelo\NettyImport\Tests\Unit;

use Modelo\NettyImport\XmlParser;
use PHPUnit\Framework\TestCase;

class XmlParserTest extends TestCase {

    private static string $fixture;

    public static function setUpBeforeClass(): void {
        self::$fixture = (string) file_get_contents(
            dirname( __DIR__ ) . '/fixtures/sample-feed.xml'
        );
    }

    /** @test */
    public function parse_retourne_deux_records(): void {
        $result = XmlParser::parse( self::$fixture );
        $this->assertCount( 2, $result['records'] );
    }

    /** @test */
    public function parse_extrait_reference_technique(): void {
        $result = XmlParser::parse( self::$fixture );
        $this->assertSame( 'TEST-001', $result['records'][0]['reference_technique'] );
        $this->assertSame( 'TEST-002', $result['records'][1]['reference_technique'] );
    }

    /** @test */
    public function parse_extrait_images(): void {
        $result = XmlParser::parse( self::$fixture );
        $this->assertCount( 2, $result['records'][0]['images'] );
        $this->assertSame( 'https://example.com/img/test-001-a.jpg', $result['records'][0]['images'][0] );
    }

    /** @test */
    public function parse_extrait_coordonnees(): void {
        $result = XmlParser::parse( self::$fixture );
        $this->assertSame( '48.8566', $result['records'][0]['latitude'] );
        $this->assertSame( '2.3522', $result['records'][0]['longitude'] );
    }

    /** @test */
    public function parse_extrait_latitude_longitude_vides_si_absent(): void {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><flux><bien><reference_technique>X</reference_technique></bien></flux>';
        $result = XmlParser::parse( $xml );
        $this->assertSame( '', $result['records'][0]['latitude'] );
        $this->assertSame( '', $result['records'][0]['longitude'] );
    }

    /** @test */
    public function parse_xml_invalide_leve_exception(): void {
        $this->expectException( \RuntimeException::class );
        XmlParser::parse( 'pas du xml valide <>' );
    }
}
