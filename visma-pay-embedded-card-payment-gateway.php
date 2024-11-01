<?php
/**
 * Plugin Name: Visma Pay Embedded Card Payment Gateway
 * Plugin URI: https://www.vismapay.com/docs
 * Description: Visma Pay Payment Gateway Embedded Card Integration for Woocommerce
 * Version: 1.1.5
 * Author: Visma
 * Author URI: https://www.visma.fi/vismapay/
 * Text Domain: visma-pay-embedded-card-payment-gateway
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 9.1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action('plugins_loaded', 'init_visma_pay_embedded_card_gateway', 0);

function woocommerce_add_WC_Gateway_visma_pay_embedded_card($methods)
{
	$methods[] = 'WC_Gateway_visma_pay_embedded_card';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_WC_Gateway_visma_pay_embedded_card');

function woocommerce_register_WC_Gateway_Visma_Pay_Embedded__Card_Blocks_Support()
{
	if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
		require_once 'includes/blocks/visma_pay_embedded_card_blocks_support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $paymentMethodRegistry) {
				$paymentMethodRegistry->register(new WC_Gateway_Visma_Pay_Embedded_Card_Blocks_Support);
			}
		);
	}
}

add_action('woocommerce_blocks_loaded', 'woocommerce_register_WC_Gateway_Visma_Pay_Embedded__Card_Blocks_Support');

function init_visma_pay_embedded_card_gateway()
{
	load_plugin_textdomain('visma-pay-embedded-card-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages/' );

	if(!class_exists('WC_Payment_Gateway'))
		return;

	class WC_Gateway_visma_pay_embedded_card extends WC_Payment_Gateway
	{
		protected $api_key;
		protected $private_key;
		protected $ordernumber_prefix;
		protected $send_items;
		protected $send_receipt;
		protected $cancel_url;
		protected $limit_currencies;
		protected $visa_logo;
		protected $mc_logo;
		protected $amex_logo;
		protected $diners_logo;
		protected $logger;
		protected $logcontext;
		
		public function __construct()
		{
			$this->id = 'visma_pay_embedded_card';
			$this->has_fields = true;
			$this->method_title = __( 'Visma Pay (Embedded Card)', 'visma-pay-embedded-card-payment-gateway' );
			$this->method_description = __( 'Visma Pay (Embedded Card) w3-API Payment Gateway integration for Woocommerce', 'visma-pay-embedded-card-payment-gateway' );

			$this->supports = array(
				'products', 
				'subscriptions',
				'subscription_cancellation', 
				'subscription_suspension', 
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'multiple_subscriptions' 
			);

			$this->init_form_fields();
			$this->init_settings();

			$this->enabled = $this->settings['enabled'];
			$this->title = $this->get_option('title');

			$this->api_key = $this->get_option('api_key');
			$this->private_key = $this->get_option('private_key');

			$this->ordernumber_prefix = $this->get_option('ordernumber_prefix');

			$this->send_items = $this->get_option('send_items');
			$this->send_receipt = $this->get_option('send_receipt');

			$this->cancel_url = $this->get_option('cancel_url');
			$this->limit_currencies = $this->get_option('limit_currencies');

			$this->visa_logo = $this->get_option('visa_logo');
			$this->mc_logo = $this->get_option('mc_logo');
			$this->amex_logo = $this->get_option('amex_logo');
			$this->diners_logo = $this->get_option('diners_logo');

			add_action('admin_notices', array($this, 'visma_pay_admin_notices'));
			add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
			add_action('woocommerce_api_wc_gateway_visma_pay_embedded_card', array($this, 'check_visma_pay_embedded_card_response' ) );
			add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'visma_pay_embedded_card_settle_payment'), 1, 1);

			add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
			add_action('woocommerce_subscription_cancelled_' . $this->id, array($this, 'subscription_cancellation'));

			// registering a new card token with 0 amount is not supported at the moment so don't allow this option
			add_filter('wcs_view_subscription_actions', array (__CLASS__, 'remove_change_payment_method_button'), 100, 2);

			if(!$this->is_valid_currency() && $this->limit_currencies == 'yes')
				$this->enabled = false;

			$this->logger = wc_get_logger();
			$this->logcontext = array('source' => 'visma-pay-embedded-card-payment-gateway');
		}

		static function plugin_url()
		{
			return untrailingslashit(plugins_url( '/', __FILE__ ));
		}
	
		static function plugin_abspath()
		{
			return trailingslashit(plugin_dir_path( __FILE__ ));
		}

		public function visma_pay_admin_notices() 
		{
			if($this->settings['enabled'] == 'no')
				return;
		}

		public function is_valid_currency()
		{
			return in_array(get_woocommerce_currency(), array('EUR'));
		}

		public function init_form_fields()
		{
			$this->form_fields = array(
				'general' => array(
					'title' => __( 'General options', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Visma Pay (Embedded Card)', 'visma-pay-embedded-card-payment-gateway' ),					
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'visma-pay-embedded-card-payment-gateway' ),
					'default' => __( 'Visma Pay (Embedded Card)', 'visma-pay-embedded-card-payment-gateway' )
				),				
				'private_key' => array(
					'title' => __( 'Private key', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Private key of the sub-merchant', 'visma-pay-embedded-card-payment-gateway' ),
					'default' => ''
				),
				'api_key' => array(
					'title' => __( 'API key', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'API key of the sub-merchant', 'visma-pay-embedded-card-payment-gateway' ),
					'default' => ''
				),
				'ordernumber_prefix' => array(
					'title' => __( 'Order number prefix', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'text',
					'description' => __( 'Prefix to avoid order number duplication', 'visma-pay-embedded-card-payment-gateway' ),
					'default' => ''
				),
				'send_items' => array(
					'title' => __( 'Send products', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send product breakdown to Visma Pay (Embedded Card).", 'visma-pay-embedded-card-payment-gateway' ),
					'default' => 'yes'
				),
				'send_receipt' => array(
					'title' => __( 'Send payment confirmation', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Send Visma Pay (Embedded Card)'s payment confirmation email to the customer's billing e-mail.", 'visma-pay-embedded-card-payment-gateway' ),
					'default' => 'yes',
				),
				'limit_currencies' => array(
					'title' => __( 'Only allow payments in EUR', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( "Enable this option if you want to allow payments only in EUR.", 'visma-pay-embedded-card-payment-gateway' ),
					'default' => 'yes',
				),
				'cancel_url' => array(
					'title' => __( 'Cancel Page', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'select',
					'description' => 
						__( 'Choose the page where the customer is redirected after a canceled/failed payment.', 'visma-pay-embedded-card-payment-gateway' ) . '<br>'.
						' - ' . __( 'Order Received: Shows the customer information about their order and a notice that the payment failed. Customer has an opportunity to try payment again.', 'visma-pay-embedded-card-payment-gateway' ) . '<br>'.
						' - ' .__( 'Pay for Order: Returns user to a page where they can try to pay their unpaid order again. ', 'visma-pay-embedded-card-payment-gateway' ) . '<br>'.
						' - ' .__( 'Cart: Customer is redirected back to the shopping cart.' , 'visma-pay-embedded-card-payment-gateway' ) . '<br>'.
						' - ' .__( 'Checkout: Customer is redirected back to the checkout.', 'visma-pay-embedded-card-payment-gateway' ) . '<br>'.
						'<br>' .__( '(When using Cart or Checkout as the return page for failed orders, the customer\'s cart will not be emptied during checkout.)', 'visma-pay-embedded-card-payment-gateway' ),
					'default' => 'order_new_checkout',
					'options' => array(
						'order_received' => __('Order Received', 'visma-pay-embedded-card-payment-gateway'),
						'order_pay' => __('Pay for Order', 'visma-pay-embedded-card-payment-gateway'),
						'order_new_cart' => __('Cart', 'visma-pay-embedded-card-payment-gateway'),
						'order_new_checkout' => __('Checkout', 'visma-pay-embedded-card-payment-gateway')
					)
				),
				'displaylogos' => array(
					'title' => __( 'Display card logos', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'title',
					'description' => '',
				),
				'visa_logo' => array(
					'title' => __( 'Visa', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display Visa and Verified by Visa logo below the form.', 'visma-pay-embedded-card-payment-gateway' ),					
					'default' => 'yes'
				),
				'mc_logo' => array(
					'title' => __( 'Mastercard', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display Mastercard and Mastercard SecureCode logo below the form.', 'visma-pay-embedded-card-payment-gateway' ),					
					'default' => 'yes'
				),
				'amex_logo' => array(
					'title' => __( 'American Express', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display American Express logo below the form.', 'visma-pay-embedded-card-payment-gateway' ),					
					'default' => 'no'
				),
				'diners_logo' => array(
					'title' => __( 'Diners Club', 'visma-pay-embedded-card-payment-gateway' ),
					'type' => 'checkbox',
					'label' => __( 'Display Diners Club logo below the form.', 'visma-pay-embedded-card-payment-gateway' ),					
					'default' => 'no'
				)
			);
		}

		public function payment_scripts()
		{
			if(!(is_checkout() || $this->is_available()))
				return;
			wp_enqueue_style( 'woocommerce_visma_pay_embedded_card', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/css/vismapay-embedded.css', '', '', 'all');
			wp_enqueue_script( 'woocommerce_visma_pay_embedded_card', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/js/vismapay-embedded.js', array( 'jquery' ), '', true );
		}

		public function payment_fields()
		{
			$img_url = untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))) . '/assets/images/';
			$clear_both = '<div style="display: block; clear: both;"></div>';

			echo '<div id="visma-pay-embedded-card-payment-content">'.wpautop(wptexturize(__( 'Payment card', 'visma-pay-embedded-card-payment-gateway' ))) . "<div id='pf-cc-form'><iframe frameBorder='0' scrolling='no' id='pf-cc-iframe' class='intrinsic-ignore' height='220px' style='width:100%' src='https://www.vismapay.com/e-payments/embedded_card_form?lang=".$this->get_lang()."'></iframe></div>" . $clear_both;

			if($this->visa_logo === 'yes' || $this->mc_logo === 'yes' || $this->amex_logo === 'yes' || $this->diners_logo === 'yes')
			{
				echo '<div class="vpe-card-brand-row">';
				if($this->visa_logo === 'yes')
				{
					echo '<div class="vpe-card-brand-container"><img class="vpe-card-brand-logo visa" src="' . esc_url($img_url) . 'visa.png" alt="Visa"/></div>';
					echo '<div class="vpe-card-brand-container"><img class="vpe-card-brand-logo verified" src="' . esc_url($img_url) . 'verified.png" alt="Verified By Visa"/></div>';
				}

				if($this->mc_logo === 'yes')
				{
					echo '<div class="vpe-card-brand-container"><img class="vpe-card-brand-logo" src="' . esc_url($img_url) . 'mastercard.png" alt="MasterCard" /></div>';
					echo '<div class="vpe-card-brand-container"><img class="vpe-card-brand-logo" src="' . esc_url($img_url) . 'securecode.png" alt="MasterCard SecureCode"/></div>';
				}

				if($this->amex_logo === 'yes')
					echo '<div class="vpe-card-brand-container"><img class="vpe-card-brand-logo" src="' . esc_url($img_url) . 'americanexpress.png" alt="American Express" /></div>';

				if($this->diners_logo === 'yes')
					echo '<div class="vpe-card-brand-container"><img class="vpe-card-brand-logo" src="' . esc_url($img_url) . 'dinersclub.png" alt="Diners" /></div>';

				echo $clear_both . '</div>' . $clear_both;
			}

			echo '</div>';
		}

		public function get_lang()
		{
			$finn_langs = array('fi-FI', 'fi', 'fi_FI');
			$sv_langs = array('sv-SE', 'sv', 'sv_SE');
			$current_locale = get_locale();

			if(in_array($current_locale, $finn_langs))
				$lang = 'fi';
			else if (in_array($current_locale, $sv_langs))
				$lang = 'sv';
			else
				$lang = 'en';
			
			return $lang;
		}

		public function process_payment($order_id)
		{
			if (sanitize_key($_POST['payment_method']) != 'visma_pay_embedded_card')
				return false;

			require_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');

			$order = new WC_Order($order_id);
			$wc_order_id = $order->get_id();
			$wc_order_total = $order->get_total();

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->ordernumber_prefix. '_'  .$order_id : $order_id;
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			$redirect_url = $this->get_return_url($order);

			$return_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id), $redirect_url );

			$amount =  (int)(round($wc_order_total*100, 0));

			$order->update_meta_data('visma_pay_embedded_card_is_settled', 99);
			$order->update_meta_data('visma_pay_embedded_card_return_code', 99);
			$order->save();

			$lang = $this->get_lang();

			$payment = new Visma\VismaPay($this->api_key, $this->private_key, 'w3.2', new Visma\VismaPayWPConnector());

			if($this->send_receipt == 'yes')
				$receipt_mail = $order->get_billing_email();
			else
				$receipt_mail = '';

			$payment->addCharge(
				array(
					'order_number' => $order_number,
					'amount' => $amount,
					'currency' => get_woocommerce_currency(),
					'email' =>  $receipt_mail
				)
			);

			$payment = $this->add_customer_to_payment($payment, $order);

			if($this->send_items == 'yes')
			{
				$payment = $this->add_products_to_payment($payment, $order, $amount);
			}

			$register_card_token = 0;

			if(function_exists('wcs_order_contains_subscription')) 
			{
				if(wcs_order_contains_subscription($order) || wcs_order_contains_renewal($order) || wcs_is_subscription($order))
				{
            		$register_card_token =  1;
				}
        	}

			$payment->addPaymentMethod(
				array(
					'type' => 'embedded', 
					'return_url' => $return_url,
					'notify_url' => $return_url,
					'lang' => $lang,
					'token_valid_until' => strtotime('+1 hour'),
					'register_card_token' => $register_card_token
				)
			);

			$return = array(
				'result' => 'success',
				'redirect' => '', // expected but not used
			);

			if(!$this->is_valid_currency() && $this->limit_currencies == 'no')
			{
				$available = false;
				$payment_methods = new Visma\VismaPay($this->api_key, $this->private_key, 'w3.2', new Visma\VismaPayWPConnector());
				try
				{
					$response = $payment_methods->getMerchantPaymentMethods(get_woocommerce_currency());

					if($response->result == 0)
					{
						if(count($response->payment_methods) > 0)
						{
							foreach ($response->payment_methods as $method)
							{
								if($method->group == 'creditcards')
									$available = true;
							}
						}

						if(!$available)
						{
							$this->logger->info('Visma Pay no payment methods available for order: ' . $order_number . ', currency: ' . get_woocommerce_currency(), $this->logcontext);
							wc_add_notice(__('Visma Pay: No payment methods available for the currency: ', 'visma-pay-embedded-card-payment-gateway') . get_woocommerce_currency(), 'error');
							$order_number_text = __('Visma Pay: No payment methods available for the currency: ', 'visma-pay-embedded-card-payment-gateway') .  get_woocommerce_currency();
							$order->add_order_note($order_number_text);
							return $return;
						}
					}
				}
				catch (Visma\VismaPayException $e) 
				{
					$this->logger->error('Visma Pay getMerchantPaymentMethods failed for order: ' . $order_number . ', exception: ' . $e->getCode().' '.$e->getMessage(), $this->logcontext);
				}
			}
			else if(!$this->is_valid_currency())
			{
				$error_text = __('Visma Pay: "Only allow payments in EUR" is enabled and currency was not EUR for order: ', 'visma-pay-embedded-card-payment-gateway');
				$this->logger->info($error_text . $order_number, $this->logcontext);
				wc_add_notice(__('Visma Pay: No payment methods available for the currency: ', 'visma-pay-embedded-card-payment-gateway') . get_woocommerce_currency(), 'notice');
				$order->add_order_note($error_text . $order_number);
				return $return;
			}

			try
			{
				$response = $payment->createCharge();

				if($response->result == 0)
				{
					$order_number_text = __('Visma Pay (Embedded Card) order', 'visma-pay-embedded-card-payment-gateway') . ": " . $order_number . "<br>-<br>" . __('Payment pending. Waiting for result.', 'visma-pay-embedded-card-payment-gateway');
					$order->add_order_note($order_number_text);

					$order->update_meta_data('visma_pay_embedded_card_order_number', $order_number);
					$order_numbers = $order->get_meta('visma_pay_embedded_card_order_numbers', true, 'edit');
					$order_numbers = ($order_numbers) ? array_values($order_numbers) : array();
					$order_numbers[] = $order_number;
					$order->update_meta_data('visma_pay_embedded_card_order_numbers', $order_numbers);
					$order->save();
					
					if(!in_array($this->cancel_url, array('order_new_cart', 'order_new_checkout')))
						WC()->cart->empty_cart();

					$return = array(
						'result'   => 'success',
						'bpf_token' => $response->token,
						'redirect' => ''
					);				
				}
				else if($response->result == 10)
				{
					$errors = '';
					wc_add_notice(__('Visma Pay (Embedded Card) system is currently in maintenance. Please try again in a few minutes.', 'visma-pay-embedded-card-payment-gateway'), 'notice');
					$this->logger->info('Visma Pay (Embedded Card)::CreateCharge. Visma Pay system maintenance in progress.', $this->logcontext);
				}
				else
				{
					$errors = '';
					wc_add_notice(__('Payment failed due to an error.', 'visma-pay-embedded-card-payment-gateway'), 'error');
					if(isset($response->errors))
					{
						foreach ($response->errors as $error) 
						{
							$errors .= ' '.$error;
						}
					}
					$this->logger->error('Visma Pay (Embedded Card)::CreateCharge failed, response: ' . $response->result . ' - Errors: ' . $errors, $this->logcontext);
				}

			}
			catch (Visma\VismaPayException $e) 
			{
				wc_add_notice(__('Payment failed due to an error.', 'visma-pay-embedded-card-payment-gateway'), 'error');
				$this->logger->error('Visma Pay (Embedded Card)::CreateCharge failed, exception: ' . $e->getCode().' '.$e->getMessage(), $this->logcontext);
			}

			if(isset($_REQUEST['pay_for_order']) && $_REQUEST['pay_for_order'] == true)
			{
				echo json_encode($return);
				exit();
			}

			return $return;
		}

		protected function get_order_by_id_and_order_number($order_id, $order_number)
		{
			$order = New WC_Order($order_id);

			$order_numbers = $order->get_meta('visma_pay_embedded_card_order_numbers', true, 'edit');

			if(!$order_numbers)
			{
				$current_order_number = $order->get_meta('visma_pay_embedded_card_order_number', true, 'edit');
				$order_numbers = array($current_order_number);
			}

			if(in_array($order_number, $order_numbers, true));
				return $order;

			return null;
		}

		protected function sanitize_visma_pay_order_number($order_number)
		{
			return preg_replace('/[^\-\p{L}\p{N}_\s@&\/\\()?!=+£$€.,;:*%]/', '', $order_number);
		}

		public function check_visma_pay_embedded_card_response()
		{
			if(count($_GET))
			{
				require_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
				$return_code = isset($_GET['RETURN_CODE']) ? sanitize_text_field($_GET['RETURN_CODE']) : -999;
				$incident_id = isset($_GET['INCIDENT_ID']) ? sanitize_text_field($_GET['INCIDENT_ID']) : null;
				$settled = isset($_GET['SETTLED']) ? sanitize_text_field($_GET['SETTLED']) : null;
				$authcode = isset($_GET['AUTHCODE']) ? sanitize_text_field($_GET['AUTHCODE']) : null;
				$contact_id = isset($_GET['CONTACT_ID']) ? sanitize_text_field($_GET['CONTACT_ID']) : null;
				$order_number = isset($_GET['ORDER_NUMBER']) ? $this->sanitize_visma_pay_order_number($_GET['ORDER_NUMBER']) : null;

				$authcode_confirm = $return_code .'|'. $order_number;

				if(isset($return_code) && $return_code == 0)
				{
					$authcode_confirm .= '|' . $settled;
					if(isset($contact_id) && !empty($contact_id))
						$authcode_confirm .= '|' . $contact_id;
				}
				else if(isset($incident_id) && !empty($incident_id))
					$authcode_confirm .= '|' . $incident_id;

				$authcode_confirm = strtoupper(hash_hmac('sha256', $authcode_confirm, $this->private_key));

				$order_id = isset($_GET['order_id']) ? sanitize_text_field($_GET['order_id']) : null;
				
				if($order_id === null || $order_number === null)
					$this->visma_pay_embedded_die("No order_id nor order_number given.");

				$order = $this->get_order_by_id_and_order_number($order_id, $order_number);
				
				if($order === null)
					$this->visma_pay_embedded_die("Order not found.");

				$wc_order_id = $order->get_id();
				$wc_order_status = $order->get_status();

				if($authcode_confirm === $authcode && $order)
				{
					$current_return_code = $order->get_meta('visma_pay_embedded_card_return_code', true, 'edit');

					if(!$order->is_paid() && $current_return_code != 0)
					{
						$pbw_extra_info = '';

						$payment = new Visma\VismaPay($this->api_key, $this->private_key, 'w3.2', new Visma\VismaPayWPConnector());
						try
						{
							$result = $payment->checkStatusWithOrderNumber($order_number);
							if(isset($result->source->object) && $result->source->object === 'card')
							{
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: Card payment', 'visma-pay-embedded-card-payment-gateway') . "<br>";
								$pbw_extra_info .=  "<br>-<br>" . __('Card payment info: ', 'visma-pay-embedded-card-payment-gateway') . "<br>";

								if(isset($result->source->card_verified))
								{
									$pbw_verified = $this->visma_pay_embedded_card_translate_verified_code($result->source->card_verified);
									$pbw_extra_info .= isset($pbw_verified) ? __('Verified: ', 'visma-pay-embedded-card-payment-gateway') . $pbw_verified . "<br>" : '';
								}

								$pbw_extra_info .= isset($result->source->card_country) ? __('Card country: ', 'visma-pay-embedded-card-payment-gateway') . $result->source->card_country . "<br>" : '';
								$pbw_extra_info .= isset($result->source->client_ip_country) ? __('Client IP country: ', 'visma-pay-embedded-card-payment-gateway') . $result->source->client_ip_country . "<br>" : '';

								if(isset($result->source->error_code))
								{
									$pbw_error = $this->visma_pay_embedded_card_translate_error_code($result->source->error_code);
									$pbw_extra_info .= isset($pbw_error) ? __('Error: ', 'visma-pay-embedded-card-payment-gateway') . $pbw_error . "<br>" : '';
								}

							}
							elseif (isset($result->source->brand))
								$pbw_extra_info .=  "<br>-<br>" . __('Payment method: ', 'visma-pay-embedded-card-payment-gateway') . ' ' . $result->source->brand . "<br>";
						}
						catch(Visma\VismaPayException $e)
						{
							$message = $e->getMessage();
							$this->logger->error('Visma Pay (Embedded Card) REST::checkStatusWithOrderNumber failed, message: ' . $message, $this->logcontext);
						}

						switch($return_code)
						{
							case 0:
								if($settled == 0)
									$pbw_note = __('Visma Pay (Embedded Card) order', 'visma-pay-embedded-card-payment-gateway') . ' ' . $order_number . "<br>-<br>" . __('Payment is authorized. Use settle option to capture funds.', 'visma-pay-embedded-card-payment-gateway') . "<br>";
								else
									$pbw_note = __('Visma Pay (Embedded Card) order', 'visma-pay-embedded-card-payment-gateway') . ' ' . $order_number . "<br>-<br>" . __('Payment accepted.', 'visma-pay-embedded-card-payment-gateway') . "<br>";

								$is_settled = ($settled == 0) ? 0 : 1;
								$order->update_meta_data('visma_pay_embedded_card_order_number', $order_number);
								$order->update_meta_data('visma_pay_embedded_card_is_settled', $is_settled);
								$order->save();

								if(isset($result->source->card_token))
								{
									$order->update_meta_data('visma_pay_embedded_card_token', $result->source->card_token);

									$pbw_extra_info .= __('Card token: ', 'visma-pay-embedded-card-payment-gateway') . ' ' . $result->source->card_token . "<br>";
									$pbw_extra_info .= __('Expiration: ', 'visma-pay-embedded-card-payment-gateway') . ' ' . $result->source->exp_month . '/' . $result->source->exp_year;

									if(function_exists('wcs_get_subscriptions_for_order')) 
									{
										$subscriptions = wcs_get_subscriptions_for_order($order_id, array( 'order_type' => 'any'));

										foreach ($subscriptions as $subscription)
										{
											$card_token = $subscription->get_meta('visma_pay_embedded_card_token', true, 'edit');

											if(!empty($card_token))
												$subscription->update_meta_data('visma_pay_embedded_card_token_old', $card_token);

											$subscription->update_meta_data('visma_pay_embedded_card_token', $result->source->card_token);
											$subscription->add_order_note($pbw_note . $pbw_extra_info);
											$subscription->save();
										}
									}
								}

								$order->add_order_note($pbw_note . $pbw_extra_info);
								$order->payment_complete();
								WC()->cart->empty_cart();
								break;

							case 1:
								$pbw_note = __('Payment was not accepted.', 'visma-pay-embedded-card-payment-gateway') . $pbw_extra_info;
								if($wc_order_status == 'failed')
									$order->add_order_note($pbw_note);
								else
									$order->update_status('failed', $pbw_note);
								break;

							case 4:
								$note = __('Transaction status could not be updated after customer returned from the web page of a bank. Please use the merchant UI to resolve the payment status.', 'visma-pay-embedded-card-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;

							case 10:
								$note = __('Maintenance break. The transaction is not created and the user has been notified and transferred back to the cancel address.', 'visma-pay-embedded-card-payment-gateway');
								if($wc_order_status == 'failed')
									$order->add_order_note($note);
								else
									$order->update_status('failed', $note);
								break;
						}

						$order->update_meta_data('visma_pay_embedded_card_return_code', $return_code);
						$order->save();
					}
				}
				else
					$this->visma_pay_embedded_die("MAC check failed");

				$cancel_url_option = $this->get_option('cancel_url', '');
				$card = (isset($result->source->object) && $result->source->object === 'card') ? true : false;
				$redirect_url = $this->visma_pay_embedded_card_url($return_code, $order, $cancel_url_option, $card);
				wp_redirect($redirect_url);
				exit('Ok');
			}
		}

		public function visma_pay_embedded_card_url($return_code, $order, $cancel_url_option = '', $card = false)
		{
			if($return_code == 0)
				$redirect_url = $this->get_return_url($order);
			else
			{
				if($card)
					$error_msg = __('Card payment failed. Your card has not been charged.', 'visma-pay-embedded-card-payment-gateway');
				else
					$error_msg = __('Payment was canceled or charge was not accepted.', 'visma-pay-embedded-card-payment-gateway');
				switch ($cancel_url_option)
				{
					case 'order_pay':
						do_action( 'woocommerce_set_cart_cookies',  true );
						$redirect_url = $order->get_checkout_payment_url();
						break;
					case 'order_new_cart':
						$redirect_url = wc_get_cart_url();
						break;
					case 'order_new_checkout':
						$redirect_url = wc_get_checkout_url();
						break;
					default:
						do_action( 'woocommerce_set_cart_cookies',  true );
						$redirect_url = $this->get_return_url($order);
						break;
				}
				wc_add_notice($error_msg, 'error');
			}
			
			return $redirect_url;
		}

		protected function visma_pay_embedded_card_translate_error_code($pbw_error_code)
		{
			switch ($pbw_error_code)
			{
				case '04':
					return ' 04 - ' . __('The card is reported lost or stolen.', 'visma-pay-embedded-card-payment-gateway');
				case '05':
					return ' 05 - ' . __('General decline. The card holder should contact the issuer to find out why the payment failed.', 'visma-pay-embedded-card-payment-gateway');
				case '51':
					return ' 51 - ' . __('Insufficient funds. The card holder should verify that there is balance on the account and the online payments are actived.', 'visma-pay-embedded-card-payment-gateway');
				case '54':
					return ' 54 - ' . __('Expired card.', 'visma-pay-embedded-card-payment-gateway');
				case '61':
					return ' 61 - ' . __('Withdrawal amount limit exceeded.', 'visma-pay-embedded-card-payment-gateway');
				case '62':
					return ' 62 - ' . __('Restricted card. The card holder should verify that the online payments are actived.', 'visma-pay-embedded-card-payment-gateway');
				case '1000':
					return ' 1000 - ' . __('Timeout communicating with the acquirer. The payment should be tried again later.', 'visma-pay-embedded-card-payment-gateway');
				default:
					return null;
			}
		}

		protected function visma_pay_embedded_card_translate_verified_code($pbw_verified_code)
		{
			switch ($pbw_verified_code)
			{
				case 'Y':
					return ' Y - ' . __('3-D Secure was used.', 'visma-pay-embedded-card-payment-gateway');
				case 'N':
					return ' N - ' . __('3-D Secure was not used.', 'visma-pay-embedded-card-payment-gateway');
				case 'A':
					return ' A - ' . __('3-D Secure was attempted but not supported by the card issuer or the card holder is not participating.', 'visma-pay-embedded-card-payment-gateway');
				default:
					return null;
			}
		}

		public function visma_pay_embedded_card_settle_payment($order)
		{
			$wc_order_id = $order->get_id();			

			$settle_field = $order->get_meta('visma_pay_embedded_card_is_settled', true, 'edit');
			$settle_check = $settle_field === '0';

			if(!$settle_check)
				return;

			$url = admin_url('post.php?post=' . absint( $wc_order_id ) . '&action=edit');

			if(isset($_GET['visma_pay_embedded_card_settle']))
			{
				$order_number = $order->get_meta('visma_pay_embedded_card_order_number', true, 'edit');
				$settlement_msg = '';

				if($this->visma_pay_embedded_card_process_settlement($order_number, $settlement_msg))
				{
					$order->add_order_note(__('Payment settled.', 'visma-pay-embedded-card-payment-gateway'));
					$order->update_meta_data('visma_pay_embedded_card_is_settled', 1);
					$order->save();
					$settlement_result = '1';
				}
				else
					$settlement_result = '0';

				if(!$settlement_result)
					echo '<div id="message" class="error">' . esc_html($settlement_msg) . ' <p class="form-field"><a href="' . esc_url($url) . '" class="button button-primary">OK</a></p></div>';
				else
				{
					echo '<div id="message" class="updated fade">' . esc_html($settlement_msg) . ' <p class="form-field"><a href="' . esc_url($url) . '" class="button button-primary">OK</a></p></div>';
					return;
				}
			}


			$text = __('Settle payment', 'visma-pay-embedded-card-payment-gateway');
			$url .= '&visma_pay_embedded_card_settle';
			$html = '
				<p class="form-field">
					<a href="' . esc_url($url) . '" class="button button-primary">' . esc_html($text) . '</a>
				</p>';

			echo $html;
		}

		public function visma_pay_embedded_card_process_settlement($order_number, &$settlement_msg)
		{
			$successful = false;
			require_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
			$payment = new Visma\VismaPay($this->api_key, $this->private_key, 'w3.2', new Visma\VismaPayWPConnector());
			try
			{
				$settlement = $payment->settlePayment($order_number);
				$return_code = $settlement->result;

				switch ($return_code)
				{
					case 0:
						$successful = true;
						$settlement_msg = __('Settlement was successful.', 'visma-pay-embedded-card-payment-gateway');
						break;
					case 1:
						$settlement_msg = __('Settlement failed. Validation failed.', 'visma-pay-embedded-card-payment-gateway');
						break;
					case 2:
						$settlement_msg = __('Settlement failed. Either the payment has already been settled or the payment gateway refused to settle payment for given transaction.', 'visma-pay-embedded-card-payment-gateway');
						break;
					default:
						$settlement_msg = __('Settlement failed. Unknown error.', 'visma-pay-embedded-card-payment-gateway');
						break;
				}
			}
			catch (Visma\VismaPayException $e) 
			{
				$message = $e->getMessage();
				$settlement_msg = __('Exception, error: ', 'visma-pay-embedded-card-payment-gateway') . $message;
			}
			return $successful;
		}

		public function visma_pay_embedded_die($msg = '')
		{
			$this->logger->error('Visma Pay Embedded - return failed. Error: ' . $msg, $this->logcontext);
			status_header(400);
			nocache_headers();
			die($msg);
		}

		public function scheduled_subscription_payment( $amount_to_charge, $order)
		{
			require_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
			$subscriptions = wcs_get_subscriptions_for_renewal_order($order);
			$subscription = end($subscriptions);

			$card_token = $subscription->get_meta('visma_pay_embedded_card_token', true, 'edit');

			$payment = new Visma\VismaPay($this->api_key, $this->private_key, 'w3.2', new Visma\VismaPayWPConnector());

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->ordernumber_prefix . '_' . $order->get_id() : $order->get_id();
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			$amount =  (int)(round($amount_to_charge*100, 0));

			$payment->addCharge(
				array(
					'order_number' => $order_number,
					'amount' => $amount,
					'currency' => get_woocommerce_currency(),
					'card_token' => $card_token,
					'email' => $this->send_receipt === 'yes' ? $order->get_billing_email() : ''
				)
			);

			$payment->addInitiator(array(
				'type' => 1
			));

			$payment = $this->add_customer_to_payment($payment, $order);

			if($this->send_items == 'yes')
			{
				$payment = $this->add_products_to_payment($payment, $order,  $amount);
			}

			$note = '';

			try
			{
				$result = $payment->chargeWithCardToken();

				$order->update_meta_data('visma_pay_embedded_card_return_code', $result->result);
				$order->save();

				switch ($result->result) {
					case 0:
						if($result->settled == 0)
						{
							$note = __('Visma Pay (Embedded Card, Subscription) order', 'visma-pay-embedded-card-payment-gateway') . ' ' . $order_number  . "<br>-<br>" . __('Payment is authorized. Use settle option to capture funds.', 'visma-pay-embedded-card-payment-gateway') . "<br>";
							$order->update_meta_data('visma_pay_embedded_card_is_settled', $result->settled);
						}
						else
							$note = __('Visma Pay (Embedded Card, Subscription) order', 'visma-pay-embedded-card-payment-gateway') . ' ' . $order_number  . "<br>-<br>" . __('Payment accepted.', 'visma-pay-embedded-card-payment-gateway') . "<br>";

						$order->update_meta_data('visma_pay_embedded_card_order_number', $order_number);
						$order->save();

						$order->add_order_note($note);
						$order->payment_complete();
						break;
					case 2:
						$note = __('Duplicate order number.', 'visma-pay-embedded-card-payment-gateway');
						$order->update_status('failed', $note);
						break;
					case 3:
						$note = __('Card token not found.', 'visma-pay-embedded-card-payment-gateway');
						$order->update_status('failed', $note);
						break;
					default:
						if(isset($result->errors))
						{
							$errors = '';
							if(isset($result->errors))
							{
								foreach ($result->errors as $error) 
								{
									$errors .= ' ' . $error;
								}
							}

							$this->logger->error('Visma Pay (Embedded Card, Subscription)::chargeWithCardToken failed, response: ' . $result->result . ' - Errors:'. $errors, $this->logcontext);
						}


						$pbw_error = '';
						if(isset($result->source->error_code))
						{
							$pbw_error = $this->visma_pay_embedded_card_translate_error_code($result->source->error_code);
						}
						
						$note = !empty($pbw_error) ? __('Payment failed. The card was not charged. Error: ', 'visma-pay-embedded-card-payment-gateway') . $pbw_error : __('Payment failed. The card was not charged.', 'visma-pay-embedded-card-payment-gateway');

						$order->update_status('failed', $note);
						break;
				}

			}
			catch(Visma\VismaPayException $e)
			{
				$note = __('Payment failed. Exception: ', 'visma-pay-embedded-card-payment-gateway') . $e->getMessage();
				$order->update_status('failed', $note);
			}

			if(!empty($note))
			{
				$subscription->add_order_note($note);
			}
		}

		public function subscription_cancellation($subscription)
		{
			require_once(plugin_dir_path( __FILE__ ).'includes/lib/visma_pay_loader.php');
			if($subscription->get_status() === 'cancelled')
			{
				$key = 'visma_pay_embedded_card_token';
				$success_note = 'The card token %s was successfully deleted';
			}
			else
			{
				$key = 'visma_pay_embedded_card_token_old';
				$success_note = 'The old card token %s was successfully deleted';
			}

			$card_token = $subscription->get_meta($key, true, 'edit');

			$payment = new Visma\VismaPay($this->api_key, $this->private_key, 'w3.2', new Visma\VismaPayWPConnector());

			try
			{
				$result = $payment->deleteCardToken($card_token);

				if($result->result == 0)
				{
					$subscription->add_order_note(sprintf(__($success_note, 'visma-pay-embedded-card-payment-gateway'), $card_token));
				}
				else
				{
					$subscription->add_order_note(sprintf(__('Failed to delete the card token %s. Return code: %s', 'visma-pay-embedded-card-payment-gateway'), $card_token, $result->result));
				}
			}
			catch(Visma\VismaPayException $e)
			{
				$subscription->add_order_note(sprintf(__('Failed to delete the card token %s. Exception: %s', 'visma-pay-embedded-card-payment-gateway'), $card_token, $e->getMessage()));
			}
		}

		protected function add_products_to_payment($payment, $order, $amount)
		{
			$products = array();
			$total_amount = 0;
			$order_items = $order->get_items();

			foreach($order_items as $item)
			{
				$tax_rates = WC_Tax::get_rates($item->get_tax_class());
				if(!empty($tax_rates))
				{
					$tax_rate = reset($tax_rates);
					$line_tax = number_format($tax_rate['rate'], 2, '.', '');
				}
				else
				{
					$i_total = $order->get_item_total($item, false, false);
					$i_tax = $order->get_item_tax($item, false);
					$line_tax = ($i_total > 0) ? number_format($i_tax / $i_total * 100, 2, '.', '') : 0;
				}
				
				$product = array(
					'title' => $item['name'],
					'id' => $item['product_id'],
					'count' => $item['qty'],
					'pretax_price' => (int)(round($order->get_item_total($item, false, false)*100, 0)),
					'price' => (int)(round($order->get_item_total($item, true, false)*100, 0)),
					'tax' => $line_tax,
					'type' => 1
				);
				$total_amount += $product['price'] * $product['count'];
				array_push($products, $product);
			}

			$shipping_items = $order->get_items('shipping');
			foreach ($shipping_items as $item_id => $s_item) {
				$s_total = floatval($s_item->get_total());

				if($s_total > 0)
				{
					$tax_data = $s_item->get_taxes();
					$s_total_tax = 0;

					if(!empty($tax_data['total']))
					{
						foreach ($tax_data['total'] as $tax_amount) {
							$s_total_tax += (float) $tax_amount;
						}
					}

					$s_tax_rate = $s_total_tax / $s_total * 100;

					$product = array(
						'title' => $s_item->get_name(),
						'id' => $s_item->get_method_id(),
						'count' => 1,
						'pretax_price' => round($s_total * 100),
						'price' => round(($s_total + $s_total_tax) * 100),
						'tax' => round($s_tax_rate, 1),
						'type' => 2
					);

					$total_amount += $product['price'] * $product['count'];
					array_push($products, $product);
				}
			}

			if(abs($total_amount - $amount) < 3)
			{
				foreach($products as $product)
				{
					$payment->addProduct(
						array(
							'id' => htmlspecialchars($product['id']),
							'title' => htmlspecialchars($product['title']),
							'count' => $product['count'],
							'pretax_price' => $product['pretax_price'],
							'tax' => $product['tax'],
							'price' => $product['price'],
							'type' => $product['type']
						)
					);
				}
			}

			return $payment;
		}

		protected function add_customer_to_payment($payment, $order)
		{
			$wc_b_first_name = $order->get_billing_first_name();
			$wc_b_last_name = $order->get_billing_last_name();
			$wc_b_email = $order->get_billing_email();
			$wc_b_address_1 = $order->get_billing_address_1();
			$wc_b_address_2 = $order->get_billing_address_2();
			$wc_b_city = $order->get_billing_city();
			$wc_b_postcode = $order->get_billing_postcode();
			$wc_b_country = $order->get_billing_country();
			$wc_s_first_name = $order->get_shipping_first_name();
			$wc_s_last_name = $order->get_shipping_last_name();
			$wc_s_address_1 = $order->get_shipping_address_1();
			$wc_s_address_2 = $order->get_shipping_address_2();
			$wc_s_city = $order->get_shipping_city();
			$wc_s_postcode = $order->get_shipping_postcode();
			$wc_s_country = $order->get_shipping_country();
			$wc_b_phone = $order->get_billing_phone();

			$payment->addCustomer(
				array(
					'firstname' => htmlspecialchars($wc_b_first_name), 
					'lastname' => htmlspecialchars($wc_b_last_name), 
					'email' => htmlspecialchars($wc_b_email), 
					'address_street' => htmlspecialchars($wc_b_address_1.' '.$wc_b_address_2),
					'address_city' => htmlspecialchars($wc_b_city),
					'address_zip' => htmlspecialchars($wc_b_postcode),
					'address_country' => htmlspecialchars($wc_b_country),
					'shipping_firstname' => htmlspecialchars($wc_s_first_name),
					'shipping_lastname' => htmlspecialchars($wc_s_last_name),
					'shipping_address_street' => trim(htmlspecialchars($wc_s_address_1.' '.$wc_s_address_2)),
					'shipping_address_city' => htmlspecialchars($wc_s_city),
					'shipping_address_zip' => htmlspecialchars($wc_s_postcode),
					'shipping_address_country' => htmlspecialchars($wc_s_country),
					'phone' => preg_replace('/[^0-9+ ]/', '', $wc_b_phone)
				)
			);

			return $payment;
		}

		public static function remove_change_payment_method_button($actions, $subscription)
		{
			$card_token = $subscription->get_meta('visma_pay_embedded_card_token', true, 'edit');

			if(!empty($card_token))
			{
				foreach ($actions as $action_key => $action)
				{
					switch ($action_key) 
					{
						case 'change_payment_method':
							unset($actions[ $action_key ]);
							break;
					}
				}
			}

			return $actions;
		}
	}
}
