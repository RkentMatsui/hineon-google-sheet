<?php

use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_Spreadsheet;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_Request;
use Google_Service_Sheets_BatchUpdateSpreadsheetRequest;
use Google_Service_Sheets_ClearValuesRequest;
use Exception;

class HineonSheets {
	/**
	 * Instance of this class
	 *
	 * @var null
	 */
	private static $instance = null;
	/**
	 * Instance Control
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	/**
	 * Class Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'add_export_orders_submenu_page' ) );
		add_action( 'woocommerce_new_order', array( $this, 'auto_export_to_sheets' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'auto_export_to_sheets' ) );
		add_action( 'do_hineon_sheets_export', array( $this, 'do_sheets_export' ) );
		//add_action( 'wp_footer', array( $this, 'debug' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'add_to_summary_sheet_trigger' ) );
	}

	/**
	 * Check if email is from a custom domain
	 * 
	 * @param string $email
	 * @return bool
	 */
	public function is_custom_email_domain( $email ) {
		$email = trim( $email );
		if ( empty( $email ) || strpos( $email, '@' ) === false ) {
			return false;
		}

		$common_domains = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com' );
		$email_parts = explode( '@', $email );
		$email_domain = strtolower( trim( end( $email_parts ) ) );

		return ! in_array( $email_domain, $common_domains );
	}

