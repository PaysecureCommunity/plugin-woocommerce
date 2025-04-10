<?php
use PHPUnit\Framework\TestCase;

class WC_Gateway_PaySecure_Test extends TestCase {

    protected $gateway;

    public function setUp(): void {
        parent::setUp();

        // Simulate WooCommerce loading the gateway
        require_once plugin_dir_path(__FILE__) . '../includes/class-wc-gateway-paysecure.php';

        $this->gateway = new WC_Gateway_PaySecure();
    }

    public function test_gateway_instance() {
        $this->assertInstanceOf(WC_Gateway_PaySecure::class, $this->gateway);
    }

    public function test_gateway_title() {
        $this->assertEquals('Paysecure', $this->gateway->title);
    }

    public function test_gateway_has_fields() {
        $this->assertFalse($this->gateway->has_fields); // Or true depending on your gateway
    }

    public function test_gateway_supports() {
        $this->assertContains('products', $this->gateway->supports);
    }

    public function test_gateway_settings() {
        $this->assertArrayHasKey('enabled', $this->gateway->settings);
        $this->assertArrayHasKey('brand_id', $this->gateway->settings);
        $this->assertArrayHasKey('private_key', $this->gateway->settings);
    }

    public function test_process_payment_response_structure() {
        $order_mock = $this->createMock(WC_Order::class);
        $order_mock->method('get_id')->willReturn(123);

        $response = $this->gateway->process_payment(123);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('redirect', $response);
    }
}
