<?php
/**
 * Ethersys Importer For Modelo Netty
 *
 * @package Ethersys\NettyImport
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Copyright (C) 2026 Ethersys
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2 or later.
 * See the LICENSE file or https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace Ethersys\NettyImport;

defined( 'ABSPATH' ) || exit;

/**
 * Petites compatibilités de traduction pour Houzez quand une chaîne n’est pas
 * présente dans les fichiers .mo (ou quand le thème utilise des chaînes non traduites).
 */
final class ThemeCompat {
	public static function init(): void {
		add_filter( 'gettext', [ __CLASS__, 'gettext' ], 20, 3 );
	}

	public static function gettext( string $translated, string $text, string $domain ): string {
		if ( $domain !== 'houzez' ) {
			return $translated;
		}

		// On remplace "Properties" par "Biens" pour coller au vocabulaire immobilier FR.
		if ( $text === 'Properties' ) {
			return 'Biens';
		}
		if ( $text === 'Property' ) {
			return 'Bien';
		}

		// Archive / listing sorting labels (observés non traduits dans le .mo FR)
		if ( $text === 'Sort order' ) {
			return 'Ordre de tri';
		}
		if ( $text === 'Sort order:' ) {
			return 'Ordre de tri :';
		}

		// Listing / taxonomy UI pieces (observés en anglais sur les archives).
		if ( $text === 'Details' ) {
			return 'Détails';
		}
		if ( $text === 'Bed:' ) {
			return 'Chambre :';
		}
		if ( $text === 'Beds:' ) {
			return 'Chambres :';
		}
		if ( $text === 'Bath:' ) {
			return 'Salle de bain :';
		}
		if ( $text === 'Baths:' ) {
			return 'Salles de bain :';
		}

		// Search results labels
		if ( $text === 'Results Found' ) {
			// Fallback générique (plutôt utilisé sans compteur).
			return 'Biens trouvés';
		}
		if ( $text === '%s Results Found' ) {
			return '%s Biens trouvés';
		}
		if ( $text === 'Result Found' ) {
			return 'Bien trouvé';
		}
		if ( $text === 'No results found' ) {
			return 'Aucun résultat';
		}

		// Search buttons
		if ( $text === 'Go' ) {
			return 'Rechercher';
		}

		// Similar listings block title
		if ( $text === 'Similar Listings' ) {
			return 'Biens similaires';
		}

		// Labels
		if ( $text === 'Featured' ) {
			return 'Coup de Coeur';
		}

		// Sort dropdown options
		if ( $text === 'Price - Low to High' ) {
			return 'Prix - croissant';
		}
		if ( $text === 'Price - High to Low' ) {
			return 'Prix - décroissant';
		}
		if ( $text === 'Featured Listings First' ) {
			return 'Biens mis en avant en premier';
		}
		if ( $text === 'Date - Old to New' ) {
			return 'Date - ancien vers récent';
		}
		if ( $text === 'Date - New to Old' ) {
			return 'Date - récent vers ancien';
		}
		if ( $text === 'Title - ASC' ) {
			return 'Titre - A → Z';
		}
		if ( $text === 'Title - DESC' ) {
			return 'Titre - Z → A';
		}

		return $translated;
	}
}
