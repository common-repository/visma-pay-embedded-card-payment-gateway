<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Visma_Pay_Embedded_Card_Blocks_Support extends AbstractPaymentMethodType
{
	private $gateway;
	protected $name = 'vismapay_embedded_card';

	public function initialize()
	{
		$this->settings = get_option('woocommerce_visma_pay_embedded_card_settings', []);
		$payment_gateways_class = WC()->payment_gateways();
		$payment_gateways = $payment_gateways_class->payment_gateways();
		$this->gateway = $payment_gateways['visma_pay_embedded_card'];
	}

	public function is_active()
	{
		return $this->gateway->is_available();
	}

	/**
	 * Registers and returns built scripts/handles
	 */
	public function get_payment_method_script_handles()
	{
		$script_path = '/build/blocks.js';
		$script_asset_path = WC_Gateway_visma_pay_embedded_card::plugin_abspath() . 'build/blocks.asset.php';
		$script_asset  = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version' => '1.0.0'
			);
		$script_url = WC_Gateway_visma_pay_embedded_card::plugin_url() . $script_path;

		wp_register_script(
			'wc-vismapay-embedded-blocks-integration',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('wc-vismapay-embedded-blocks-integration', 'visma-pay-embedded-card-payment-gateway', WC_Gateway_visma_pay_embedded_card::plugin_abspath() . 'languages/' );
		}

		return ['wc-vismapay-embedded-blocks-integration'];
	}

	/**
	 * Returned data is available to the payment method scripts via getSetting('vismapay_embedded_card_data')
	 */
	public function get_payment_method_data()
	{
		$visa = false;
		$mastercard = false;
		$amex = false;
		$diners = false;

		// blocks will also be rendered in the editor
		if (is_checkout()) {
			wc_print_notices();
			
			$visa = $this->get_setting('visa_logo') === 'yes';
			$mastercard = $this->get_setting('mc_logo') === 'yes';
			$amex = $this->get_setting('amex_logo') === 'yes';
			$diners = $this->get_setting('diners_logo') === 'yes';
		}

		return [
			'title' => $this->get_setting('title'),
			'imgUrl' => esc_url($this->gateway::plugin_url().'/assets/images'),
			'lang' => $this->gateway->get_lang(),
			'visa' => $visa,
			'mastercard' => $mastercard,
			'amex' => $amex,
			'diners' => $diners,
			'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
		];
	}
}