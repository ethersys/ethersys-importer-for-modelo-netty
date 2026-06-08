<?php
/**
 * Modelo Netty Importer
 *
 * @package Modelo\NettyImport
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Copyright (C) 2026 Ethersys
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2 or later.
 * See the LICENSE file or https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace Modelo\NettyImport;

defined( 'ABSPATH' ) || exit;

final class XmlParser {
	/**
	 * @return array{records: array<int,array<string,mixed>>}
	 */
	public static function parse( string $xml_string ): array {
		$prev = libxml_use_internal_errors( true );
		try {
			$xml = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NOCDATA );
			if ( $xml === false ) {
				$err = libxml_get_last_error();
				throw new \RuntimeException( $err ? trim( $err->message ) : 'XML invalide' );
			}

			$records = [];
			if ( isset( $xml->bien ) ) {
				foreach ( $xml->bien as $bien ) {
					$records[] = self::parse_bien( $bien );
				}
			}

			return [ 'records' => $records ];
		} finally {
			libxml_use_internal_errors( $prev );
			libxml_clear_errors();
		}
	}

	/**
	 * @param \SimpleXMLElement $bien
	 * @return array<string,mixed>
	 */
	private static function parse_bien( \SimpleXMLElement $bien ): array {
		$get = static function ( string $key ) use ( $bien ): string {
			return isset( $bien->{$key} ) ? trim( (string) $bien->{$key} ) : '';
		};

		$images = [];
		if ( isset( $bien->images ) && isset( $bien->images->image ) ) {
			foreach ( $bien->images->image as $img ) {
				$url = trim( (string) $img );
				if ( $url !== '' ) {
					$images[] = $url;
				}
			}
		}

		return [
			'reference_technique'       => $get( 'reference_technique' ),
			'reference_a_afficher'      => $get( 'reference_a_afficher' ),
			'type_annonce'              => $get( 'type_annonce' ),
			'type_prod'                 => $get( 'type_prod' ),
			'etat'                      => $get( 'etat' ),
			'mise_en_avant'             => $get( 'mise_en_avant' ),
			'titre'                     => $get( 'titre' ),
			'description'               => $get( 'description' ),
			'code_postal'               => $get( 'code_postal' ),
			'ville'                     => $get( 'ville' ),
			'latitude'                  => $get( 'latitude' ),
			'longitude'                 => $get( 'longitude' ),
			'surface_habitable'         => $get( 'surface_habitable' ),
			'surface_terrain'           => $get( 'surface_terrain' ),
			'nb_piece'                  => $get( 'nb_piece' ),
			'nb_chambre'                => $get( 'nb_chambre' ),
			'nb_sdb'                    => $get( 'nb_sdb' ),
			'nb_sde'                    => $get( 'nb_sde' ),
			'wc'                        => $get( 'wc' ),
			'cave'                      => $get( 'cave' ),
			'piscine'                   => $get( 'piscine' ),
			'stationnement_interne'     => $get( 'stationnement_interne' ),
			'stationnement_externe'     => $get( 'stationnement_externe' ),
			'type_cuisine'              => $get( 'type_cuisine' ),
			'type_chauffage'            => $get( 'type_chauffage' ),
			'chauffages'                => $get( 'chauffages' ),
			'climatisations'            => $get( 'climatisations' ),
			'ameublement'               => $get( 'ameublement' ),
			'loyer'                     => $get( 'loyer' ),
			'charges'                   => $get( 'charges' ),
			'prix'                      => $get( 'prix' ),
			'depot_garantie'            => $get( 'depot_garantie' ),
			'honoraires_visite_dossier' => $get( 'honoraires_visite_dossier' ),
			'honoraires_etat_lieux'     => $get( 'honoraires_etat_lieux' ),
			'honoraires_locataire'      => $get( 'honoraires_locataire' ),

			'dpe_etat'                  => $get( 'dpe_etat' ),
			'bilan_energie'             => $get( 'bilan_energie' ),
			'valeur_energie'            => $get( 'valeur_energie' ),
			'bilan_ges'                 => $get( 'bilan_ges' ),
			'valeur_ges'                => $get( 'valeur_ges' ),
			'dpe_date_realisation'      => $get( 'dpe_date_realisation' ),
			'dpe_cout_min_conso'        => $get( 'dpe_cout_min_conso' ),
			'dpe_cout_max_conso'        => $get( 'dpe_cout_max_conso' ),
			'dpe_annee_reference_conso' => $get( 'dpe_annee_reference_conso' ),

			'images'                    => $images,
		];
	}
}
