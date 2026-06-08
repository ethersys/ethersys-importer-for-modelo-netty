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

final class DpeIntegration {
	private static bool $rendered = false;

	public static function init(): void {
		// Prefer early render (if theme supports wp_body_open), but fall back to wp_footer.
		// The JS will move the container into the right Houzez block.
		add_action( 'wp_body_open', [ __CLASS__, 'render_container' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_container' ], 1 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_move_script' ] );
	}

	public static function render_container(): void {
		if ( self::$rendered ) {
			return;
		}
		if ( ! is_singular( 'property' ) ) {
			return;
		}
		if ( ! shortcode_exists( 'immowp_dpe_ges' ) ) {
			return;
		}

		// Render server-side; JS will move it into the Houzez energy block.
		self::$rendered = true;
		echo '<div id="nti-immowp-dpe-ges" style="display:none">';
		echo do_shortcode( '[immowp_dpe_ges]' );
		echo '</div>';
	}

	public static function enqueue_move_script(): void {
		if ( ! is_singular( 'property' ) ) {
			return;
		}
		if ( ! shortcode_exists( 'immowp_dpe_ges' ) ) {
			return;
		}

		$js = <<<'JS'
(function () {
  function ensureSection(hostSection) {
    if (!hostSection) return null;

    var existing = hostSection.querySelector('.nh-dpe-ges-section');
    if (existing) return existing;

    // Create a Houzez-like block inside the Details section.
    var wrap = document.createElement('div');
    wrap.className = 'block-wrap nh-dpe-ges-section';

    var titleWrap = document.createElement('div');
    titleWrap.className = 'block-title-wrap d-flex justify-content-between align-items-center';

    var h2 = document.createElement('h2');
    h2.textContent = 'Diagnostic de Performance Énergétique';

    titleWrap.appendChild(h2);

    var contentWrap = document.createElement('div');
    contentWrap.className = 'block-content-wrap';

    wrap.appendChild(titleWrap);
    wrap.appendChild(contentWrap);

    hostSection.appendChild(wrap);
    return wrap;
  }

  function move() {
    var container = document.getElementById('nti-immowp-dpe-ges');
    if (!container) return;

    // Prefer inserting as its own Houzez block inside the Details section.
    var detailsSection = document.getElementById('property-detail-wrap');
    if (detailsSection) {
      var block = ensureSection(detailsSection);
      if (!block) {
        console.warn('[NTI DPE] ensureSection returned null — DPE block not moved.');
        return;
      }
      var target = block.querySelector('.block-content-wrap');
      if (!target) {
        console.warn('[NTI DPE] .block-content-wrap not found inside DPE section.');
        return;
      }
      container.style.display = 'block';
      target.appendChild(container);
      return;
    }

    // Fallback: append into Energy Class content if it exists.
    var energyTarget = document.querySelector('#property-energy-class-wrap .block-content-wrap');
    if (energyTarget) {
      container.style.display = 'block';
      energyTarget.appendChild(container);
      return;
    }

    console.warn('[NTI DPE] No target found for DPE block (#property-detail-wrap or #property-energy-class-wrap). DPE remains hidden.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', move);
  } else {
    move();
  }
})();
JS;

		// Register a tiny inline script without shipping a JS file.
		wp_register_script( 'nh-dpe-move', '', [], MNTI_VERSION, true );
		wp_enqueue_script( 'nh-dpe-move' );
		wp_add_inline_script( 'nh-dpe-move', $js );
	}
}
