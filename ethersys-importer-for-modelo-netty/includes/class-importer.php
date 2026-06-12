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

final class Importer {
	public const META_REF                = 'eimn_reference_technique';
	public const META_COMPLETE_AT        = 'eimn_import_complete_at';
	private const OPT_DEFAULT_AGENT_ID   = 'eimn_default_agent_id';
	private const OPT_FEED_ETAG          = 'eimn_feed_etag';
	private const OPT_FEED_LAST_MODIFIED = 'eimn_feed_last_modified';
	private const LOCK_OPTION            = 'eimn_import_lock';
	private const STALE_RUN_MINS         = 30;

	private static bool $during_import = false;

	/**
	 * URL du flux Netty (réglage admin). Ne jamais coder d’URL secrète dans le dépôt.
	 */
	public static function get_feed_url(): string {
		return esc_url_raw( trim( (string) get_option( 'eimn_feed_url', '' ) ), [ 'http', 'https' ] );
	}

	public static function is_feed_configured(): bool {
		$url = self::get_feed_url();
		return $url !== '' && (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	public static function is_during_import(): bool {
		return self::$during_import;
	}

	/**
	 * @return array{run_id:int,counts:array<string,int>}
	 */
	public static function run( array $opts = [] ): array {
		$opts = wp_parse_args(
			$opts,
			[
				'dry_run'        => false,
				'sync_images'    => true,
				'delete_missing' => true,
			]
		);

		$counts = [
			'created'          => 0,
			'updated'          => 0,
			'deleted'          => 0,
			'images_added'     => 0,
			'images_deleted'   => 0,
			'errors'           => 0,
			'would_create'     => 0,
			'would_update'     => 0,
			'would_delete'     => 0,
			'would_add_images' => 0,
			'would_del_images' => 0,
		];

		if ( ! self::is_feed_configured() ) {
			$run_id = Logger::start_run( '' );
			Logger::log_error( $run_id, 'no_feed_url', __( 'URL du flux XML non configurée (réglages du plugin).', 'ethersys-importer-for-modelo-netty' ) );
			++$counts['errors'];
			Logger::finish_run_failed( $run_id, __( 'URL du flux non configurée', 'ethersys-importer-for-modelo-netty' ), $counts );
			return [
				'run_id' => $run_id,
				'counts' => $counts,
			];
		}

		$feed_url = self::get_feed_url();

		// 1. Nettoyage des runs interrompus
		self::cleanup_stale_runs();

		$run_id = Logger::start_run( $feed_url );

		// 2. Verrou atomique
		if ( ! add_option( self::LOCK_OPTION, $run_id, '', false ) ) {
			Logger::log_error( $run_id, 'locked', __( 'Import déjà en cours (verrou actif).', 'ethersys-importer-for-modelo-netty' ) );
			Logger::finish_run_failed( $run_id, __( 'Import déjà en cours', 'ethersys-importer-for-modelo-netty' ), $counts );
			return [
				'run_id' => $run_id,
				'counts' => $counts,
			];
		}

		try {
			try {
				$xml = self::fetch_feed();
			} catch ( \RuntimeException $e ) {
				if ( $e->getMessage() === 'FEED_NOT_MODIFIED' ) {
					Logger::log_info( $run_id, 'feed_cache', 'Flux non modifié (304) — run ignoré.' );
					Logger::finish_run_success( $run_id, $counts );
					return [
						'run_id' => $run_id,
						'counts' => $counts,
					];
				}
				throw $e;
			}
			$parsed  = XmlParser::parse( $xml );
			$records = $parsed['records'];

			if ( empty( $records ) ) {
				Logger::log_error( $run_id, 'feed_empty', 'Flux vide: aucune suppression ne sera effectuée.' );
				++$counts['errors'];
				Logger::finish_run_failed( $run_id, 'Flux vide', $counts );
				return [
					'run_id' => $run_id,
					'counts' => $counts,
				];
			}

			$seen_refs = [];
			$ref_index = self::load_ref_index();

			// post_id touchés (créés/maj/supprimés) — sert à rejouer les invalidations
			// de cache objet court-circuitées par wp_suspend_cache_invalidation() ci-dessous.
			$touched_post_ids = [];

			self::$during_import = true;
			wp_defer_term_counting( true );
			wp_defer_comment_counting( true );
			wp_suspend_cache_invalidation( true );

			try {
				foreach ( $records as $rec ) {
					$ref = (string) ( $rec['reference_technique'] ?? '' );
					if ( $ref === '' ) {
						++$counts['errors'];
						Logger::log_error( $run_id, 'missing_reference', 'Bien ignoré: reference_technique manquante', [ 'record' => $rec ] );
						continue;
					}
					$seen_refs[ $ref ] = true;

					$post_id = $ref_index[ $ref ] ?? 0;
					$is_new  = ! $post_id;

					if ( $opts['dry_run'] ) {
						if ( $is_new ) {
							++$counts['would_create'];
							$counts['would_add_images'] += count( array_filter( array_map( 'trim', (array) ( $rec['images'] ?? [] ) ) ) );
						} else {
							++$counts['would_update'];
							// Estimate images to add: those not yet downloaded
							$existing_urls = self::get_existing_image_urls( $post_id );
							$new_urls      = array_filter( array_map( 'trim', (array) ( $rec['images'] ?? [] ) ) );
							foreach ( $new_urls as $img_url ) {
								if ( ! in_array( $img_url, $existing_urls, true ) ) {
									++$counts['would_add_images'];
								}
							}
						}
						Logger::log_info(
							$run_id,
							'dry_run',
							$is_new ? 'Créerait le bien' : 'Mettrait à jour le bien',
							[
								'reference_technique' => $ref,
								'post_id'             => $post_id,
							]
						);
						continue;
					}

					$post_id                      = self::upsert_property( $run_id, $post_id, $rec );
					$ref_index[ $ref ]            = $post_id;
					$touched_post_ids[ $post_id ] = true;

					if ( $is_new ) {
						++$counts['created'];
					} else {
						++$counts['updated'];
					}

					// Images
					if ( $opts['sync_images'] ) {
						$res                       = MediaSync::sync_gallery( $run_id, $post_id, $ref, (array) ( $rec['images'] ?? [] ) );
						$counts['images_added']   += (int) $res['added'];
						$counts['images_deleted'] += (int) $res['deleted'];
					}
				}

				// Delete missing
				if ( $opts['delete_missing'] ) {
					if ( $opts['dry_run'] ) {
						foreach ( $ref_index as $ref => $post_id ) {
							if ( ! isset( $seen_refs[ $ref ] ) ) {
								++$counts['would_delete'];
							}
						}
					} else {
						foreach ( $ref_index as $idx_ref => $idx_pid ) {
							if ( ! isset( $seen_refs[ $idx_ref ] ) ) {
								$touched_post_ids[ (int) $idx_pid ] = true;
							}
						}
						$counts['deleted'] += self::delete_missing_properties( $run_id, $ref_index, $seen_refs );
					}
				}
			} finally {
				wp_suspend_cache_invalidation( false );
				wp_defer_comment_counting( false );
				wp_defer_term_counting( false );
				self::$during_import = false;

				// Rejoue les invalidations supprimées pendant la boucle. Dans le finally
				// pour couvrir aussi les biens déjà écrits avant un crash partiel.
				self::flush_object_cache_after_import( $touched_post_ids );
			}

			Logger::finish_run_success( $run_id, $counts );

			/**
			 * Permet aux caches tiers (cache page : WP Rocket, W3TC, LiteSpeed…) de purger
			 * après un import. Le plugin ne gère en dur que le cache objet core de WordPress.
			 *
			 * @param array<string,int> $counts           Compteurs du run.
			 * @param int[]             $touched_post_ids  IDs des biens créés/maj/supprimés.
			 */
			do_action( 'eimn_after_import', $counts, array_keys( $touched_post_ids ) );
		} catch ( \Throwable $e ) {
			++$counts['errors'];
			Logger::log_error( $run_id, 'run_failed', $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
			Logger::finish_run_failed( $run_id, $e->getMessage(), $counts );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		return [
			'run_id' => $run_id,
			'counts' => $counts,
		];
	}

	/**
	 * Rejoue les invalidations de cache objet court-circuitées par
	 * wp_suspend_cache_invalidation() pendant la boucle d'import.
	 *
	 * No-op si aucun changement ou si le cache objet n'est pas persistant
	 * (en mode non-persistant le cache meurt avec la requête : rien à purger).
	 * Ciblé (par post + par taxonomie) : jamais de wp_cache_flush() global.
	 *
	 * @param array<int,bool> $touched_post_ids IDs touchés en clés.
	 */
	private static function flush_object_cache_after_import( array $touched_post_ids ): void {
		if ( empty( $touched_post_ids ) || ! wp_using_ext_object_cache() ) {
			return;
		}

		foreach ( array_keys( $touched_post_ids ) as $post_id ) {
			clean_post_cache( (int) $post_id );
		}

		foreach ( [ 'property_city', 'property_type', 'property_status', 'property_feature' ] as $taxonomy ) {
			clean_taxonomy_cache( $taxonomy );
		}
	}

	private static function cleanup_stale_runs(): void {
		global $wpdb;
		$runs_table = Db::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- UPDATE on custom table; table name from Db::runs_table(), not user input.
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::runs_table(), not user input.
				"UPDATE {$runs_table}
				 SET status = 'failed',
				     error_message = 'Run interrompu (timeout détecté au démarrage)',
				     finished_at = %s
				 WHERE status = 'running'
				   AND started_at < %s",
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_RUN_MINS * MINUTE_IN_SECONDS )
			)
		);
		if ( $affected && $affected > 0 ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	private static function fetch_feed(): string {
		$url = self::get_feed_url();
		if ( ! self::is_feed_configured() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not rendered output.
			throw new \RuntimeException( __( 'URL du flux invalide ou vide.', 'ethersys-importer-for-modelo-netty' ) );
		}

		$headers = [
			'User-Agent' => 'Modelo-Netty-Importer/' . EIMN_VERSION,
		];

		$etag          = (string) get_option( self::OPT_FEED_ETAG, '' );
		$last_modified = (string) get_option( self::OPT_FEED_LAST_MODIFIED, '' );
		if ( $etag !== '' ) {
			$headers['If-None-Match'] = $etag;
		} elseif ( $last_modified !== '' ) {
			$headers['If-Modified-Since'] = $last_modified;
		}

		$res = wp_remote_get(
			$url,
			[
				'timeout'     => 60,
				'redirection' => 5,
				'headers'     => $headers,
			]
		);

		if ( is_wp_error( $res ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not rendered output.
			throw new \RuntimeException( $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( $code === 304 ) {
			throw new \RuntimeException( 'FEED_NOT_MODIFIED' );
		}

		if ( $code < 200 || $code >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not rendered output.
			throw new \RuntimeException( 'HTTP ' . $code . ' en téléchargeant le flux' );
		}

		$body = (string) wp_remote_retrieve_body( $res );
		if ( trim( $body ) === '' ) {
			throw new \RuntimeException( 'Flux vide' );
		}

		// Store ETag / Last-Modified for next run
		$new_etag = wp_remote_retrieve_header( $res, 'etag' );
		$new_lm   = wp_remote_retrieve_header( $res, 'last-modified' );
		if ( is_string( $new_etag ) && $new_etag !== '' ) {
			update_option( self::OPT_FEED_ETAG, $new_etag, false );
			delete_option( self::OPT_FEED_LAST_MODIFIED );
		} elseif ( is_string( $new_lm ) && $new_lm !== '' ) {
			update_option( self::OPT_FEED_LAST_MODIFIED, $new_lm, false );
			delete_option( self::OPT_FEED_ETAG );
		}

		return $body;
	}

	/**
	 * Charge en une requête l'index ref → post_id pour tous les biens importés.
	 *
	 * @return array<string,int>
	 */
	private static function load_ref_index(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk index load at run start; caching would return stale data across import runs.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_REF
			),
			ARRAY_A
		);
		$index = [];
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['meta_value'], $row['post_id'] ) && $row['meta_value'] !== '' ) {
				$index[ (string) $row['meta_value'] ] = (int) $row['post_id'];
			}
		}
		return $index;
	}

	/**
	 * @param array<string,mixed> $rec
	 */
	private static function upsert_property( int $run_id, int $post_id, array $rec ): int {
		$ref = (string) $rec['reference_technique'];

		$raw_content = wp_kses_post( (string) ( $rec['description'] ?? '' ) );
		// Nettoyage léger pour améliorer l’affichage des interlignes:
		// - le flux contient des <br> (souvent en série). On les convertit en sauts de ligne,
		// puis on laisse WordPress générer les paragraphes via wpautop.
		$normalized   = preg_replace( '#<\s*br\s*/?>#i', "\n", $raw_content );
		$normalized   = html_entity_decode( (string) $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$normalized   = preg_replace( "/\n{3,}/", "\n\n", (string) $normalized );
		$normalized   = trim( (string) $normalized );
		$post_content = wpautop( $normalized );

		$postarr = [
			'ID'           => $post_id ?: 0,
			'post_type'    => 'property',
			'post_status'  => $post_id ? get_post_status( $post_id ) : 'publish',
			'post_title'   => (string) ( $rec['titre'] ?? '' ),
			'post_content' => $post_content,
		];

		// Check if title/content have actually changed before calling wp_insert_post
		$needs_write = true;
		if ( $post_id > 0 ) {
			$existing_post = get_post( $post_id );
			$new_title     = (string) ( $rec['titre'] ?? '' );
			if (
				$existing_post instanceof \WP_Post &&
				$existing_post->post_title === $new_title &&
				$existing_post->post_content === $post_content
			) {
				$needs_write = false;
			}
		}

		if ( $needs_write ) {
			$new_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $new_id ) ) {
				Logger::log_error(
					$run_id,
					'upsert_failed',
					$new_id->get_error_message(),
					[
						'reference_technique' => $ref,
						'post_id'             => $post_id,
					]
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not rendered output.
				throw new \RuntimeException( $new_id->get_error_message() );
			}
			$is_new  = ( $post_id === 0 );
			$post_id = (int) $new_id;
		} else {
			$is_new = false;
			// $post_id unchanged — title/content identical, skip wp_insert_post
		}
		update_post_meta( $post_id, self::META_REF, $ref );

		// Taxonomies
		self::ensure_status_terms( $run_id, $ref );
		self::assign_taxonomy_term( $run_id, $post_id, 'property_status', self::map_status_slug( (string) ( $rec['type_annonce'] ?? '' ) ), $ref );
		self::assign_taxonomy_term( $run_id, $post_id, 'property_type', self::map_property_type( (string) ( $rec['type_prod'] ?? '' ) ), $ref, true );
		self::assign_taxonomy_term( $run_id, $post_id, 'property_city', (string) ( $rec['ville'] ?? '' ), $ref, true );
		self::apply_features_from_record( $run_id, $post_id, $rec, $ref );

		// Champs "o/O" supplémentaires (debug / exploitation)
		update_post_meta( $post_id, 'eimn_reference_a_afficher', (string) ( $rec['reference_a_afficher'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_type_annonce', (string) ( $rec['type_annonce'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_etat', (string) ( $rec['etat'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_mise_en_avant', (string) ( $rec['mise_en_avant'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_dpe_etat', (string) ( $rec['dpe_etat'] ?? '' ) );

		// Mise en avant (Houzez) — mappe le champ Netty vers le "Featured" natif du thème.
		// Houzez utilise la meta `fave_featured` (0/1) pour afficher les biens mis en avant
		// et pour les widgets/tri "featured".
		$raw_featured = trim( (string) ( $rec['mise_en_avant'] ?? '' ) );
		$is_featured  = self::is_truthy_feature_value( $raw_featured ) ? 1 : 0;
		update_post_meta( $post_id, 'fave_featured', (string) $is_featured );

		// Core Houzez metas
		update_post_meta( $post_id, 'fave_property_zip', (string) ( $rec['code_postal'] ?? '' ) );
		self::set_meta_number( $post_id, 'fave_property_size', (string) ( $rec['surface_habitable'] ?? '' ) );
		self::set_meta_number( $post_id, 'fave_property_land', (string) ( $rec['surface_terrain'] ?? '' ) );
		self::set_meta_number( $post_id, 'fave_property_bedrooms', (string) ( $rec['nb_chambre'] ?? '' ) );

		$bath = (int) ( $rec['nb_sdb'] ?? 0 ) + (int) ( $rec['nb_sde'] ?? 0 );
		update_post_meta( $post_id, 'fave_property_bathrooms', (string) $bath );
		self::set_meta_number( $post_id, 'fave_property_rooms', (string) ( $rec['nb_piece'] ?? '' ) );

		// Price
		$type = strtolower( trim( (string) ( $rec['type_annonce'] ?? '' ) ) );
		if ( 'location' === $type ) {
			self::set_meta_number( $post_id, 'fave_property_price', (string) ( $rec['loyer'] ?? '' ) );
			$charges = (float) str_replace( ',', '.', (string) ( $rec['charges'] ?? '' ) );
			// Houzez ajoute souvent son propre séparateur (ex: "/") entre prix et postfix.
			// On évite donc de commencer le postfix par "/" pour ne pas afficher "//mois".
			$postfix = 'mois';
			if ( $charges <= 0 ) {
				$postfix .= ' CC';
			}
			update_post_meta( $post_id, 'fave_property_price_postfix', $postfix );
		} else {
			self::set_meta_number( $post_id, 'fave_property_price', (string) ( $rec['prix'] ?? '' ) );
			update_post_meta( $post_id, 'fave_property_price_postfix', '' );
		}

		// Custom metas for CSV fields without Houzez key (V1)
		update_post_meta( $post_id, 'eimn_charges', (string) ( $rec['charges'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_depot_garantie', (string) ( $rec['depot_garantie'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_honoraires_visite_dossier', (string) ( $rec['honoraires_visite_dossier'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_honoraires_etat_lieux', (string) ( $rec['honoraires_etat_lieux'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_honoraires_locataire', (string) ( $rec['honoraires_locataire'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_wc', (string) ( $rec['wc'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_type_cuisine', (string) ( $rec['type_cuisine'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_type_chauffage', (string) ( $rec['type_chauffage'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_chauffages', (string) ( $rec['chauffages'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_climatisations', (string) ( $rec['climatisations'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_ameublement', (string) ( $rec['ameublement'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_stationnement_interne', (string) ( $rec['stationnement_interne'] ?? '' ) );
		update_post_meta( $post_id, 'eimn_stationnement_externe', (string) ( $rec['stationnement_externe'] ?? '' ) );

		// DPE/GES (Houzez)
		update_post_meta( $post_id, 'fave_energy_class', (string) ( $rec['bilan_energie'] ?? '' ) );
		update_post_meta( $post_id, 'fave_ghg_emissions_class', (string) ( $rec['bilan_ges'] ?? '' ) );
		update_post_meta( $post_id, 'fave_ghg_emissions_index', (string) ( $rec['valeur_ges'] ?? '' ) );
		update_post_meta( $post_id, 'fave_diagnostic_date', (string) ( $rec['dpe_date_realisation'] ?? '' ) );

		// Contact / formulaire agent (Houzez)
		// Tous les biens importés doivent utiliser le même agent (si configuré).
		// Sinon fallback: auteur du post.
		self::apply_default_contact_agent( $post_id );

		// DPE/GES (ImmoWP defaults)
		$is_effectue = ( strtolower( trim( (string) ( $rec['dpe_etat'] ?? '' ) ) ) === 'effectué' ) || ( strtolower( trim( (string) ( $rec['dpe_etat'] ?? '' ) ) ) === 'effectue' );
		update_post_meta( $post_id, 'dpeNumber', $is_effectue ? (string) ( $rec['valeur_energie'] ?? '' ) : '0' );
		update_post_meta( $post_id, 'gesNumber', $is_effectue ? (string) ( $rec['valeur_ges'] ?? '' ) : '0' );
		update_post_meta( $post_id, 'montantEnergieMin', (string) ( $rec['dpe_cout_min_conso'] ?? '' ) );
		update_post_meta( $post_id, 'montantEnergieMax', (string) ( $rec['dpe_cout_max_conso'] ?? '' ) );
		update_post_meta( $post_id, 'dateDpe', (string) ( $rec['dpe_date_realisation'] ?? '' ) );
		update_post_meta( $post_id, 'dateDpeReference', (string) ( $rec['dpe_annee_reference_conso'] ?? '' ) );
		update_post_meta( $post_id, 'surfaceHabitable', (string) ( $rec['surface_habitable'] ?? '' ) );
		update_post_meta( $post_id, 'soumisDpe', 'true' );

		// Géolocalisation Houzez
		$lat = trim( (string) ( $rec['latitude'] ?? '' ) );
		$lng = trim( (string) ( $rec['longitude'] ?? '' ) );
		if ( $lat !== '' && $lng !== '' ) {
			update_post_meta( $post_id, 'houzez_geolocation_lat', $lat );
			update_post_meta( $post_id, 'houzez_geolocation_long', $lng );
		}
		// Adresse carte (ville + code_postal)
		$city = trim( (string) ( $rec['ville'] ?? '' ) );
		$zip  = trim( (string) ( $rec['code_postal'] ?? '' ) );
		if ( $city !== '' ) {
			$map_address = $zip !== '' ? "{$zip} {$city}, France" : "{$city}, France";
			update_post_meta( $post_id, 'fave_property_map_address', $map_address );
		}

		update_post_meta( $post_id, self::META_COMPLETE_AT, gmdate( 'Y-m-d H:i:s' ) );

		Logger::log_info(
			$run_id,
			$is_new ? 'create' : 'upsert',
			$is_new ? 'Bien créé' : 'Bien mis à jour',
			[
				'reference_technique' => $ref,
				'post_id'             => $post_id,
			]
		);

		return $post_id;
	}

	private static function apply_default_contact_agent( int $post_id ): void {
		$agent_id = (int) get_option( self::OPT_DEFAULT_AGENT_ID, 0 );
		if ( $agent_id > 0 ) {
			update_post_meta( $post_id, 'fave_agent_display_option', 'agent_info' );
			// If multiple rows exist (from a previous bug), delete all first.
			$existing = get_post_meta( $post_id, 'fave_agents', false );
			if ( count( $existing ) > 1 ) {
				delete_post_meta( $post_id, 'fave_agents' );
				add_post_meta( $post_id, 'fave_agents', (string) $agent_id );
			} else {
				update_post_meta( $post_id, 'fave_agents', (string) $agent_id );
			}
			return;
		}
		update_post_meta( $post_id, 'fave_agent_display_option', 'author_info' );
	}

	/**
	 * Champs "o" qui sont plutôt des "caractéristiques" affichables côté Houzez.
	 *
	 * @param array<string,mixed> $rec
	 */
	private static function apply_features_from_record( int $run_id, int $post_id, array $rec, string $ref ): void {
		$feature_map = [
			'cave'                  => 'Cave',
			'piscine'               => 'Piscine',
			'climatisations'        => 'Climatisation',
			'ameublement'           => 'Meublé',
			'stationnement_interne' => 'Parking',
			'stationnement_externe' => 'Parking extérieur',
		];

		$desired = [];
		foreach ( $feature_map as $key => $label ) {
			$raw = trim( (string) ( $rec[ $key ] ?? '' ) );
			if ( ! self::is_truthy_feature_value( $raw ) ) {
				continue;
			}
			$desired[] = $label;
		}

		// Sync (add/remove) only the features we manage, without touching others.
		$managed_labels = array_values( $feature_map );
		$existing       = wp_get_post_terms( $post_id, 'property_feature', [ 'fields' => 'names' ] );
		$kept           = array_values( array_diff( (array) $existing, $managed_labels ) );
		$final          = array_values( array_unique( array_merge( $kept, $desired ) ) );

		// Ensure terms exist
		foreach ( $final as $term_name ) {
			$exists = term_exists( $term_name, 'property_feature' );
			if ( ! $exists ) {
				$created = wp_insert_term( $term_name, 'property_feature' );
				if ( is_wp_error( $created ) ) {
					Logger::log_error(
						$run_id,
						'term_create_failed',
						$created->get_error_message(),
						[
							'taxonomy'            => 'property_feature',
							'term'                => $term_name,
							'reference_technique' => $ref,
							'post_id'             => $post_id,
						]
					);
				}
			}
		}

		wp_set_object_terms( $post_id, $final, 'property_feature', false );
	}

	private static function is_truthy_feature_value( string $value ): bool {
		$v = strtolower( remove_accents( trim( $value ) ) );
		if ( $v === '' ) {
			return false;
		}
		if ( in_array( $v, [ '0', 'non', 'n', 'no', 'false' ], true ) ) {
			return false;
		}
		// "Non meublé", "non disponible", etc. — "non" suivi d'un espace.
		// str_starts_with($v, 'non') seul rejetterait "nord-est" à tort.
		if ( str_starts_with( $v, 'non ' ) ) {
			return false;
		}
		return true;
	}

	private static function map_status_slug( string $type_annonce ): string {
		$t = strtolower( trim( $type_annonce ) );
		// User vocabulary:
		// - status must be Louer/Acheter (location/vente)
		return ( $t === 'vente' ) ? 'acheter' : 'louer';
	}

	private static function map_property_type( string $type_prod ): string {
		$t = trim( $type_prod );
		if ( $t === '' ) {
			return '';
		}
		$norm = strtolower( remove_accents( $t ) );

		// Guard rails: these belong to "status", never to "type".
		$bad = [ 'location', 'vente', 'louer', 'acheter', 'rent', 'sale' ];
		if ( in_array( $norm, $bad, true ) ) {
			return '';
		}

		return $t;
	}

	private static function ensure_status_terms( int $run_id, string $ref ): void {
		self::ensure_term_by_slug( $run_id, 'property_status', 'louer', 'Louer', $ref );
		self::ensure_term_by_slug( $run_id, 'property_status', 'acheter', 'Acheter', $ref );
	}

	private static function ensure_term_by_slug( int $run_id, string $taxonomy, string $slug, string $name, string $ref ): void {
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing ) {
			return;
		}
		$created = wp_insert_term(
			$name,
			$taxonomy,
			[
				'slug' => $slug,
			]
		);
		if ( is_wp_error( $created ) ) {
			Logger::log_error(
				$run_id,
				'term_create_failed',
				$created->get_error_message(),
				[
					'taxonomy'            => $taxonomy,
					'term'                => $name,
					'slug'                => $slug,
					'reference_technique' => $ref,
				]
			);
		}
	}

	private static function assign_taxonomy_term( int $run_id, int $post_id, string $taxonomy, string $term, string $ref, bool $create_if_missing = false, bool $append = false ): void {
		$term = trim( $term );
		if ( $term === '' ) {
			return;
		}

		if ( $create_if_missing ) {
			$existing = term_exists( $term, $taxonomy );
			if ( ! $existing ) {
				$created = wp_insert_term( $term, $taxonomy );
				if ( is_wp_error( $created ) ) {
					Logger::log_error(
						$run_id,
						'term_create_failed',
						$created->get_error_message(),
						[
							'taxonomy'            => $taxonomy,
							'term'                => $term,
							'reference_technique' => $ref,
							'post_id'             => $post_id,
						]
					);
					return;
				}
			}
			wp_set_object_terms( $post_id, [ $term ], $taxonomy, $append );
			return;
		}

		// For property_status we set by slug if possible.
		$term_obj = get_term_by( 'slug', $term, $taxonomy );
		if ( $term_obj ) {
			wp_set_object_terms( $post_id, [ (int) $term_obj->term_id ], $taxonomy, false );
		} else {
			wp_set_object_terms( $post_id, [ $term ], $taxonomy, false );
		}
	}

	private static function set_meta_number( int $post_id, string $meta_key, string $value ): void {
		$value = trim( $value );
		if ( $value === '' ) {
			update_post_meta( $post_id, $meta_key, '' );
			return;
		}
		$value = str_replace( ',', '.', $value );
		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * @return array<int,string>
	 */
	private static function get_existing_image_urls( int $post_id ): array {
		$ids  = get_post_meta( $post_id, 'fave_property_images', false );
		$ids  = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$urls = [];
		foreach ( $ids as $att_id ) {
			$src = (string) get_post_meta( $att_id, MediaSync::ATT_SOURCE_URL_META, true );
			if ( $src !== '' ) {
				$urls[] = $src;
			}
		}
		return $urls;
	}

	/**
	 * @param array<string,int>  $ref_index  Index ref → post_id (déjà chargé)
	 * @param array<string,bool> $seen_refs  Refs vues dans ce run
	 */
	private static function delete_missing_properties( int $run_id, array $ref_index, array $seen_refs ): int {
		$to_delete = [];
		foreach ( $ref_index as $ref => $post_id ) {
			if ( ! isset( $seen_refs[ $ref ] ) ) {
				$to_delete[] = [
					'ref'     => $ref,
					'post_id' => $post_id,
				];
			}
		}

		$deleted = 0;
		foreach ( array_chunk( $to_delete, 100 ) as $chunk ) {
			foreach ( $chunk as $item ) {
				MediaSync::delete_all_attached_media( $run_id, $item['post_id'], $item['ref'] );
				wp_delete_post( $item['post_id'], true );
				++$deleted;
				Logger::log_info(
					$run_id,
					'delete_property',
					'Bien supprimé (absent du flux)',
					[
						'reference_technique' => $item['ref'],
						'post_id'             => $item['post_id'],
					]
				);
			}
		}

		return $deleted;
	}
}
