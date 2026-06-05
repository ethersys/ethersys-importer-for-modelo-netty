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

final class Cron {
	public const SCHEDULE_RECURRENCE = 'mnti_import_recurrence';
	private const EVENT_HOOK         = 'mnti_import_event';
	private const PURGE_HOOK         = 'mnti_import_purge_event';
	private const PURGE_SCHEDULE     = 'mnti_purge_daily';

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ] );
		add_action( self::EVENT_HOOK, [ __CLASS__, 'handle_import' ] );
		add_action( self::PURGE_HOOK, [ __CLASS__, 'handle_purge' ] );
		add_action( 'mnti_import_oneshot', [ __CLASS__, 'handle_import' ] );
	}

	public static function add_schedules( array $schedules ): array {
		static $interval = null;
		if ( $interval === null ) {
			$interval = self::get_interval_seconds_from_options();
		}

		$schedules[ self::SCHEDULE_RECURRENCE ] = [
			'interval' => $interval,
			/* translators: %s: interval in seconds (debug / cron list) */
			'display'  => sprintf( __( 'Import Immo (intervalle : %d s)', 'modelo-nettytowpimport' ), $interval ),
		];

		if ( ! isset( $schedules[ self::PURGE_SCHEDULE ] ) ) {
			$schedules[ self::PURGE_SCHEDULE ] = [
				'interval' => DAY_IN_SECONDS,
				'display'  => __( 'Quotidien (nettoyage des logs Netty)', 'modelo-nettytowpimport' ),
			];
		}

		return $schedules;
	}

	/**
	 * Intervalle WP-Cron pour l’import principal (lecture des options).
	 */
	public static function get_interval_seconds_from_options(): int {
		$n    = (int) get_option( 'mnti_schedule_interval', 6 );
		$n    = max( 1, min( 999, $n ) );
		$unit = (string) get_option( 'mnti_schedule_unit', 'hour' );
		if ( ! in_array( $unit, [ 'minute', 'hour', 'day' ], true ) ) {
			$unit = 'hour';
		}

		$seconds = $n * HOUR_IN_SECONDS;
		if ( $unit === 'minute' ) {
			$seconds = $n * MINUTE_IN_SECONDS;
		} elseif ( $unit === 'day' ) {
			$seconds = $n * DAY_IN_SECONDS;
		}

		return min( $seconds, 30 * DAY_IN_SECONDS );
	}

	public static function activate(): void {
		self::schedule_purge_if_needed();
		self::reschedule_main_import();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::EVENT_HOOK );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}

	/**
	 * Replanifie l’import après changement de réglages (ou à l’activation).
	 */
	public static function reschedule_main_import(): void {
		wp_clear_scheduled_hook( self::EVENT_HOOK );

		if ( ! Importer::is_feed_configured() ) {
			return;
		}

		wp_schedule_event( time() + 60, self::SCHEDULE_RECURRENCE, self::EVENT_HOOK );
	}

	private static function schedule_purge_if_needed(): void {
		if ( wp_next_scheduled( self::PURGE_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 300, self::PURGE_SCHEDULE, self::PURGE_HOOK );
	}

	public static function handle_import(): void {
		if ( ! Importer::is_feed_configured() ) {
			return;
		}

		Importer::run(
			[
				'dry_run'        => false,
				'sync_images'    => true,
				'delete_missing' => true,
			]
		);
	}

	public static function handle_purge(): void {
		Db::purge_old_runs( 200 );
	}

	/** @return int|false Timestamp WordPress du prochain import planifié, ou false si aucun. */
	public static function next_import_timestamp() {
		return wp_next_scheduled( self::EVENT_HOOK );
	}
}
