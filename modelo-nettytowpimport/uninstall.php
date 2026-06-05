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

// Garde-fou : ne s'exécute que dans le contexte de désinstallation WP.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Purge les tables, options et crons pour le blog courant.
 */
function mnti_uninstall_cleanup(): void {
	global $wpdb;

	// Tables custom (noms construits comme dans Db::runs_table()/logs_table()).
	$runs_table = $wpdb->prefix . 'mnti_import_runs';
	$logs_table = $wpdb->prefix . 'mnti_import_logs';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms de tables internes, pas de saisie utilisateur.
	$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms de tables internes, pas de saisie utilisateur.
	$wpdb->query( "DROP TABLE IF EXISTS {$runs_table}" );

	// Options (mnti_feed_url peut contenir un token d'accès).
	$options = [
		'mnti_feed_url',
		'mnti_feed_etag',
		'mnti_feed_last_modified',
		'mnti_schedule_interval',
		'mnti_schedule_unit',
		'mnti_default_agent_id',
		'mnti_import_lock',
	];
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Événements cron résiduels (normalement déjà nettoyés à la désactivation).
	wp_clear_scheduled_hook( 'mnti_import_event' );
	wp_clear_scheduled_hook( 'mnti_import_purge_event' );
	wp_clear_scheduled_hook( 'mnti_import_oneshot' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		mnti_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	mnti_uninstall_cleanup();
}