	/**
	 * Debug
	 */

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes() {
		register_rest_route(
			'hineon/v1',
			'/hineon_orders',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'get_hineon_orders' ),
				'permission_callback' => array( $this, 'verify_api_key' ),
			)
		);

	}

	/**
	 * Verify API key from request header
	 *
	 * @param \WP_REST_Request $request The request object
	 * @return bool|\WP_Error
	 */
	public function verify_api_key( $request ) {
		$api_key = $request->get_header( 'X-API-Key' );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Missing API key',
				array( 'status' => 401 )
			);
		}

		// Get the stored API key from WordPress options
		$valid_key = get_field( 'hineon_api_key', 'option' );

		if ( empty( $valid_key ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'API key not configured',
				array( 'status' => 401 )
			);
		}

		// Compare API keys using hash_equals to prevent timing attacks
		if ( ! hash_equals( $valid_key, $api_key ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Invalid API key',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	public function get_hineon_live_orders() {
		global $wpdb;

		// Get all order IDs from 2024 onwards
		$order_ids = $wpdb->get_col(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p 
			LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_hide_order'
			LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_is_temporary_combined_order'
			WHERE p.post_type = 'shop_order' 
			AND p.post_status NOT IN ('trash', 'wc-failed')
			AND pm1.meta_value IS NULL
			AND pm2.meta_value IS NULL
			AND p.post_date >= '2024-01-01 00:00:00'
			ORDER BY p.post_date DESC"
		);


		$response = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
			/** if 'test' is in customer name, continue */
			if ( stripos( $customer_name, 'test' ) !== false ) {
				continue;
			}

			$customer_id = $order->get_customer_id();

			/** if customer is admin, continue */
			if ( $customer_id && user_can( $customer_id, 'administrator' ) ) {
				continue;
			}


			$items = $order->get_items();
			$order_number = $order->get_order_number();

			foreach ( $items as $item ) {
				//get item total
				$item_total = $item->get_total();

				$response[] = array(
					'Order ID' => $order_number,
					'Product Item' => wp_strip_all_tags( $item->get_name() ),
					'Customer Name' => wp_strip_all_tags( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'State' => wp_strip_all_tags( $order->get_billing_state() ),
					'Country' => wp_strip_all_tags( $order->get_billing_country() ),
					'Currency' => wp_strip_all_tags( $order->get_currency() ),
					'Item Price' => floatval( $item_total ),
					'Total Price' => floatval( $order->get_total() ),
					'Order Date' => wp_strip_all_tags( $order->get_date_created()->format( 'm/d/Y' ) ),
					'Order Status' => wp_strip_all_tags( $order->get_status() ),
				);
			}
		}

		return $response;

	}

	/**
	 * Get orders excluding those with _hide_order meta
	 */
	public function get_hineon_orders() {

		$response = $this->get_hineon_live_orders();

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Add Export Orders submenu page under WooCommerce menu
	 */
	public function add_export_orders_submenu_page() {
		add_submenu_page(
			'woocommerce',
			'Hineon Orders Sheet',
			'Hineon Orders Sheet',
			'manage_woocommerce',
			'export-orders',
			array( $this, 'export_orders_page_content' )
		);
	}

	/**
	 * Display the Export Orders page content
	 */
	public function export_orders_page_content() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		// Check if export button was clicked
		if ( isset( $_GET['export_orders'] ) && $_GET['export_orders'] === '1' ) {
			$this->export_orders_to_csv();
			return;
		}

		// Check if Google Sheets export was requested
		if ( isset( $_GET['export_to_sheets'] ) && $_GET['export_to_sheets'] === '1' ) {
			$this->update_google_sheet();
			return;
		}

		?>
<div class="wrap">
  <h1>Export Orders</h1>
  <p>Click one of the buttons below to export all orders.</p>
  <?php
			?>
  <div class="button-group">
    <a href="<?php echo admin_url( 'admin.php?page=export-orders&export_orders=1' ); ?>" class="button button-primary"
      style="margin-right: 10px;">Export to CSV</a>
    <a href="<?php echo admin_url( 'admin.php?page=export-orders&export_to_sheets=1' ); ?>"
      class="button button-secondary">Update Google Sheet</a>
  </div>
  <?php
			// Display Google Sheets settings if they exist
			$sheet_url = get_option( 'hineon_orders_sheet_url' );
			$last_updated = get_option( 'hineon_orders_sheet_last_updated' );
			if ( $sheet_url ) {
				echo '<div class="sheet-info" style="margin-top: 20px;">';
				echo '<p class="description">Google Sheet: <a href="' . esc_url( $sheet_url ) . '" target="_blank">View Sheet</a>';
				if ( $last_updated ) {
					echo '<br>Last updated: ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_updated ) );
				}
				echo '</p></div>';
			}
			?>
</div>
<?php
	}

	/**
	 * Export orders to CSV file
	 */
	private function export_orders_to_csv() {
		// Prevent WordPress from sending its headers
		if ( ! headers_sent() ) {
			// Clear any previous output
			ob_clean();

			// Prevent WordPress from processing further output
			remove_all_actions( 'wp_headers' );
			remove_all_actions( 'admin_head' );
			remove_all_actions( 'admin_footer' );

			// Set headers for CSV download
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="hineon-orders-' . date( 'Y-m-d' ) . '.csv"' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
		}

		$orders_data = $this->get_hineon_live_orders();

		if ( empty( $orders_data ) ) {
			wp_die( 'No orders found to export.' );
		}

		// Create output stream
		$output = fopen( 'php://output', 'w' );

		// Add UTF-8 BOM for proper Excel encoding
		fputs( $output, "\xEF\xBB\xBF" );

		// Add headers
		fputcsv( $output, array_keys( $orders_data[0] ) );

		// Add data
		foreach ( $orders_data as $row ) {
			// Clean each field
			$clean_row = array_map( function ($value) {
				// First decode HTML entities
				$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				// Then strip any remaining HTML tags
				$stripped = wp_strip_all_tags( $decoded );
				// Finally trim any whitespace
				return trim( $stripped );
			}, $row );

			fputcsv( $output, $clean_row );
		}

		fclose( $output );
		exit();
	}

	public function update_google_sheet() {
		$this->export_orders_to_sheets();
		// Redirect back with success message
		wp_redirect( add_query_arg( 'sheets_export_success', '1', admin_url( 'admin.php?page=export-orders' ) ) );
		exit;
	}

	/**
	 * Export orders to Google Sheets
	 */
	private function export_orders_to_sheets() {
		if ( ! class_exists( 'Google_Client' ) ) {
			require_once get_template_directory() . '/vendor/autoload.php';
		}

		try {
			$client = new Google_Client();
			$client->setApplicationName( 'hineon Orders Export' );
			// Set full access scope
			$client->setScopes( [ 
				Google_Service_Sheets::SPREADSHEETS,
				Google_Service_Sheets::DRIVE,
				Google_Service_Sheets::DRIVE_FILE
			] );

			// Get credentials from WordPress options
			$spreadsheet_id = get_field( 'google_sheet_id', 'option' );

			$credentials_json_file = get_field( 'google_api_json', 'option' );

			$credentials = str_replace(
				site_url(),
				ABSPATH,
				$credentials_json_file['url']
			);

			if ( empty( $credentials ) ) {
				wp_die( 'Google Sheets credentials not found. Please configure them in the theme options.' );
			}

			if ( empty( $spreadsheet_id ) ) {
				wp_die( 'Google Sheets ID not found. Please configure it in the theme options.' );
			}

			$client->setAuthConfig( $credentials );

			$service = new Google_Service_Sheets( $client );

			// First, try to get the spreadsheet to check permissions
			try {
				$spreadsheet = $service->spreadsheets->get( $spreadsheet_id );
			} catch (Exception $e) {
				wp_die( 'Error accessing Google Sheet. Please make sure the sheet is shared with the service account email address. Error: ' . $e->getMessage() );
			}

			// Get the orders data
			$orders_data = $this->get_hineon_live_orders();
			if ( empty( $orders_data ) ) {
				wp_die( 'No orders found to export.' );
			}

			// Prepare the data for Google Sheets
			$values = [];
			// Add headers
			$values[] = array_keys( $orders_data[0] );

			// Add data rows
			foreach ( $orders_data as $row ) {
				$clean_row = array_map( function ($value) {
					$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$stripped = wp_strip_all_tags( $decoded );
					return trim( $stripped );
				}, $row );
				$values[] = array_values( $clean_row );
			}

			// Clear the existing content
			$clear_request = new Google_Service_Sheets_ClearValuesRequest();
			$service->spreadsheets_values->clear( $spreadsheet_id, 'Hineon Orders', $clear_request );

			$body = new Google_Service_Sheets_ValueRange( [ 
				'values' => $values
			] );

			// Update the sheet
			$result = $service->spreadsheets_values->update(
				$spreadsheet_id,
				'Hineon Orders!A1:Z',
				$body,
				[ 'valueInputOption' => 'RAW' ]
			);

			// Auto-resize columns and format cells
			$requests = [ 
				new Google_Service_Sheets_Request( [ 
					'autoResizeDimensions' => [ 
						'dimensions' => [ 
							'sheetId' => 0,
							'dimension' => 'COLUMNS',
							'startIndex' => 0,
							'endIndex' => count( $values[0] )
						]
					]
				] ),
				// Make header bold
				new Google_Service_Sheets_Request( [ 
					'repeatCell' => [ 
						'range' => [ 
							'sheetId' => 0,
							'startRowIndex' => 0,
							'endRowIndex' => 1
						],
						'cell' => [ 
							'userEnteredFormat' => [ 
								'textFormat' => [ 
									'bold' => true
								]
							]
						],
						'fields' => 'userEnteredFormat.textFormat.bold'
					]
				] ),
				// Format Item Price column as number
				new Google_Service_Sheets_Request( [ 
					'repeatCell' => [ 
						'range' => [ 
							'sheetId' => 0,
							'startRowIndex' => 1,
							'startColumnIndex' => 9, // Index of Item Price column
							'endColumnIndex' => 10
						],
						'cell' => [ 
							'userEnteredFormat' => [ 
								'numberFormat' => [ 
									'type' => 'NUMBER',
									'pattern' => '#,##0.00'
								]
							]
						],
						'fields' => 'userEnteredFormat.numberFormat'
					]
				] ),
				// Format Total Price column as number
				new Google_Service_Sheets_Request( [ 
					'repeatCell' => [ 
						'range' => [ 
							'sheetId' => 0,
							'startRowIndex' => 1,
							'startColumnIndex' => 10, // Index of Total Price column
							'endColumnIndex' => 11
						],
						'cell' => [ 
							'userEnteredFormat' => [ 
								'numberFormat' => [ 
									'type' => 'NUMBER',
									'pattern' => '#,##0.00'
								]
							]
						],
						'fields' => 'userEnteredFormat.numberFormat'
					]
				] ),
				// Format Order Date column as date
				new Google_Service_Sheets_Request( [ 
					'repeatCell' => [ 
						'range' => [ 
							'sheetId' => 0,
							'startRowIndex' => 1,
							'startColumnIndex' => 11, // Index of Order Date column
							'endColumnIndex' => 12
						],
						'cell' => [ 
							'userEnteredFormat' => [ 
								'numberFormat' => [ 
									'type' => 'DATE',
									'pattern' => 'mm/dd/yyyy'
								]
							]
						],
						'fields' => 'userEnteredFormat.numberFormat'
					]
				] )
			];

			$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest( [ 
				'requests' => $requests
			] );

			$service->spreadsheets->batchUpdate( $spreadsheet_id, $batchUpdateRequest );

			// Save the sheet URL
			$sheet_url = "https://docs.google.com/spreadsheets/d/{$spreadsheet_id}";
			update_option( 'hineon_orders_sheet_url', $sheet_url );

			// Add last updated timestamp
			update_option( 'hineon_orders_sheet_last_updated', current_time( 'mysql' ) );

		} catch (Exception $e) {
			wp_die( 'Error exporting to Google Sheets: ' . $e->getMessage() );
		}
	}

	/**
	 * Auto export to sheets when order is created or status changes
	 */
	public function auto_export_to_sheets() {
		// Don't run if we're doing AJAX or in admin
		if ( wp_doing_ajax() ) {
			return;
		}

		// Run the export in the background after 1 minute to ensure order data is fully processed
		wp_schedule_single_event( time() + 60, 'do_hineon_sheets_export' );
	}

	/**
	 * Background export handler
	 */
	public function do_sheets_export() {
		try {
			$this->export_orders_to_sheets();
		} catch (Exception $e) {
			error_log( 'hineon Sheets Export Error: ' . $e->getMessage() );
		}
	}

	public function add_to_summary_sheet_trigger( $order_id ){
		$order = null;
		if ( ! class_exists( 'Google_Client' ) ) {
			require_once get_template_directory() . '/vendor/autoload.php';
		}

		// Get the order object
		if($order_id ){
			$order = wc_get_order( $order_id );	
		}
		if($order){
			//check if order goes above 1000
			$order_total = $order->get_total();
			$order_total_flag = false;
			if($order_total > 1000){
				$order_total_flag = true;
			}

			//check if order email is from a custom domain
			$order_email_flag = $this->is_custom_email_domain( $order->get_billing_email() );

			//check if user is a repeat customer
			$user_id = $order->get_user_id();
			$order_email = $order->get_billing_email();
			$args = array(
				'limit'       => -1, // Use -1 to get all orders, or a number (e.g., 10) for recent ones
				'status'      => 'completed', 
			);
			
			if ( $user_id ) {
				$args['customer_id'] = $user_id;
			} elseif ( $order_email ) {
				$args['billing_email'] = $order_email;
			}

			$orders = array();
			if ( ! empty( $args['customer_id'] ) || ! empty( $args['billing_email'] ) ) {
				$orders = wc_get_orders( $args );
			}
			
			$order_repeat_flag = false;
			if(count($orders) > 1){
				$order_repeat_flag = true;
			}

			if($order_total_flag || $order_email_flag || $order_repeat_flag){
				$this->add_to_summary_sheet($order_id);
			}
		}

	}

	function add_to_summary_sheet( $order_id = null ){
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = $order->get_billing_email();
		// if ( ! $this->is_custom_email_domain( $email ) ) {
		// 	return; // Safeguard: Never add non-custom domains to summary sheet
		// }

		if ( ! class_exists( 'Google_Client' ) ) {
			require_once get_template_directory() . '/vendor/autoload.php';
		}

		try {
			$client = new Google_Client();
			$client->setApplicationName( 'Hineon Summary Sheet' );
			// Set full access scope
			$client->setScopes( [ 
				Google_Service_Sheets::SPREADSHEETS,
				Google_Service_Sheets::DRIVE,
				Google_Service_Sheets::DRIVE_FILE
			] );

			// Get credentials from WordPress options
			$spreadsheet_id = get_field( 'google_sheet_summary', 'option' );

			$credentials_json_file = get_field( 'google_api_json', 'option' );

			$credentials = str_replace(
				site_url(),
				ABSPATH,
				$credentials_json_file['url']
			);

			if ( empty( $spreadsheet_id ) ) {
				return;
			}

			$client->setAuthConfig( $credentials );

			$service = new Google_Service_Sheets( $client );

			// Check and setup headers
			$range = 'Hineon Companies!A:N'; 
			try {
				$response = $service->spreadsheets_values->get( $spreadsheet_id, $range );
				$rows = $response->getValues();
			} catch ( Exception $e ) {
				$rows = [];
			}

			$headers = [ 'Email', 'First Name', 'Last Name','Phone Number', 'City', 'State', 'Country', 'Registration Date','Order #','Item stock code', 'Price' , '# of Orders','Company Name', 'Company Summary' ];
			
			// If sheet is empty or headers don't match, reset it
			if ( empty( $rows ) || ( isset( $rows[0] ) && $rows[0] !== $headers ) ) {
				$body = new Google_Service_Sheets_ValueRange( [ 'values' => [ $headers ] ] );
				// Clear first
				$clear_request = new Google_Service_Sheets_ClearValuesRequest();
				$service->spreadsheets_values->clear( $spreadsheet_id, 'Hineon Companies', $clear_request );
				
				// Append headers
				$service->spreadsheets_values->append(
					$spreadsheet_id,
					'Hineon Companies!A1',
					$body,
					[ 'valueInputOption' => 'USER_ENTERED' ]
				);
				$rows = [ $headers ]; // Update local cache
			}

			$email = $order->get_billing_email();
			if ( empty( $email ) ) {
				return;
			}

			// Search for existing rows with this email to reuse company data
			$existing_company_name = '';
			$existing_summary = '';
			foreach ( $rows as $index => $row ) {
				if ( isset( $row[0] ) && strcasecmp( $row[0], $email ) === 0 ) {
					// Found existing row with same email - get company name and summary
					$existing_company_name = isset( $row[12] ) ? $row[12] : ''; // Column M: Company Name
					$existing_summary = isset( $row[13] ) ? $row[13] : ''; // Column N: Company Summary
					if ( ! empty( $existing_company_name ) || ! empty( $existing_summary ) ) {
						break; // Use the first match found
					}
				}
			}

			// Calculate total orders and gather order details
			$customer_orders = wc_get_orders( [
				'billing_email' => $email,
				'limit' => -1,
				'status' => [ 'completed', 'processing', 'on-hold' ]
			] );
			$total_orders = is_array($customer_orders) ? count( $customer_orders ) : 0;
			
			// Calculate total spent and get last order details
			$total_spent = 0;
			$last_order_date = '';
			$last_order_id = '';
			$last_order_number = '';
			$last_products = '';
			$last_item_price = 0;
			
			if ( ! empty( $customer_orders ) ) {
				foreach ( $customer_orders as $cust_order ) {
					$total_spent += $cust_order->get_total();
				}
				
				// Get last order details
				$last_order = $customer_orders[0];
				$last_order_date = $last_order->get_date_created()->date( 'Y-m-d' );
				$last_order_id = $last_order->get_order_number();
				$last_order_number = $last_order->get_order_number();
				
				// Get product names and calculate item price from last order
				$items = $last_order->get_items();
				$product_names = [];
				foreach ( $items as $item ) {
					$product_names[] = $item->get_name();
					$last_item_price += $item->get_total();
				}
				$last_products = implode( ', ', $product_names );
				$last_item_price = number_format( $last_item_price, 2 );
			}
			
			$phone = $order->get_billing_phone();
			
			// Use existing company name if available, otherwise get from current order
			if ( ! empty( $existing_company_name ) ) {
				$company_name = $existing_company_name;
			} else {
				$company_name = $order->get_billing_company();
			}

			// Always add a new row for each order (allow duplicates)
			$first_name = $order->get_billing_first_name();
			$last_name = $order->get_billing_last_name();
			$city = $order->get_billing_city();
			$state = $order->get_billing_state();
			$country = $order->get_billing_country();
			
			$user_id = $order->get_user_id();
			$user = $user_id ? get_userdata( $user_id ) : null;
			$reg_date = $user ? date( 'Y-m-d', strtotime( $user->user_registered ) ) : $order->get_date_created()->date( 'Y-m-d' );
			
			// Use existing summary and company name if available, otherwise fetch from Gemini
			if ( ! empty( $existing_summary ) || ! empty( $existing_company_name ) ) {
				$summary = $existing_summary;
				// company_name already set above from existing data
			} else {
				$gemini_data = $this->get_company_data_from_gemini( $email );
				$summary = $gemini_data['summary'];
				if ( ! empty( $gemini_data['company_name'] ) ) {
					$company_name = $gemini_data['company_name'];
				}
			}

				// Headers: Email, First Name, Last Name, Phone Number, City, State, Country, Registration Date, Order #, Item stock code, Price, # of Orders, Company Name, Company Summary
				$new_row = [
					$email,                  // A: Email
					$first_name,             // B: First Name
					$last_name,              // C: Last Name
					$phone,                  // D: Phone Number
					$city,                   // E: City
					$state,                  // F: State
					$country,                // G: Country
					$reg_date,               // H: Registration Date
					$last_order_number,      // I: Order #
					$last_products,          // J: Item stock code (product names)
					$last_item_price,        // K: Price
					$total_orders,           // L: # of Orders
					$company_name,           // M: Company Name
					$summary                 // N: Company Summary
				];

				$body = new Google_Service_Sheets_ValueRange( [ 'values' => [ $new_row ] ] );
				$service->spreadsheets_values->append(
					$spreadsheet_id,
					'Hineon Companies!A1',
					$body,
					[ 'valueInputOption' => 'USER_ENTERED' ]
				);

		} catch (Exception $e) {
			error_log( 'Hineon Summary Sheet Error: ' . $e->getMessage() );
		}
	}

	private function get_company_data_from_gemini( $email ) {
		$api_key = get_field( 'gemini_api_key', 'option' );
		if ( empty( $api_key ) ) {
			return [ 'company_name' => '', 'summary' => '' ];
		}

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=' . $api_key;

		$body = [
			'system_instruction' => [
				'parts' => [
					[ 'text' => 'You are a company data scraper. You will be given an email address and you will find any data from it and create a summary on what the company does. 
					IMPORTANT: Return your response in this exact format:
					Company Name: [company name]
					Location: [location]
					Industry: [industry]
					Core Services: [list of core services]
					Summary: [brief company summary]

					If you cannot find any data from the email, return:
					Company Name: Unknown
					Summary: No data found

					If the email is from a personal email provider (gmail, yahoo, hotmail, etc), return:
					Company Name: Personal Email
					Summary: Not a company

					DO NOT include any preambles, commentaries, or additional text.' ]
				]
			],
			'contents' => [
				[
					'role' => 'user',
					'parts' => [
						[ 'text' => $email ]
					]
				]
			]
		];

		$response = wp_remote_post( $url, [
			'body'    => json_encode( $body ),
			'headers' => [ 'Content-Type' => 'application/json' ],
			'timeout' => 30
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'company_name' => '', 'summary' => '' ];
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		if ( $http_code !== 200 ) {
			return [ 'company_name' => '', 'summary' => '' ];
		}

		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text = trim( $data['candidates'][0]['content']['parts'][0]['text'] );
			
			// Parse the response to extract all fields
			$company_name = '';
			$location = '';
			$industry = '';
			$core_services = '';
			$summary_text = '';
			
			// Look for "Company Name: " pattern
			if ( preg_match( '/Company Name:\s*(.+?)(?:\n|$)/i', $text, $name_matches ) ) {
				$company_name = trim( $name_matches[1] );
			}
			
			// Look for "Location: " pattern
			if ( preg_match( '/Location:\s*(.+?)(?:\n|$)/i', $text, $location_matches ) ) {
				$location = trim( $location_matches[1] );
			}
			
			// Look for "Industry: " pattern
			if ( preg_match( '/Industry:\s*(.+?)(?:\n|$)/i', $text, $industry_matches ) ) {
				$industry = trim( $industry_matches[1] );
			}
			
			// Look for "Core Services: " pattern
			if ( preg_match( '/Core Services:\s*(.+?)(?:\n|Summary:|$)/is', $text, $services_matches ) ) {
				$core_services = trim( $services_matches[1] );
			}
			
			// Look for "Summary: " pattern
			if ( preg_match( '/Summary:\s*(.+)/is', $text, $summary_matches ) ) {
				$summary_text = trim( $summary_matches[1] );
			}
			
			// Build formatted summary with all extracted fields
			$summary_parts = [];
			if ( ! empty( $location ) ) {
				$summary_parts[] = "Location: " . $location;
			}
			if ( ! empty( $industry ) ) {
				$summary_parts[] = "Industry: " . $industry;
			}
			if ( ! empty( $core_services ) ) {
				$summary_parts[] = "Core Services: " . $core_services;
			}
			if ( ! empty( $summary_text ) ) {
				$summary_parts[] = "Summary: " . $summary_text;
			}
			
			$summary = implode( "\n", $summary_parts );
			
			// If parsing failed, use the whole text as summary
			if ( empty( $company_name ) && empty( $summary ) ) {
				$summary = $text;
			}
			
			return [ 'company_name' => $company_name, 'summary' => $summary ];
		}

		return [ 'company_name' => '', 'summary' => '' ];
	}
		
	

	
}