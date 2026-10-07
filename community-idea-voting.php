<?php
/**
 * Plugin Name: Community Idea Voting
 * Description: Anonymous pairwise voting, community idea submissions, and transparent results.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Let's Roll
 * Text Domain: community-idea-voting
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CIV_VERSION', '1.0.0');
define('CIV_FILE', __FILE__);
define('CIV_DIR', plugin_dir_path(__FILE__));
define('CIV_URL', plugin_dir_url(__FILE__));

require_once CIV_DIR . 'includes/class-civ-db.php';
require_once CIV_DIR . 'includes/class-civ-rest.php';
require_once CIV_DIR . 'includes/class-civ-admin.php';
require_once CIV_DIR . 'includes/class-civ-public.php';

register_activation_hook(CIV_FILE, array('CIV_DB', 'install'));
register_activation_hook(CIV_FILE, array('CIV_Public', 'add_rewrite_rules'));

add_action('plugins_loaded', function () {
    CIV_DB::maybe_upgrade();
    (new CIV_REST())->register();
    (new CIV_Admin())->register();
    (new CIV_Public())->register();
});
