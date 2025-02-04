<?php
/*
Plugin Name: WooCommerce Custom Payment Integration
Description: Custom payment integration for WooCommerce.
Version: 1.0
Author: Your Name
*/

// Create custom tables on plugin activation
register_activation_hook( __FILE__, 'create_custom_tables' );
function create_custom_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $payment_table_name = $wpdb->prefix . 'payment_data';
    $status_table_name = $wpdb->prefix . 'status_data';

    // Create payment data table
    $payment_sql = "CREATE TABLE IF NOT EXISTS $payment_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        payload text NOT NULL,
        purchaseId varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    // Create status data table
    $status_sql = "CREATE TABLE IF NOT EXISTS $status_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        purchaseId varchar(255) NOT NULL,
        merchantRef varchar(255) NOT NULL,
        status varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $payment_sql );
    dbDelta( $status_sql );
}

// Register a settings page for the API key
add_action( 'admin_menu', 'register_api_key_settings_page' );
function register_api_key_settings_page() {
    add_options_page( 'API Settings', 'API Settings', 'manage_options', 'api-settings', 'api_settings_page_content' );
}

function api_settings_page_content() {
    ?>
    <div class="wrap">
        <h2>API Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'api-settings-group' ); ?>
            <?php do_settings_sections( 'api-settings-group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="api_key" value="<?php echo esc_attr( get_option('api_key') ); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Register API key setting
add_action( 'admin_init', 'register_api_key_setting' );
function register_api_key_setting() {
    register_setting( 'api-settings-group', 'api_key' );
}

// Handling webhook notifications
add_action('rest_api_init', 'custom_webhook_endpoint');
function custom_webhook_endpoint() {
    register_rest_route('custom-webhooks/v1', '/payment', array(
        'methods' => 'POST',
        'callback' => 'handle_webhook_notification',
        'permission_callback' => '__return_true', // Adjust permissions as needed
    ));
}

function handle_webhook_notification($request) {
    $params = $request->get_json_params(); // Get JSON payload
    
    // Serialize the entire JSON payload
    $payload = json_encode($params);
    $data = json_decode($payload, true);
    
    // Extract purchaseId from the payload
    $purchaseId = isset($data['message']['purchaseId']) ? $data['message']['purchaseId'] : 'blank';
    $merchantRef = isset($data['message']['merchantRef']) ? $data['message']['merchantRef'] : 'blank';
    
    // Save the serialized payload and purchaseId to the database
    global $wpdb;
    $table_name = $wpdb->prefix . 'payment_data'; // Use the table name directly
    
    $wpdb->insert(
        $table_name,
        array(
            'payload' => $payload,
            'purchaseId' => $purchaseId,
        ),
        array(
            '%s',
            '%s',
        )
    );

    // Make API request to get status
    $url = 'https://api.paysecure.net/api/v1/purchases/'.$purchaseId.'/';

    // API key
    $apiKey = get_option('api_key');

    // Set headers
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    );

    // Initialize cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response instead of outputting it

    // Execute cURL session
    $response = curl_exec($ch);

    // Check for errors
    if(curl_error($ch)) {
        $statuspayment = 'Error: ' . curl_error($ch);
    } else {
        // Convert JSON response to array
        $responseData = json_decode($response, true);

        // Check if decoding was successful
        if ($responseData !== null && isset($responseData['status'])) {
            // Get status value
            $statuspayment = $responseData['status'];
        } else {
            $statuspayment = 'Error decoding JSON response or status not found.';
        }
    }

    // Check if the status_data table exists
    $status_table_name = $wpdb->prefix . 'status_data';
   
    // Insert data into status_data table
    $wpdb->insert(
        $status_table_name,
        array(
            'purchaseId' => $purchaseId,
            'merchantRef' => $merchantRef,
            'status' => $statuspayment,
        ),
        array(
            '%s',
            '%s',
            '%s',
        )
    );

    // Close cURL session
    curl_close($ch);

    return new WP_REST_Response('Webhook received and processed.', 200);
}

// Register custom order status
add_action( 'init', 'register_custom_order_status' );
function register_custom_order_status() {
    register_post_status( 'wc-wc-paid', array(
        'label'                     => _x( 'Paid', 'Order status', 'woocommerce' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Paid <span class="count">(%s)</span>', 'Paid <span class="count">(%s)</span>', 'woocommerce' ),
    ) );
}

// Add custom order status to order list
add_filter( 'wc_get_order_statuses', 'add_custom_order_status' );
function add_custom_order_status( $order_statuses ) {
    $order_statuses['wc-wc-paid'] = _x( 'Paid', 'Order status', 'woocommerce' );
    return $order_statuses;
}

// Add custom order status to edit order list
add_filter( 'wc_order_statuses', 'add_custom_order_status_to_edit_order_list' );
function add_custom_order_status_to_edit_order_list( $order_statuses ) {
    $order_statuses['wc-wc-paid'] = _x( 'Paid', 'Order status', 'woocommerce' );
    return $order_statuses;
}

// Modify order query to include custom status
add_filter( 'woocommerce_my_account_my_orders_query', 'modify_my_account_orders_query' );
function modify_my_account_orders_query( $args ) {
    $args['post_status'] = array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-wc-paid' );
    return $args;
}
