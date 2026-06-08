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

final class MediaSync {
	public const ATT_SOURCE_URL_META = 'mnti_source_url';

	/**
	 * @param array<int,string> $image_urls
	 * @return array{added:int,deleted:int,kept:int,featured_attachment_id:int|null}
	 */
	public static function sync_gallery( int $run_id, int $post_id, string $reference_technique, array $image_urls ): array {
		$image_urls = array_values( array_unique( array_filter( array_map( 'trim', $image_urls ) ) ) );

		$existing_ids = get_post_meta( $post_id, 'fave_property_images', false );
		$existing_ids = array_values( array_filter( array_map( 'intval', $existing_ids ) ) );

		$featured_id = (int) get_post_thumbnail_id( $post_id );

		$existing_map = [];
		$all_existing = array_unique( array_merge( $existing_ids, $featured_id ? [ $featured_id ] : [] ) );
		foreach ( $all_existing as $att_id ) {
			$src = (string) get_post_meta( $att_id, self::ATT_SOURCE_URL_META, true );
			if ( $src !== '' ) {
				$existing_map[ $src ] = $att_id;
			}
		}

		// Phase 1 : téléchargement parallèle des URLs absentes du cache local.
		$urls_to_download = [];
		foreach ( $image_urls as $url ) {
			if ( ! isset( $existing_map[ $url ] ) ) {
				$urls_to_download[] = $url;
			}
		}
		$downloaded = self::download_urls_parallel( $urls_to_download );

		$added           = 0;
		$kept            = 0;
		$deleted         = 0;
		$new_gallery_ids = [];
		$new_featured_id = null;

		// Phase 2 : sideload séquentiel + constitution de la galerie.
		foreach ( $image_urls as $idx => $url ) {
			if ( isset( $existing_map[ $url ] ) ) {
				$att_id = (int) $existing_map[ $url ];
				++$kept;
			} elseif ( isset( $downloaded[ $url ] ) ) {
				$tmp_or_error = $downloaded[ $url ];
				if ( is_wp_error( $tmp_or_error ) ) {
					Logger::log_error(
						$run_id,
						'download_image_failed',
						$tmp_or_error->get_error_message(),
						[
							'reference_technique' => $reference_technique,
							'post_id'             => $post_id,
							'url'                 => $url,
						]
					);
					continue;
				}
				$att_id = self::sideload_attachment( $run_id, $post_id, $reference_technique, $url, $tmp_or_error );
				@unlink( $tmp_or_error );
				if ( null === $att_id ) {
					continue;
				}
				++$added;
			} else {
				continue;
			}

			if ( 0 === $idx ) {
				$new_featured_id = $att_id;
			}
			$new_gallery_ids[] = $att_id;
		}

		if ( $new_featured_id ) {
			set_post_thumbnail( $post_id, $new_featured_id );
		}

		delete_post_meta( $post_id, 'fave_property_images' );
		foreach ( $new_gallery_ids as $att_id ) {
			add_post_meta( $post_id, 'fave_property_images', (int) $att_id );
		}

		$keep_set = array_fill_keys( $new_gallery_ids, true );
		foreach ( $existing_ids as $att_id ) {
			if ( ! isset( $keep_set[ $att_id ] ) ) {
				wp_delete_attachment( $att_id, true );
				++$deleted;
				Logger::log_info(
					$run_id,
					'delete_image',
					sprintf( 'Suppression image %d (sortie du flux)', $att_id ),
					[
						'reference_technique' => $reference_technique,
						'post_id'             => $post_id,
						'attachment_id'       => $att_id,
					]
				);
			}
		}

		return [
			'added'                  => $added,
			'deleted'                => $deleted,
			'kept'                   => $kept,
			'featured_attachment_id' => $new_featured_id,
		];
	}

