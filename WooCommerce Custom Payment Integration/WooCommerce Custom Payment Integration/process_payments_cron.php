<?php

// Security check
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Load WordPress
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    die("WooCommerce is not active.");
}

// Check if $wpdb is available
global $wpdb;
if (!$wpdb) {
    die("WordPress database connection is not available.");
}

// Get API key from theme options
$api_key = get_option('api_key');

// Check if API key is empty
if (empty($api_key)) {
    die("API key is not set. Please set the API key in the theme options.");
}

// Prepare the query to retrieve purchaseId and merchantRef from status_data table
$query = $wpdb->prepare(
    "SELECT DISTINCT purchaseId, merchantRef FROM {$wpdb->prefix}status_data"
);

// Execute the query
$results = $wpdb->get_results($query, ARRAY_A);

// Check if query executed successfully
if (!$results) {
    die("Query failed: " . $wpdb->last_error);
}

// Loop through the results
foreach ($results as $result) {
    $purchaseId = $result['purchaseId'];
    $merchantRef = $result['merchantRef'];

    $url = 'https://api.paysecure.net/api/v1/purchases/' . $purchaseId . '/';

    // Set headers
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
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
    if (curl_error($ch)) {
        $status = 'Error: ' . curl_error($ch);
    } else {
        // Convert JSON response to array
        $responseData = json_decode($response, true);

        // Check if decoding was successful
        if ($responseData !== null && isset($responseData['status'])) {
            // Get status value
            switch ($responseData['status']) {
                case 'PAYMENT_IN_PROCESS':
                    $status = 'processing';
                    break;
                case 'PAID':
                    $status = 'wc-wc-paid';
                    break;
                default:
                    $status = 'failed';
                    break;
            }

            // Update status in WooCommerce based on order ID (merchantRef)
            $order = wc_get_order($merchantRef);
            if ($order) {
                $order->update_status($status);
            } else {
                $status = 'Order not found for ID: ' . $merchantRef;
            }
        } else {
            $status = 'Error decoding JSON response or status not found.';
        }
    }

    // Output status
    echo "Status for order ID " . $merchantRef . " (purchase ID " . $purchaseId . "): " . $responseData['status'] . " <br/>";

    // Close cURL session
    curl_close($ch);
}
