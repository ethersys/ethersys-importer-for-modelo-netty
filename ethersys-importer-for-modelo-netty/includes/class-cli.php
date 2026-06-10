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

final class Cli {
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'eimn import', [ __CLASS__, 'cmd_import' ] );
	}

	/**
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $assoc_args
	 */
	public static function cmd_import( array $args, array $assoc_args ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- imports longs + sideload Imagick dépassent 30 s en CLI.
		}

		$dry_run   = (bool) ( $assoc_args['dry-run'] ?? false );
		$no_delete = (bool) ( $assoc_args['no-delete'] ?? false );
		$no_images = (bool) ( $assoc_args['no-images'] ?? false );

		$res = Importer::run(
			[
				'dry_run'        => $dry_run,
				'delete_missing' => ! $no_delete,
				'sync_images'    => ! $no_images,
			]
		);

		\WP_CLI::success( 'Run #' . $res['run_id'] . ' terminé' );
		\WP_CLI::log( wp_json_encode( $res['counts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
