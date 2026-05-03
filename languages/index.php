<?php
/**
 * Silence is golden.
 *
 * This file exists for two reasons:
 *
 * 1. To keep the `languages/` folder in version control even when no `.po` /
 *    `.mo` translation file is present yet — Plugin Check rejects a plugin
 *    whose `Domain Path: /languages` header points to a non-existent folder.
 *
 * 2. To prevent directory listing on misconfigured servers that don't have
 *    `Options -Indexes` set — accessing `/wp-content/plugins/<slug>/languages/`
 *    will return this empty PHP file instead of a file index.
 *
 * Translations themselves are auto-loaded by wordpress.org from
 * translate.wordpress.org since WP 4.6 — they don't need to ship in this folder.
 */

defined( 'ABSPATH' ) || exit;
