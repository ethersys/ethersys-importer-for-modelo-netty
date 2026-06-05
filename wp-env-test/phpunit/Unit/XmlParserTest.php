<?php

declare(strict_types=1);

namespace Modelo\NettyImport\Tests\Unit;

use Modelo\NettyImport\Tests\UnitTestCase;
use Modelo\NettyImport\XmlParser;

class XmlParserTest extends UnitTestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = dirname(__DIR__) . '/Fixtures';
    }

    private function readFixture(string $name): string
    {
        $content = file_get_contents($this->fixturesDir . '/' . $name);
        $this->assertNotFalse($content, "Fixture '{$name}' not found at {$this->fixturesDir}");
        return $content;
    }

    public function test_parse_full_feed_returns_all_scalar_fields(): void
    {
        $xml    = $this->readFixture('feed-full.xml');
        $result = XmlParser::parse($xml);

        $this->assertCount(1, $result['records']);
        $rec = $result['records'][0];

        $this->assertSame('REF-FULL', $rec['reference_technique']);
        $this->assertSame('REF-DISPLAY-001', $rec['reference_a_afficher']);
        $this->assertSame('location', $rec['type_annonce']);
        $this->assertSame('Appartement', $rec['type_prod']);
        $this->assertSame('Disponible', $rec['etat']);
        $this->assertSame('oui', $rec['mise_en_avant']);
        $this->assertSame('Appartement complet', $rec['titre']);
        $this->assertSame('69001', $rec['code_postal']);
        $this->assertSame('Lyon', $rec['ville']);
        $this->assertSame('75,5', $rec['surface_habitable']);
        $this->assertSame('0', $rec['surface_terrain']);
        $this->assertSame('4', $rec['nb_piece']);
        $this->assertSame('2', $rec['nb_chambre']);
        $this->assertSame('1', $rec['nb_sdb']);
        $this->assertSame('1', $rec['nb_sde']);
        $this->assertSame('1', $rec['wc']);
        $this->assertSame('oui', $rec['cave']);
        $this->assertSame('non', $rec['piscine']);
        $this->assertSame('1', $rec['stationnement_interne']);
        $this->assertSame('0', $rec['stationnement_externe']);
        $this->assertSame('Equipee', $rec['type_cuisine']);
        $this->assertSame('Individuel', $rec['type_chauffage']);
        $this->assertSame('Gaz', $rec['chauffages']);
        $this->assertSame('non', $rec['climatisations']);
        $this->assertSame('Meuble', $rec['ameublement']);
        $this->assertSame('1200', $rec['loyer']);
        $this->assertSame('150', $rec['charges']);
        $this->assertSame('0', $rec['prix']);
        $this->assertSame('2400', $rec['depot_garantie']);
        $this->assertSame('200', $rec['honoraires_visite_dossier']);
        $this->assertSame('150', $rec['honoraires_etat_lieux']);
        $this->assertSame('1200', $rec['honoraires_locataire']);
        $this->assertSame('Effectué', $rec['dpe_etat']);
        $this->assertSame('C', $rec['bilan_energie']);
        $this->assertSame('120', $rec['valeur_energie']);
        $this->assertSame('B', $rec['bilan_ges']);
        $this->assertSame('15', $rec['valeur_ges']);
        $this->assertSame('2023-06-15', $rec['dpe_date_realisation']);
        $this->assertSame('800', $rec['dpe_cout_min_conso']);
        $this->assertSame('1100', $rec['dpe_cout_max_conso']);
        $this->assertSame('2021', $rec['dpe_annee_reference_conso']);
    }

    public function test_parse_full_feed_extracts_images(): void
    {
        $xml    = $this->readFixture('feed-full.xml');
        $result = XmlParser::parse($xml);

        $this->assertSame(
            ['https://images.test/photo1.jpg', 'https://images.test/photo2.jpg'],
            $result['records'][0]['images']
        );
    }

    public function test_parse_malformed_xml_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        XmlParser::parse('<flux><bien><unclosed>');
    }

    public function test_parse_empty_flux_returns_empty_records(): void
    {
        $result = XmlParser::parse('<flux></flux>');
        $this->assertSame(['records' => []], $result);
    }

    public function test_parse_missing_fields_return_empty_strings(): void
    {
        $xml    = '<flux><bien><reference_technique>REF-MIN</reference_technique></bien></flux>';
        $result = XmlParser::parse($xml);

        $rec = $result['records'][0];
        $this->assertSame('REF-MIN', $rec['reference_technique']);
        $this->assertSame('', $rec['type_annonce']);
        $this->assertSame('', $rec['ville']);
        $this->assertSame('', $rec['dpe_etat']);
        $this->assertSame([], $rec['images']);
    }

    public function test_parse_cdata_description_extracted_as_text(): void
    {
        $xml = <<<XML
        <flux><bien>
          <reference_technique>REF-CDATA</reference_technique>
          <description><![CDATA[Beau & grand<br>appartement]]></description>
        </bien></flux>
        XML;

        $result = XmlParser::parse($xml);
        $this->assertSame('Beau & grand<br>appartement', $result['records'][0]['description']);
    }

    public function test_parse_trims_whitespace_from_fields(): void
    {
        $xml = '<flux><bien><reference_technique>  REF-TRIM  </reference_technique><ville>  Lyon  </ville></bien></flux>';
        $result = XmlParser::parse($xml);

        $this->assertSame('REF-TRIM', $result['records'][0]['reference_technique']);
        $this->assertSame('Lyon', $result['records'][0]['ville']);
    }

    public function test_parse_two_properties_returns_both(): void
    {
        $xml    = $this->readFixture('feed-two-properties.xml');
        $result = XmlParser::parse($xml);

        $this->assertCount(2, $result['records']);
        $this->assertSame('REF-001', $result['records'][0]['reference_technique']);
        $this->assertSame('REF-002', $result['records'][1]['reference_technique']);
    }

    public function test_parse_images_skips_empty_url(): void
    {
        $xml = <<<XML
        <flux><bien>
          <reference_technique>REF-IMG</reference_technique>
          <images>
            <image>https://images.test/ok.jpg</image>
            <image>  </image>
            <image>https://images.test/ok2.jpg</image>
          </images>
        </bien></flux>
        XML;

        $result = XmlParser::parse($xml);
        $this->assertSame(
            ['https://images.test/ok.jpg', 'https://images.test/ok2.jpg'],
            $result['records'][0]['images']
        );
    }
}
