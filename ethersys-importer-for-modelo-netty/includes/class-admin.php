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

final class Admin {
	private const MENU_SLUG             = 'nti-import';
	private const OPT_FEED_URL          = 'eimn_feed_url';
	private const OPT_SCHEDULE_INTERVAL = 'eimn_schedule_interval';
	private const OPT_SCHEDULE_UNIT     = 'eimn_schedule_unit';
	private const OPT_DEFAULT_AGENT_ID  = 'eimn_default_agent_id';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_post_eimn_run_import', [ __CLASS__, 'handle_run_import' ] );
		add_action( 'admin_post_eimn_save_settings', [ __CLASS__, 'handle_save_settings' ] );
		add_action( 'admin_post_eimn_test_feed', [ __CLASS__, 'handle_test_feed' ] );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Import Immo', 'ethersys-importer-for-modelo-netty' ),
			__( 'Import Immo', 'ethersys-importer-for-modelo-netty' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-update'
		);
	}

	public static function handle_test_feed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ethersys-importer-for-modelo-netty' ) );
		}
		check_admin_referer( 'eimn_test_feed' );

		self::redirect_test_result( self::run_feed_test( Importer::get_feed_url() ) );
	}

	/**
	 * Teste une URL de flux (connexion + parsing) sans rien écrire en base.
	 *
	 * @return array{status:string,count:int,message:string}
	 */
	private static function run_feed_test( string $url ): array {
		$count = 0;

		try {
			if ( $url === '' ) {
				throw new \RuntimeException( __( 'URL du flux non configurée.', 'ethersys-importer-for-modelo-netty' ) );
			}

			$res = wp_remote_get(
				$url,
				[
					'timeout'    => 30,
					'user-agent' => 'Modelo-Netty-Importer/' . EIMN_VERSION,
				]
			);

			if ( is_wp_error( $res ) ) {
				throw new \RuntimeException( $res->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code < 200 || $code >= 300 ) {
				throw new \RuntimeException( 'HTTP ' . $code );
			}

			$body = (string) wp_remote_retrieve_body( $res );
			if ( trim( $body ) === '' ) {
				throw new \RuntimeException( __( 'Corps de réponse vide.', 'ethersys-importer-for-modelo-netty' ) );
			}

			$parsed = XmlParser::parse( $body );
			$count  = count( $parsed['records'] );
		} catch ( \Throwable $e ) {
			return [
				'status'  => 'test_fail',
				'count'   => 0,
				'message' => $e->getMessage(),
			];
		}

		return [
			'status'  => 'test_ok',
			'count'   => $count,
			'message' => '',
		];
	}

	/**
	 * Redirige vers l'écran admin avec le résultat d'un test de flux.
	 *
	 * @param array{status:string,count:int,message:string} $result
	 */
	private static function redirect_test_result( array $result ): void {
		$args = [
			'page'       => self::MENU_SLUG,
			'eimn_msg'   => $result['status'],
			'eimn_count' => $result['count'],
		];
		if ( $result['message'] !== '' ) {
			$args['eimn_err'] = rawurlencode( $result['message'] );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_run_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ethersys-importer-for-modelo-netty' ) );
		}
		check_admin_referer( 'eimn_run_import' );

		// Import synchrone : on exécute directement dans cette requête (pas de wp-cron
		// loopback qui mourait au timeout web). On lève les limites de temps/mémoire et on
		// affiche le résultat réel au retour.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- set_time_limit peut être désactivé par l'hébergeur ; nécessaire pour les imports longs en contexte admin.
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$result = Importer::run();
		$run_id = (int) $result['run_id'];

		global $wpdb;
		$status = '';
		$error  = '';
		if ( $run_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from Db::runs_table(), not user input; custom table, no WP cache API applicable.
			$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT status, error_message FROM ' . Db::runs_table() . ' WHERE id = %d', $run_id ), ARRAY_A );
			$status = (string) ( $row['status'] ?? '' );
			$error  = (string) ( $row['error_message'] ?? '' );
		}

		if ( 'success' === $status ) {
			$msg = 'ok';
		} elseif ( false !== strpos( $error, 'déjà en cours' ) ) {
			$msg = 'locked';
		} else {
			$msg = 'failed';
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'     => self::MENU_SLUG,
					'eimn_msg' => $msg,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ethersys-importer-for-modelo-netty' ) );
		}
		check_admin_referer( 'eimn_save_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via esc_url_raw() on the next line.
		$feed_raw = isset( $_POST['eimn_feed_url'] ) ? wp_unslash( (string) $_POST['eimn_feed_url'] ) : '';
		$feed_url = $feed_raw === '' ? '' : esc_url_raw( trim( $feed_raw ), [ 'http', 'https' ] );

		$interval = isset( $_POST['eimn_schedule_interval'] ) ? (int) $_POST['eimn_schedule_interval'] : 6;
		$interval = max( 1, min( 999, $interval ) );

		$unit = isset( $_POST['eimn_schedule_unit'] ) ? sanitize_key( (string) wp_unslash( $_POST['eimn_schedule_unit'] ) ) : 'hour';
		if ( ! in_array( $unit, [ 'minute', 'hour', 'day' ], true ) ) {
			$unit = 'hour';
		}

		$agent_id = isset( $_POST['eimn_default_agent_id'] ) ? (int) $_POST['eimn_default_agent_id'] : 0;
		if ( $agent_id < 0 ) {
			$agent_id = 0;
		}

		// Valider l'agent : un ID erroné casserait le formulaire de contact sur TOUS les
		// biens importés. On accepte un post Houzez « houzez_agent » ou un utilisateur
		// existant ; sinon on retombe sur 0 (auteur du bien) et on avertit l'admin.
		$agent_invalid = false;
		if ( $agent_id > 0 ) {
			$post     = get_post( $agent_id );
			$is_agent = $post instanceof \WP_Post && 'houzez_agent' === $post->post_type;
			$is_user  = (bool) get_userdata( $agent_id );
			if ( ! $is_agent && ! $is_user ) {
				$agent_invalid = true;
				$agent_id      = 0;
			}
		}

		update_option( self::OPT_FEED_URL, $feed_url, false );
		update_option( self::OPT_SCHEDULE_INTERVAL, $interval, false );
		update_option( self::OPT_SCHEDULE_UNIT, $unit, false );
		update_option( self::OPT_DEFAULT_AGENT_ID, $agent_id, false );

		Cron::reschedule_main_import();

		// Bouton « Tester la connexion au flux » : on a enregistré l'URL saisie ci-dessus,
		// on teste donc la valeur fraîchement enregistrée (et non l'ancienne).
		if ( isset( $_POST['eimn_test'] ) ) {
			self::redirect_test_result( self::run_feed_test( $feed_url ) );
		}

		$args = [
			'page'     => self::MENU_SLUG,
			'eimn_msg' => 'settings_ok',
		];
		if ( $agent_invalid ) {
			$args['eimn_agent_warn'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ethersys-importer-for-modelo-netty' ) );
		}

		global $wpdb;

		$runs_table = Db::runs_table();
		$logs_table = Db::logs_table();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page, gated by current_user_can( 'manage_options' ).
		$run_id = isset( $_GET['run_id'] ) ? (int) $_GET['run_id'] : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import Immo', 'ethersys-importer-for-modelo-netty' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of redirect message, gated by current_user_can( 'manage_options' ).
		$msg = isset( $_GET['eimn_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['eimn_msg'] ) ) : '';
		if ( $msg === 'ok' ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Import terminé. Voir le dernier run ci-dessous pour le détail.', 'ethersys-importer-for-modelo-netty' ) . '</p></div>';
		} elseif ( $msg === 'failed' ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Import en erreur. Consultez les logs.', 'ethersys-importer-for-modelo-netty' ) . '</p></div>';
		} elseif ( $msg === 'locked' ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Un import est déjà en cours.', 'ethersys-importer-for-modelo-netty' ) . '</p></div>';
		} elseif ( $msg === 'settings_ok' ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Réglages enregistrés.', 'ethersys-importer-for-modelo-netty' ) . '</p></div>';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of redirect flag, gated by current_user_can( 'manage_options' ).
			if ( isset( $_GET['eimn_agent_warn'] ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'ID d’agent introuvable (ni agent Houzez, ni utilisateur) : valeur réinitialisée à 0 (auteur du bien).', 'ethersys-importer-for-modelo-netty' ) . '</p></div>';
			}
		} elseif ( $msg === 'test_ok' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of redirect param, gated by current_user_can( 'manage_options' ).
			$count = isset( $_GET['eimn_count'] ) ? (int) $_GET['eimn_count'] : 0;
			echo '<div class="notice notice-success"><p>' . esc_html(
				/* translators: %d: number of properties detected in the feed */
				sprintf( __( 'Connexion OK — %d biens détectés dans le flux.', 'ethersys-importer-for-modelo-netty' ), $count )
			) . '</p></div>';
		} elseif ( $msg === 'test_fail' ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of redirect param, gated by current_user_can( 'manage_options' ).
			$err = isset( $_GET['eimn_err'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['eimn_err'] ) ) ) : '';
			echo '<div class="notice notice-error"><p>' . esc_html(
				__( 'Erreur de connexion : ', 'ethersys-importer-for-modelo-netty' ) . $err
			) . '</p></div>';
		}

		$feed_url          = (string) get_option( self::OPT_FEED_URL, '' );
		$schedule_interval = (int) get_option( self::OPT_SCHEDULE_INTERVAL, 6 );
		$schedule_interval = max( 1, min( 999, $schedule_interval ) );
		$schedule_unit     = (string) get_option( self::OPT_SCHEDULE_UNIT, 'hour' );
		if ( ! in_array( $schedule_unit, [ 'minute', 'hour', 'day' ], true ) ) {
			$schedule_unit = 'hour';
		}
		$default_agent_id = (int) get_option( self::OPT_DEFAULT_AGENT_ID, 0 );

		if ( ! Importer::is_feed_configured() ) {
			echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p>' . esc_html__( 'Sans URL de flux valide, l’import manuel et l’import automatique ne pourront pas récupérer les données.', 'ethersys-importer-for-modelo-netty' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0;">';
		wp_nonce_field( 'eimn_run_import' );
		echo '<input type="hidden" name="action" value="eimn_run_import" />';
		submit_button( __( 'Lancer l’import maintenant', 'ethersys-importer-for-modelo-netty' ), 'primary', 'submit', false );
		echo '</form>';

		if ( $run_id ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from Db::*_table(), not user input; custom tables, no WP cache API applicable.
			$run = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$runs_table} WHERE id=%d", $run_id ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( $run ) {
				echo '<h2>' . esc_html__( 'Détail du run', 'ethersys-importer-for-modelo-netty' ) . ' #' . (int) $run_id . '</h2>';
				echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1200px;overflow:auto;">' . esc_html( wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only log filter params, gated by current_user_can( 'manage_options' ).
			$level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only log filter params, gated by current_user_can( 'manage_options' ).
			$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : '';

			$where  = 'WHERE run_id = %d';
			$params = [ $run_id ];
			if ( $level ) {
				$where   .= ' AND level = %s';
				$params[] = $level;
			}
			if ( $ref ) {
				$where   .= ' AND reference_technique = %s';
				$params[] = $ref;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name and $where built from controlled variables only; custom tables, no WP cache API applicable.
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$logs_table} {$where} ORDER BY id DESC LIMIT 200",
					...$params
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			echo '<h3>' . esc_html__( 'Logs (200 derniers)', 'ethersys-importer-for-modelo-netty' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>ID</th><th>' . esc_html__( 'Niveau', 'ethersys-importer-for-modelo-netty' ) . '</th><th>' . esc_html__( 'Action', 'ethersys-importer-for-modelo-netty' ) . '</th><th>' . esc_html__( 'Référence', 'ethersys-importer-for-modelo-netty' ) . '</th><th>Post</th><th>Attachment</th><th>' . esc_html__( 'Message', 'ethersys-importer-for-modelo-netty' ) . '</th><th>Date</th>';
			echo '</tr></thead><tbody>';
			foreach ( $logs as $row ) {
				echo '<tr>';
				echo '<td>' . (int) $row['id'] . '</td>';
				echo '<td>' . esc_html( $row['level'] ) . '</td>';
				echo '<td>' . esc_html( $row['action'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['reference_technique'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['post_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['attachment_id'] ) . '</td>';
				echo '<td>' . esc_html( wp_trim_words( (string) $row['message'], 20 ) ) . '</td>';
				echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from Db::runs_table(), not user input; custom table, no WP cache API applicable.
			$runs = $wpdb->get_results( "SELECT * FROM {$runs_table} ORDER BY id DESC LIMIT 20", ARRAY_A );
			echo '<h2>' . esc_html__( 'Derniers runs (20 plus récents)', 'ethersys-importer-for-modelo-netty' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>ID</th><th>' . esc_html__( 'Statut', 'ethersys-importer-for-modelo-netty' ) . '</th><th>' . esc_html__( 'Début', 'ethersys-importer-for-modelo-netty' ) . '</th><th>' . esc_html__( 'Fin', 'ethersys-importer-for-modelo-netty' ) . '</th><th>' . esc_html__( 'Source', 'ethersys-importer-for-modelo-netty' ) . '</th><th>' . esc_html__( 'Compteurs', 'ethersys-importer-for-modelo-netty' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $runs as $row ) {
				$link = add_query_arg(
					[
						'page'   => self::MENU_SLUG,
						'run_id' => (int) $row['id'],
					],
					admin_url( 'admin.php' )
				);
				echo '<tr>';
				echo '<td><a href="' . esc_url( $link ) . '">' . (int) $row['id'] . '</a></td>';
				echo '<td>' . esc_html( $row['status'] ) . '</td>';
				echo '<td>' . esc_html( $row['started_at'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['finished_at'] ) . '</td>';
				echo '<td><code>' . esc_html( wp_trim_words( (string) $row['source_url'], 8 ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) $row['counts_json'] ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Réglages', 'ethersys-importer-for-modelo-netty' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:800px;background:#fff;border:1px solid #ccd0d4;padding:12px;margin:12px 0;">';
		wp_nonce_field( 'eimn_save_settings' );
		echo '<input type="hidden" name="action" value="eimn_save_settings" />';

		echo '<p><label for="eimn_feed_url"><strong>' . esc_html__( 'URL du flux XML Netty', 'ethersys-importer-for-modelo-netty' ) . '</strong></label></p>';
		echo '<p><input class="large-text code" type="url" id="eimn_feed_url" name="eimn_feed_url" value="' . esc_attr( $feed_url ) . '" placeholder="https://…" autocomplete="off" /></p>';
		echo '<p class="description">' . esc_html__( 'Fournie par votre espace Netty (aucune URL secrète ne doit figurer dans le code du plugin). HTTPS recommandé.', 'ethersys-importer-for-modelo-netty' ) . '</p>';

		// Bouton de test directement sous l'URL : enregistre puis vérifie la valeur saisie.
		echo '<p>';
		submit_button( __( 'Tester la connexion au flux', 'ethersys-importer-for-modelo-netty' ), 'secondary', 'eimn_test', false );
		echo ' <span class="description">' . esc_html__( 'Enregistre les réglages puis vérifie l’URL ci-dessus.', 'ethersys-importer-for-modelo-netty' ) . '</span>';
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Fréquence d’import automatique (WP-Cron)', 'ethersys-importer-for-modelo-netty' ) . '</strong></p>';
		echo '<p style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">';
		echo '<label for="eimn_schedule_interval" class="screen-reader-text">' . esc_html__( 'Nombre', 'ethersys-importer-for-modelo-netty' ) . '</label>';
		echo '<input class="small-text" type="number" min="1" max="999" step="1" id="eimn_schedule_interval" name="eimn_schedule_interval" value="' . esc_attr( (string) $schedule_interval ) . '" />';
		echo '<label for="eimn_schedule_unit" class="screen-reader-text">' . esc_html__( 'Unité', 'ethersys-importer-for-modelo-netty' ) . '</label>';
		echo '<select id="eimn_schedule_unit" name="eimn_schedule_unit">';
		echo '<option value="minute"' . selected( $schedule_unit, 'minute', false ) . '>' . esc_html__( 'minute(s)', 'ethersys-importer-for-modelo-netty' ) . '</option>';
		echo '<option value="hour"' . selected( $schedule_unit, 'hour', false ) . '>' . esc_html__( 'heure(s)', 'ethersys-importer-for-modelo-netty' ) . '</option>';
		echo '<option value="day"' . selected( $schedule_unit, 'day', false ) . '>' . esc_html__( 'jour(s)', 'ethersys-importer-for-modelo-netty' ) . '</option>';
		echo '</select>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'L’import planifié n’est actif que si l’URL du flux est renseignée. Intervalle maximal : 30 jours. Sur un site peu visité, prévoir un vrai cron système qui appelle wp-cron.php.', 'ethersys-importer-for-modelo-netty' ) . '</p>';

		$next_ts = Cron::next_import_timestamp();
		if ( $next_ts ) {
			/* translators: %s: date/heure locale WordPress */
			echo '<p class="description">' . esc_html( sprintf( __( 'Prochain import automatique prévu vers : %s', 'ethersys-importer-for-modelo-netty' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts ) ) ) . '</p>';
		} elseif ( Importer::is_feed_configured() ) {
			echo '<p class="description">' . esc_html__( 'Aucun import automatique planifié pour l’instant (enregistrez les réglages pour replanifier).', 'ethersys-importer-for-modelo-netty' ) . '</p>';
		}

		echo '<hr style="margin:16px 0;" />';

		echo '<p><label for="eimn_default_agent_id"><strong>' . esc_html__( 'ID de l’agent Houzez (unique)', 'ethersys-importer-for-modelo-netty' ) . '</strong></label></p>';
		echo '<p><input class="regular-text" type="number" min="0" step="1" id="eimn_default_agent_id" name="eimn_default_agent_id" value="' . esc_attr( (string) $default_agent_id ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Si renseigné, tous les biens importés utiliseront cet agent (formulaire de contact). Laissez 0 pour utiliser l’auteur du bien.', 'ethersys-importer-for-modelo-netty' ) . '</p>';
		submit_button( __( 'Enregistrer', 'ethersys-importer-for-modelo-netty' ), 'primary', 'eimn_save', false );
		echo '</form>';

		echo '</div>';
	}
}
