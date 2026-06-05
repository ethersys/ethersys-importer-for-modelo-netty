<?php
/**
 * Plugin Name: Houzez Stub (Test)
 * Description: Enregistre les post types et taxonomies Houzez pour les tests locaux sans licence.
 * Version:     1.0.0
 */

declare( strict_types=1 );

add_action( 'init', static function (): void {
    register_post_type( 'property', [
        'public'   => true,
        'label'    => 'Propriétés',
        'supports' => [ 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ],
        'rewrite'  => [ 'slug' => 'property' ],
    ] );

    register_post_type( 'houzez_agent', [
        'public'   => true,
        'label'    => 'Agents',
        'supports' => [ 'title', 'thumbnail', 'custom-fields' ],
        'rewrite'  => [ 'slug' => 'agent' ],
    ] );

    $taxonomies = [
        'property_status'  => 'Statuts',
        'property_type'    => 'Types de bien',
        'property_city'    => 'Villes',
        'property_feature' => 'Caractéristiques',
    ];

    foreach ( $taxonomies as $slug => $label ) {
        register_taxonomy( $slug, [ 'property' ], [
            'public'            => true,
            'label'             => $label,
            'hierarchical'      => false,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => $slug ],
        ] );
    }
} );

add_action( 'add_meta_boxes', static function (): void {
    add_meta_box(
        'mnti_gallery_preview',
        'Galerie importée (fave_property_images)',
        static function ( WP_Post $post ): void {
            $ids      = get_post_meta( $post->ID, 'fave_property_images' );
            $featured = (int) get_post_thumbnail_id( $post->ID );
            if ( empty( $ids ) ) {
                echo '<p style="color:#999">Aucune image importée.</p>';
                return;
            }
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px;padding:8px">';
            foreach ( $ids as $id ) {
                $id     = (int) $id;
                $thumb  = wp_get_attachment_image( $id, [ 120, 90 ] );
                $border = ( $id === $featured ) ? 'border:3px solid #2271b1;' : 'border:3px solid transparent;';
                $badge  = ( $id === $featured ) ? '<small style="color:#2271b1;font-weight:bold">★ à la une</small>' : '<small style="color:#999">#' . $id . '</small>';
                echo '<div style="text-align:center;' . $border . 'border-radius:4px;padding:2px">' . $thumb . '<br>' . $badge . '</div>';
            }
            echo '</div>';
        },
        'property',
        'normal',
        'high'
    );
} );
