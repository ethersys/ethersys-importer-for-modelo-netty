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

final class Logger {
	public static function start_run( string $source_url ): int {
		global $wpdb;

		$runs_table = Db::runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT on custom table; no WP CRUD API for custom tables.
		$wpdb->insert(
			$runs_table,
			[
				'started_at'    => gmdate( 'Y-m-d H:i:s' ),
				'finished_at'   => null,
				'status'        => 'running',
				'source_url'    => self::redact_url( $source_url ),
				'counts_json'   => null,
				'error_message' => null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retire la query-string et le fragment avant stockage/affichage : l'URL de flux
	 * Netty contient souvent un token d'accès (?token=…) qui ne doit pas finir en clair
	 * dans la table runs ni dans d'éventuels exports/sauvegardes. On ne conserve que
	 * scheme://host[:port]/path.
	 */
	private static function redact_url( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return ''; // non parsable : ne rien stocker plutôt que de risquer une fuite
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = $parts['path'] ?? '';
		return $scheme . $parts['host'] . $port . $path;
	}

	public static function finish_run_success( int $run_id, array $counts ): void {
		self::finish_run( $run_id, 'success', $counts, null );
	}

	public static function finish_run_failed( int $run_id, string $error, array $counts ): void {
		self::finish_run( $run_id, 'failed', $counts, $error );
	}

	private static function finish_run( int $run_id, string $status, array $counts, ?string $error ): void {
		global $wpdb;
		$runs_table = Db::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE on custom table; no WP CRUD API for custom tables.
		$wpdb->update(
			$runs_table,
			[
				'finished_at'   => gmdate( 'Y-m-d H:i:s' ),
				'status'        => $status,
				'counts_json'   => wp_json_encode( $counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'error_message' => $error,
			],
			[ 'id' => $run_id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public static function log_info( int $run_id, string $action, string $message, array $ctx = [] ): void {
		self::log( $run_id, 'info', $action, $message, $ctx );
	}

	public static function log_error( int $run_id, string $action, string $message, array $ctx = [] ): void {
		self::log( $run_id, 'error', $action, $message, $ctx );
	}

	private static function log( int $run_id, string $level, string $action, string $message, array $ctx ): void {
		global $wpdb;

		$logs_table = Db::logs_table();

		$reference = isset( $ctx['reference_technique'] ) ? (string) $ctx['reference_technique'] : null;
		$post_id   = isset( $ctx['post_id'] ) ? (int) $ctx['post_id'] : null;
		$att_id    = isset( $ctx['attachment_id'] ) ? (int) $ctx['attachment_id'] : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- INSERT on custom table; no WP CRUD API for custom tables.
		$wpdb->insert(
			$logs_table,
			[
				'run_id'              => $run_id,
				'level'               => $level,
				'action'              => $action,
				'reference_technique' => $reference,
				'post_id'             => $post_id,
				'attachment_id'       => $att_id,
				'message'             => $message,
				'context_json'        => wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);
	}
}
