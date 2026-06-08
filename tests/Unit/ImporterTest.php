<?php

declare(strict_types=1);

namespace Modelo\NettyImport\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ImporterTest extends TestCase {

    /** @test */
    public function map_status_slug_vente_retourne_acheter(): void {
        $ref = new \ReflectionClass( \Modelo\NettyImport\Importer::class );
        $method = $ref->getMethod( 'map_status_slug' );
        $method->setAccessible( true );

        $this->assertSame( 'acheter', $method->invoke( null, 'vente' ) );
        $this->assertSame( 'acheter', $method->invoke( null, 'VENTE' ) );
    }

    /** @test */
    public function map_status_slug_location_retourne_louer(): void {
        $ref = new \ReflectionClass( \Modelo\NettyImport\Importer::class );
        $method = $ref->getMethod( 'map_status_slug' );
        $method->setAccessible( true );

        $this->assertSame( 'louer', $method->invoke( null, 'location' ) );
        $this->assertSame( 'louer', $method->invoke( null, '' ) );
    }

    /** @test */
    public function is_truthy_feature_value_rejette_non(): void {
        $ref = new \ReflectionClass( \Modelo\NettyImport\Importer::class );
        $method = $ref->getMethod( 'is_truthy_feature_value' );
        $method->setAccessible( true );

        $this->assertFalse( $method->invoke( null, 'non' ) );
        $this->assertFalse( $method->invoke( null, 'Non' ) );
        $this->assertFalse( $method->invoke( null, 'no' ) );
        $this->assertFalse( $method->invoke( null, '0' ) );
        $this->assertFalse( $method->invoke( null, 'false' ) );
        $this->assertFalse( $method->invoke( null, '' ) );
    }

    /** @test */
    public function is_truthy_feature_value_accepte_oui(): void {
        $ref = new \ReflectionClass( \Modelo\NettyImport\Importer::class );
        $method = $ref->getMethod( 'is_truthy_feature_value' );
        $method->setAccessible( true );

        $this->assertTrue( $method->invoke( null, 'oui' ) );
        $this->assertTrue( $method->invoke( null, '1' ) );
        $this->assertTrue( $method->invoke( null, 'yes' ) );
    }

    /** @test */
    public function is_truthy_feature_value_nord_est_est_truthy(): void {
        $ref = new \ReflectionClass( \Modelo\NettyImport\Importer::class );
        $method = $ref->getMethod( 'is_truthy_feature_value' );
        $method->setAccessible( true );

        // Bug fix: "nord-est" starts with "non" but should be truthy.
        $this->assertTrue( $method->invoke( null, 'nord-est' ) );
    }
}
