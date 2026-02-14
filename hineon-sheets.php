<?php
/*
Plugin Name: Hineon Google Sheets Integration
Plugin URI:  http://hineon.com
Description: Integrates Google Sheets API to sync partners
Version:     1.0
Author:      Bonn Joel Elimanco <bonnjoel@gmail.com>
Author URI:  https://www.onlinejobs.ph/jobseekers/info/77592
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/classes/class-hineon-sheets.php';

function run_google_sheets_integration() {
	new HineonSheets();
}
add_action( 'plugins_loaded', 'run_google_sheets_integration' );