<?php

class PaySecureAPI
{
    const LOG_FILE_PREFIX = 'paysecure';

    //const PAYSECURE_API_URL = "https://api.paysecure.net";
    const PAYSECURE_API_URL = "https://staging.paysecure.net";

    /**
     * @var string
     */
    private $private_key;

    /**
     * @var string
     */
    private $brand_id;

    /**
     * @var string
     */
    private $logger;

    /**
     * @var mixed
     */
    private $debug;

    public function __construct($private_key, $brand_id, WC_Logger $logger, $debug)
    {
        $this->private_key = $private_key;
        $this->brand_id = $brand_id;
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public function create_payment($params)
    {
        $this->log_info("Loading Payment Form");

        return $this->call('POST', '/purchases/', $params);
    }

    public function payment_methods($currency, $language)
    {
        $this->log_info("Fetching Payment Methods");

        return $this->call(
            'GET',
            "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
        );
    }

    public function was_payment_successful($payment_id)
    {
        $this->log_info(sprintf("Validating Payment: %s", $payment_id));
        $result = $this->call('GET', "/purchases/{$payment_id}/");

        $this->log_info(sprintf(
            "Payment Validation Result: %s",
            var_export($result, true)
        ));

        return $result && $result['status'] == 'PAID';
    }

    public function refund_payment($payment_id, $params)
    {
        $this->log_info(sprintf("Refunding Payment: %s", $payment_id));

        $result = $this->call('GET', "/purchases/{$payment_id}/refund/", $params);

        $this->log_info(sprintf(
            "Payment Refund Result: %s",
            var_export($result, true)
        ));

        return $result;
    }

    private function call($method, $route, $params = [])
    {
        $private_key = $this->private_key;
        if (!empty($params)) {
            $params = json_encode($params);
        }

        $response = $this->request(
            $method,
            sprintf("%s/api/v1%s", self::PAYSECURE_API_URL, $route),
            $params,
            [
                'Content-type: application/json',
                'Authorization: ' . "Bearer " . $private_key,
            ]
        );

        $this->log_info(sprintf('API Response: %s', $response));
        $result = json_decode($response, true);

        if (!$result) {
            $this->log_error('JSON Parsing Error', $response);
            return null;
        }

        if (!empty($result['errors'])) {
            $this->log_error('API Error', $result['errors']);
            return null;
        }

        return $result;
    }

    private function request($method, $url, $params = [], $headers = []): bool|string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        }

        if ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_PUT, 1);
        }

        if ($method == 'PUT' or $method == 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->log_info(sprintf(
            "%s `%s`\n%s\n%s",
            $method,
            $url,
            var_export($params, true),
            var_export($headers, true)
        ));

        $response = curl_exec($ch);
        switch ($code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200:
            case 201:
            case 202:
                break;
            default:
                $this->log_error(
                    sprintf("%s %s: %d", $method, $url, $code),
                    $response
                );
        }

        if (!$response) {
            $this->log_error('curl', curl_error($ch));
        }

        curl_close($ch);

        return $response;
    }

    public function log_info($message, $error_data = null): void
    {
        if ($this->debug) {
            $this->logger->info(": " . $message . ";", ['source'  => self::LOG_FILE_PREFIX]);
        }
    }

    public function log_error($message, $error_data = null): void
    {
        $message = ": " . $message . ";";

        if ($error_data) {
            $message .= " ERROR DATA: " . var_export($error_data, true) . ";";
        }

        if ($this->debug) {
            $this->logger->error( $message, ['source'  => self::LOG_FILE_PREFIX]);
        }
    }
}