	public static function delete_all_attached_media( int $run_id, int $post_id, string $reference_technique ): int {
		$ids = get_post_meta( $post_id, 'fave_property_images', false );
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		$featured_id = (int) get_post_thumbnail_id( $post_id );
		if ( $featured_id ) {
			$ids[] = $featured_id;
		}

		$ids = array_values( array_unique( $ids ) );

		$deleted = 0;
		foreach ( $ids as $att_id ) {
			if ( wp_delete_attachment( $att_id, true ) ) {
				++$deleted;
			}
		}

		Logger::log_info(
			$run_id,
			'delete_all_media',
			sprintf( 'Suppression des médias attachés (%d)', $deleted ),
			[
				'reference_technique' => $reference_technique,
				'post_id'             => $post_id,
			]
		);

		return $deleted;
	}

	/**
	 * Vérifie le MIME, sideloade un fichier temp existant et enregistre la meta source.
	 *
	 * @return int|null attachment ID ou null en cas d'erreur
	 */
	private static function sideload_attachment(
		int $run_id,
		int $post_id,
		string $reference_technique,
		string $url,
		string $tmp_path
	): ?int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename     = wp_basename( parse_url( $url, PHP_URL_PATH ) ?: 'image.jpg' );
		$filetype     = wp_check_filetype_and_ext( $tmp_path, $filename );
		$allowed_exts = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];

		// URL sans extension exploitable (CDN servant l'image via query-string, ex.
		// .../photo?id=123 → basename « photo », ext vide). On détecte alors le vrai MIME
		// depuis le contenu et on reconstruit un nom de fichier extensionné avant rejet.
		if ( ! in_array( $filetype['ext'], $allowed_exts, true ) ) {
			$mime_to_ext   = [
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
			];
			$detected_mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $tmp_path ) : (string) $filetype['type'];
			if ( isset( $mime_to_ext[ $detected_mime ] ) ) {
				$filename = 'netty-' . substr( md5( $url ), 0, 12 ) . '.' . $mime_to_ext[ $detected_mime ];
				$filetype = wp_check_filetype_and_ext( $tmp_path, $filename );
			}
		}

		if ( ! in_array( $filetype['ext'], $allowed_exts, true ) ) {
			Logger::log_error(
				$run_id,
				'invalid_mime',
				sprintf( 'Image rejetée: type non autorisé (%s)', $filetype['type'] ?: 'inconnu' ),
				[
					'reference_technique' => $reference_technique,
					'post_id'             => $post_id,
					'url'                 => $url,
				]
			);
			return null;
		}

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_path,
		];

		$att_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $att_id ) ) {
			Logger::log_error(
				$run_id,
				'media_handle_failed',
				$att_id->get_error_message(),
				[
					'reference_technique' => $reference_technique,
					'post_id'             => $post_id,
					'url'                 => $url,
				]
			);
			return null;
		}

		update_post_meta( $att_id, self::ATT_SOURCE_URL_META, $url );

		Logger::log_info(
			$run_id,
			'add_image',
			sprintf( 'Ajout image %d', $att_id ),
			[
				'reference_technique' => $reference_technique,
				'post_id'             => $post_id,
				'attachment_id'       => $att_id,
				'url'                 => $url,
			]
		);

		return (int) $att_id;
	}

	/**
	 * Télécharge plusieurs URLs en parallèle vers des fichiers temporaires.
	 *
	 * Utilise curl_multi_exec avec un maximum de MNTI_IMAGE_CONCURRENCY (défaut 5) slots simultanés.
	 * Streaming direct vers fichier — pas de spike mémoire.
	 *
	 * @param  array<int,string> $urls
	 * @param  int               $timeout Timeout par requête en secondes
	 * @return array<string, string|\WP_Error> map url => chemin tmp | WP_Error
	 */
	private static function download_urls_parallel( array $urls, int $timeout = 60 ): array {
		if ( empty( $urls ) ) {
			return [];
		}

		// Point d'injection : permet de court-circuiter le téléchargement réseau (tests,
		// ou backend de téléchargement alternatif). Un filtre qui retourne un tableau
		// (map url => chemin tmp | WP_Error) court-circuite curl_multi ; sinon (null) le
		// téléchargement normal s'exécute.
		$pre = apply_filters( 'mnti_pre_download_urls', null, $urls, $timeout );
		if ( is_array( $pre ) ) {
			return $pre;
		}

		$concurrency = defined( 'MNTI_IMAGE_CONCURRENCY' ) ? max( 1, (int) MNTI_IMAGE_CONCURRENCY ) : 5;

		// Taille max d'une image téléchargée, surchargeable dans wp-config.php
		// (define( 'MNTI_MAX_IMAGE_BYTES', n )). Défaut : 20 Mo.
		$max_bytes = defined( 'MNTI_MAX_IMAGE_BYTES' ) ? max( 1, (int) MNTI_MAX_IMAGE_BYTES ) : 20 * 1024 * 1024;

		$results = [];
		$pending = array_values( $urls );
		$active  = []; // (int)$ch => [ 'ch', 'fp', 'tmp', 'url' ]
		$mh      = curl_multi_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- WP HTTP API ne supporte pas le multi-curl.

		$wp_version = (string) get_bloginfo( 'version' );

		/** Ouvre un slot curl pour la prochaine URL en attente. */
		$open_slot = static function () use ( $mh, &$pending, &$active, &$results, $timeout, $wp_version, $max_bytes ): void {
			if ( empty( $pending ) ) {
				return;
			}
			$url    = (string) array_shift( $pending );
			$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
				$results[ $url ] = new \WP_Error( 'invalid_scheme', sprintf( 'Schéma non autorisé : %s', $scheme ) );
				return;
			}

			// Garde SSRF : même validation que wp_safe_remote_get() (refus IP privées /
			// loopback / link-local, ports 80/443/8080), surchargeable via le filtre core
			// http_request_host_is_external. $url reste la clé d'identité (mnti_source_url) ;
			// seule l'URL effectivement requêtée est normalisée plus bas.
			if ( ! wp_http_validate_url( $url ) ) {
				$results[ $url ] = new \WP_Error( 'unsafe_url', sprintf( 'URL refusée (SSRF/IP privée) : %s', $url ) );
				return;
			}

			// http → https même hôte : pré-normalisation locale, car FOLLOWLOCATION est
			// désactivé (cf. plus bas) et une 301 http→https échouerait sinon. On ne mute
			// PAS $url (identité), uniquement l'URL passée à cURL.
			$request_url = preg_replace( '#^http://#i', 'https://', $url );
			$tmp_result  = tempnam( sys_get_temp_dir(), 'mnti_img_' );
			if ( false === $tmp_result ) {
				$results[ $url ] = new \WP_Error( 'tempnam_failed', 'Impossible de créer le fichier temporaire' );
				return;
			}
			$tmp = $tmp_result;
			$fp  = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- écriture streaming vers fichier temp.
			if ( false === $fp ) {
				$results[ $url ] = new \WP_Error( 'fopen_failed', 'Impossible d\'ouvrir le fichier temporaire' );
				@unlink( $tmp );
				return;
			}

			$ch = curl_init( $request_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- WP HTTP API ne supporte pas le multi-curl.
			if ( false === $ch ) {
				fclose( $fp );
				@unlink( $tmp );
				$results[ $url ] = new \WP_Error( 'curl_init_failed', 'curl_init() a échoué' );
				return;
			}
			$opts = [
				CURLOPT_FILE             => $fp,
				CURLOPT_TIMEOUT          => $timeout,
				// FOLLOWLOCATION désactivé : ferme le SSRF par redirection (DNS rebinding
				// vers une IP interne via 3xx). Une redirection renvoie alors un code 3xx
				// non suivi → WP_Error 'http_error' loggué par la boucle principale.
				CURLOPT_FOLLOWLOCATION   => false,
				CURLOPT_USERAGENT        => 'WordPress/' . $wp_version,
				CURLOPT_SSL_VERIFYPEER   => true,
				// Garde-fou taille : MAXFILESIZE coupe si le Content-Length annoncé dépasse
				// le seuil. Comme il n'est fiable que si le serveur l'annonce honnêtement,
				// PROGRESSFUNCTION surveille les octets réellement reçus et avorte le
				// transfert (retour ≠ 0 → CURLE_ABORTED_BY_CALLBACK) au-delà du seuil.
				CURLOPT_MAXFILESIZE      => $max_bytes,
				CURLOPT_NOPROGRESS       => false,
				CURLOPT_PROGRESSFUNCTION => static function ( $ch, $dl_total, $dl_now, $ul_total, $ul_now ) use ( $max_bytes ): int {
					return ( $dl_now > $max_bytes || $dl_total > $max_bytes ) ? 1 : 0;
				},
			];
			// Défense en profondeur : restreindre les protocoles cURL à http/https.
			if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
				$opts[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
			}
			curl_setopt_array( $ch, $opts ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- WP HTTP API ne supporte pas le multi-curl.
			curl_multi_add_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle -- WP HTTP API ne supporte pas le multi-curl.
			$active[ (int) $ch ] = [
				'ch'  => $ch,
				'fp'  => $fp,
				'tmp' => $tmp,
				'url' => $url,
			];
		};

		// Remplissage des slots initiaux.
		$initial_slots = min( $concurrency, count( $pending ) );
		for ( $i = 0; $i < $initial_slots; $i++ ) {
			$open_slot();
		}

		$running = 0;
		do {
			curl_multi_exec( $mh, $running ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_exec -- WP HTTP API ne supporte pas le multi-curl.
			curl_multi_select( $mh, 0.1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_select -- WP HTTP API ne supporte pas le multi-curl.

			while ( false !== ( $info = curl_multi_info_read( $mh ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read -- WP HTTP API ne supporte pas le multi-curl.
				/** @var \CurlHandle|resource $ch */
				$ch   = $info['handle'];
				$id   = (int) $ch;
				$meta = $active[ $id ];

				fclose( $meta['fp'] );
				curl_multi_remove_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle -- WP HTTP API ne supporte pas le multi-curl.

				if ( CURLE_OK !== $info['result'] ) {
					@unlink( $meta['tmp'] );
					if ( ( defined( 'CURLE_ABORTED_BY_CALLBACK' ) && CURLE_ABORTED_BY_CALLBACK === $info['result'] ) || CURLE_FILESIZE_EXCEEDED === $info['result'] ) {
						$results[ $meta['url'] ] = new \WP_Error(
							'file_too_large',
							sprintf( 'Image ignorée : taille supérieure à la limite (%d octets)', $max_bytes )
						);
					} else {
						$results[ $meta['url'] ] = new \WP_Error(
							'curl_error',
							sprintf( 'cURL %d : %s', $info['result'], curl_strerror( $info['result'] ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_strerror -- WP HTTP API ne supporte pas le multi-curl.
						);
					}
				} else {
					$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- WP HTTP API ne supporte pas le multi-curl.
					if ( $http_code < 200 || $http_code >= 300 ) {
						@unlink( $meta['tmp'] );
						$results[ $meta['url'] ] = new \WP_Error(
							'http_error',
							sprintf( 'HTTP %d', $http_code )
						);
					} else {
						$results[ $meta['url'] ] = $meta['tmp'];
					}
				}

				curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- WP HTTP API ne supporte pas le multi-curl.
				unset( $active[ $id ] );
				$open_slot();
			}
		} while ( $running > 0 || ! empty( $active ) );

		curl_multi_close( $mh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- WP HTTP API ne supporte pas le multi-curl.

		return $results;
	}
}
