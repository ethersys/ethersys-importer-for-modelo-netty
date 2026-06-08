<?php
/**
 * Modelo/Netty to WP Import
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

/**
 * Force des libellés FR (options Houzez) même si Houzez les réinitialise
 * lors d’un changement de modèle / style de recherche.
 */
final class HouzezSearchI18n {
	/**
	 * Valeurs forcées dans houzez_options.
	 *
	 * @return array<string,string>
	 */
	private static function overrides(): array {
		return [
			// Multi-select bootstrap-select
			'cl_select_all'        => 'Tout sélectionner',
			'cl_deselect_all'      => 'Tout désélectionner',
			'cl_items_selected'    => 'éléments sélectionnés',

			// Search labels / placeholders
			'srh_status'           => 'Acheter / Louer',
			'srh_all_status'       => 'Acheter / Louer',
			'srh_type'             => 'Type de biens',
			'srh_location'         => 'Toutes les villes',
			'srh_cities'           => 'Toutes les villes',

			'srh_min_price'        => 'Budget',
			'srh_max_price'        => 'Budget max',

			'srh_clear'            => 'Annuler',
			'srh_apply'            => 'Appliquer',
			'srh_btn_search'       => 'Rechercher',
			'srh_btn_save_search'  => 'Enregistrer la recherche',

			'srh_any'              => 'Indifférent',
			'srh_keyword'          => 'Mot-clé',
			'srh_csa'              => 'Ville, département ou quartier',
			'srh_item_selected'    => 'éléments sélectionnés',

			// Titles used by some layouts
			'srh_dock_title'       => 'Recherche avancée',
			'srh_mobile_title'     => 'Recherche',

			// Labels
			'cl_featured_label'    => 'Coup de Coeur',

			// Search field behavior
			// Force les champs prix en saisie libre (évite le menu déroulant Max Price dans certains templates)
			'price_field_type'     => 'input',

			// Property detail blocks
			'sps_similar_listings' => 'Biens similaires',

			// Listing cards (glc = grid/list card labels)
			'glc_bedrooms'         => 'Chambres',
			'glc_bedroom'          => 'Chambre',
			'glc_bathrooms'        => 'Salles de bain',
			'glc_bathroom'         => 'Salle de bain',
			'glc_rooms'            => 'Pièces',
			'glc_room'             => 'Pièce',

			// Dashboard / submit labels (cl = custom labels)
			'cl_bedrooms'          => 'Chambres',
			'cl_bathrooms'         => 'Salles de bain',
			'cl_rooms'             => 'Pièces',

			// Search labels
			'srh_rooms'            => 'Pièces',

			// Contact form default message
			// Houzez ajoute déjà le titre entre crochets après ce texte, on ne met donc pas de placeholder ici.
			'spl_con_interested'   => 'Bonjour, je suis intéressé(e) par',
		];
	}

	public static function init(): void {
		// À l’exécution: garantit la traduction même si la DB a été écrasée.
		add_filter( 'option_houzez_options', [ __CLASS__, 'filter_option' ], 20 );
		// À la sauvegarde: ré-injecte nos valeurs dans la DB quand Houzez met à jour l’option.
		add_filter( 'pre_update_option_houzez_options', [ __CLASS__, 'pre_update_option' ], 20, 2 );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function filter_option( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		return array_merge( $value, self::overrides() );
	}

	/**
	 * @param mixed $new_value
	 * @param mixed $old_value
	 * @return mixed
	 */
	public static function pre_update_option( $new_value, $old_value ) {
		if ( ! is_array( $new_value ) ) {
			return $new_value;
		}
		return array_merge( $new_value, self::overrides() );
	}
}
