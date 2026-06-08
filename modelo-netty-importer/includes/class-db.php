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

final class Db {
	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mnti_import_runs';
	}

	public static function logs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mnti_import_logs';
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$runs_table      = self::runs_table();
		$logs_table      = self::logs_table();

		$sql_runs = "CREATE TABLE {$runs_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			status VARCHAR(20) NOT NULL,
			source_url TEXT NOT NULL,
			counts_json LONGTEXT NULL,
			error_message LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT(20) UNSIGNED NOT NULL,
			level VARCHAR(10) NOT NULL,
			action VARCHAR(50) NOT NULL,
			reference_technique VARCHAR(191) NULL,
			post_id BIGINT(20) UNSIGNED NULL,
			attachment_id BIGINT(20) UNSIGNED NULL,
			message LONGTEXT NOT NULL,
			context_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY level (level),
			KEY action (action),
			KEY reference_technique (reference_technique)
		) {$charset_collate};";

		dbDelta( $sql_runs );
		dbDelta( $sql_logs );
	}

	public static function purge_old_runs( int $max_runs = 200 ): void {
		global $wpdb;

		$runs_table = self::runs_table();
		$logs_table = self::logs_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from self::*_table(), not user input; custom tables, no WP cache API applicable.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$runs_table} ORDER BY id DESC LIMIT 100000 OFFSET %d",
				$max_runs
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from self::*_table(), not user input; custom tables.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$logs_table} WHERE run_id IN ({$placeholders})", ...array_map( 'intval', $ids ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from self::*_table(), not user input; custom tables.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs_table} WHERE id IN ({$placeholders})", ...array_map( 'intval', $ids ) ) );
	}
}
