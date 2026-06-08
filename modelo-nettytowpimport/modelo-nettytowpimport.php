<?php
/**
 * Plugin Name: Modelo/Netty to WP Import
 * Description: Synchronise un flux Modelo/Netty XML vers les biens Houzez (création / mise à jour / suppression, images, logs, DPE/GES).
 * Version: 1.0.0
 * Author: Ethersys
 * Author URI: https://www.ethersys.fr
 * Plugin URI: https://github.com/ethersys/Modelo-NettyToWPImport
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: modelo-nettytowpimport
 * Domain Path: /languages
 * Requires PHP: 8.3
 *
 * @package Modelo\NettyImport
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Copyright (C) 2026 Ethersys
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2 or later,
 * as published by the Free Software Foundation. See the LICENSE file or
 * https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$_mnti_data    = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
$_mnti_version = ! empty( $_mnti_data['Version'] ) ? $_mnti_data['Version'] : '0.0.0';
define( 'MNTI_VERSION', $_mnti_version );
unset( $_mnti_data, $_mnti_version );
define( 'MNTI_PATH', plugin_dir_path( __FILE__ ) );
define( 'MNTI_URL', plugin_dir_url( __FILE__ ) );
define( 'MNTI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Nombre maximum de téléchargements d'images simultanés par run.
 * Surcharger dans wp-config.php : define( 'MNTI_IMAGE_CONCURRENCY', 10 );
 * Valeur par défaut : 5.
 */

require_once MNTI_PATH . 'includes/class-plugin.php';

\Modelo\NettyImport\Plugin::init();
