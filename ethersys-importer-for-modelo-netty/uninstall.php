<?php
/**
 * Désinstallation du plugin Netty → WP Import.
 *
 * Exécuté par WordPress uniquement lors de la *suppression* du plugin
 * (pas à la désactivation). Supprime les tables custom, les options et
 * les événements cron résiduels. Les posts `property` importés et leurs
 * postmeta ne sont PAS supprimés (le catalogue est conservé) : la
 * désinstallation ne doit pas détruire le contenu éditorial.
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

// Garde-fou : ne s'exécute que dans le contexte de désinstallation WP.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Purge les tables, options et crons pour le blog courant.
 */
function eimn_uninstall_cleanup(): void {
	global $wpdb;

	// Tables custom (noms construits comme dans Db::runs_table()/logs_table()).
	$runs_table = $wpdb->prefix . 'eimn_import_runs';
	$logs_table = $wpdb->prefix . 'eimn_import_logs';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE lors de la désinstallation ; noms de tables internes, pas de saisie utilisateur.
	$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE lors de la désinstallation ; noms de tables internes, pas de saisie utilisateur.
	$wpdb->query( "DROP TABLE IF EXISTS {$runs_table}" );

	// Options (eimn_feed_url peut contenir un token d'accès).
	$options = [
		'eimn_feed_url',
		'eimn_feed_etag',
		'eimn_feed_last_modified',
		'eimn_schedule_interval',
		'eimn_schedule_unit',
		'eimn_default_agent_id',
		'eimn_import_lock',
	];
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Événements cron résiduels (normalement déjà nettoyés à la désactivation).
	wp_clear_scheduled_hook( 'eimn_import_event' );
	wp_clear_scheduled_hook( 'eimn_import_purge_event' );
	wp_clear_scheduled_hook( 'eimn_import_oneshot' );
}

if ( is_multisite() ) {
	$eimn_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $eimn_site_ids as $eimn_site_id ) {
		switch_to_blog( (int) $eimn_site_id );
		eimn_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	eimn_uninstall_cleanup();
}
