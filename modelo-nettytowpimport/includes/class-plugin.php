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

final class Plugin {
	public const TEXT_DOMAIN = 'modelo-nettytowpimport';

	public static function init(): void {
		add_action( 'plugins_loaded', [ __CLASS__, 'load_textdomain' ] );

		register_activation_hook( MNTI_BASENAME, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( MNTI_BASENAME, [ __CLASS__, 'deactivate' ] );

		require_once MNTI_PATH . 'includes/class-db.php';
		require_once MNTI_PATH . 'includes/class-logger.php';
		require_once MNTI_PATH . 'includes/class-xml-parser.php';
		require_once MNTI_PATH . 'includes/class-media-sync.php';
		require_once MNTI_PATH . 'includes/class-importer.php';
		require_once MNTI_PATH . 'includes/class-cron.php';
		require_once MNTI_PATH . 'includes/class-admin.php';
		require_once MNTI_PATH . 'includes/class-cli.php';
		require_once MNTI_PATH . 'includes/class-dpe-integration.php';
		require_once MNTI_PATH . 'includes/class-theme-compat.php';
		require_once MNTI_PATH . 'includes/class-houzez-search-i18n.php';

		Cron::init();
		Admin::init();
		Cli::init();
		DpeIntegration::init();

		// Houzez est un *thème* : `houzez_init()` n'est définie qu'au chargement du
		// thème (after_setup_theme), donc après l'inclusion de ce plugin. Vérifier la
		// garde ici renverrait toujours false → on diffère sur after_setup_theme.
		add_action(
			'after_setup_theme',
			static function (): void {
				if ( function_exists( 'houzez_init' ) ) {
					ThemeCompat::init();
					HouzezSearchI18n::init();
				}
			}
		);
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( MNTI_BASENAME ) . '/languages'
		);
	}

	public static function activate(): void {
		Db::create_tables();
		Cron::activate();
	}

	public static function deactivate(): void {
		Cron::deactivate();
	}
}
