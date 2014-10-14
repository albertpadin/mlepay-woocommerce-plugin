<?php
if ( ! defined( 'ABSPATH' ) ) { 
    exit;
}

/*
Plugin Name: mlepay
Plugin URI: http://www.mlepay.com
Description: MLePay payment gateway for woocommerce
Author: Made with love by Faye of Symph.
Version: 1.1
Author URI: http://www.sym.ph
*/


if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

  add_action('plugins_loaded', 'mlepay_gateway_init', 0);
  function mlepay_gateway_init() {

    /**
    * Gateway class
    **/
    class WC_Gateway_MlePay extends WC_Payment_Gateway { 

      var $notify_url;

      public function __construct() {

        $this->state = 'CREATED';
        $this->id = 'mlepay';
        $this->method_title = 'MLePay';
        $this->has_fields = false;
        $this->liveurl = 'http://www.mlepay.com/api/v2/transaction/create';

        $this->init_form_fields();
        $this->init_settings();
   
        $this->title = $this->settings['title'];
        $this->instructions = $this->settings['instructions'];
        $this->description = $this->settings['description'];
        $this->mlid = $this->settings['mlid'];
        $this->secure_key = $this->settings['secure_key'];
        $this->merchant_email = $this->settings['merchant_email'];
        $this->expiration_hour = $this->settings['expiration_hour'];
        $this->notify_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'wc_gateway_mlepay', home_url( '/' ) ) );

        add_action('woocommerce_api_wc_gateway_mlepay', array($this, 'check_ipn_response'));
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_mlepay', array( $this, 'receipt_page' ) );
      
      }
      
      /**
       * Initialize Gateway Settings Form Fields
       */
      function init_form_fields() {

        $this -> form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'gateway'),
                'type' => 'checkbox',
                'label' => __('Allow MLePay payment module.', 'gateway'),
                'default' => 'yes'),
            'title' => array(
                'title' => __('Title:', 'gateway'),
                'type'=> 'text',
                'description' => __('The label for this payment method in the list of payment methods that the user will choose from.', 'gateway'),
                'default' => __('MLePay', 'gateway')),
            'description' => array(
                'title' => __('Description:', 'gateway'),
                'type' => 'textarea',
                'description' => __('The description for this payment method in the list of payment methods that the user will choose from.', 'gateway'),
                'default' => __('Pay via MLePay in the nearest M Lhuillier branch.', 'gateway')),
            'merchant_email' => array(
                'title' => __('Email Address', 'gateway'),
                'required' => true,
                'description' => __('The Email address of your MLePay account','gateway')),
            'expiration_hour' => array(
                'title' => __('Expiration in Hours', 'gateway'),
                'required' => true,
                'description' => __('Transaction Expiration in Hours. Defaults to 720 hours (30 days).','gateway'),
                'default' => __('720', 'gateway')),
            'mlid' => array(
                'title' => __('MLePay API Key', 'gateway'),
                'required' => true,
                'description' => __('Your MLePay API Key (found in your MLePay profile page).','gateway')),
            'secure_key' => array(
                'title' => __('MLePay Secret Key', 'gateway'),
                'required' => true,
                'description' =>  __('Your MLePay Secret Key (found in your MLePay profile page).', 'gateway')),
            'instructions' => array(
                'title' => __('Instructions for completing cash payment', 'gateway'),
                'type' => 'textarea',
                'description' => __('Instructions for the customer on how to pay using MLePay.', 'gateway'),
                'default' => __('Present the above code in any M Lhuillier branch and pay to complete your order.', 'gateway'))
        );
      
      }

      /**
      * Admin Panel Options 
      */
      public function admin_options() {

          echo '<h3>'.__('MLePay Payment Gateway', 'woocommerce').'</h3>';
          echo '<p>'.__('Accept cash payments in any M Lhuillier branch.').'</p>';
          echo '<table class="form-table">';
          $this -> generate_settings_html();
          echo '</table>';

      }

      /**
      * Process the payment and check if the currency is in PHP
      */
      function process_payment( $order_id ) {

        global $woocommerce;
        
        $order = new WC_Order( $order_id );
        $woocommerce->cart->empty_cart();

        if( get_woocommerce_currency() == 'PHP' || get_woocommerce_currency() == 'php' ){

          return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url( true )
          );
        }
        else{
          $woocommerce->add_error(__('Currency Error: ', 'woothemes') . "will only accept PHP currency.");
        }
      
      }

      /**
      * Display receipt order
      */
      function receipt_page( $order_id ) {

        global $woocommerce;
        
        try{

          $order = new WC_Order( $order_id );
          $randStrLen = 16;
          $nonce = $this->randString($randStrLen);
          $timestamp = time();
          $expiry = $this->due_time($this->expiration_hour);
          $payload_id = $order->id;
          $product = $order->get_items();
          $product_name = array();

          foreach ( $order->get_items() as $item ) {
           
            if ( $item['qty'] ) {

                $item_loop++;

                $product = $order->get_product_from_item( $item );

                $item_name  = $item['name'];
                $item_name = $item_loop . ". " . $item_name . " ";
            }
            array_push($product_name, $item_name);
          }

          $request_body = array(
                  "receiver_email"=> $this->merchant_email, 
                  "sender_email"=> $order->billing_email,
                  "sender_name"=> $order->billing_first_name.' '.$order->billing_last_name,
                  "sender_phone"=> $order->billing_phone,
                  "sender_address"=> $order->billing_address_1.' '.$order->billing_address_2,
                  "amount"=> (int)($order->get_total() * 100),
                  "currency"=> "PHP",
                  "nonce"=> $nonce,
                  "timestamp"=> $timestamp,
                  "expiry"=> $expiry,
                  "payload"=> $payload_id,
                  "description"=> join(" ",$product_name)
              );

          $data_string = json_encode($request_body);
          $base_string = "POST";
          $base_string .= "&" . 'https%3A//www.mlepay.com/api/v2/transaction/create';
          $base_string .= "&" . rawurlencode($data_string);
          $secret_key = $this->secure_key;

          $signature = base64_encode(hash_hmac("sha256", $base_string, $secret_key, true));
          $ch = curl_init('https://www.mlepay.com/api/v2/transaction/create');  
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
          curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string); 
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);                                                                 
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
              'Content-Type: application/json',                                                                                
              'X-Signature: ' . $signature)                                                                     
          );                                                                                                                   
           
          $result = curl_exec($ch);
          $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          $result = json_decode($result, true);
          echo '<div id="mlepay_transaction_code_wrapper"><span id="mlepay_transaction_code_label">ML ePay Transaction Code:</span> <div id="mlepay_transaction_code">'. $result['transaction']['code'] . '</div><div id="mlepay_transaction_code_instructions">' . $this->instructions . '</div></div>' ;
      
        }
        catch(Exception $e) {

          echo '<div id="mlepay_transaction_code_wrapper"><span id="mlepay_transaction_code_error">An Error occurred. Please try again.</span></div>';
        
        }

    }

      /**
      * Check for the IPN response
      */
      function check_ipn_response() {

        $body_response = file_get_contents('php://input');
        $headers = $this->parse_request_headers();

        $http_url =  explode( ':', $this->notify_url );
        $gateway_url = explode( '?wc-api', $http_url[1] );
        $http_access = rawurlencode($http_url[0].':');
        $http_address = $gateway_url[0];
        $gateway_extension = rawurlencode('?wc-api'.$gateway_url[1]);

        $base_string = 'POST&'.$http_access.$http_address.$gateway_extension.'&'.rawurlencode($body_response);
        $secret_key = $this->secure_key;  
   
        $signature_ipn = base64_encode(hash_hmac("sha256", $base_string, $secret_key, true));
      
        if( $headers['X-Signature'] == $signature_ipn ) {
          
          $result = json_decode( $body_response, true );
          $result['description'];

          if ( ! empty( $result['transaction_status'] ) ) {

            $order = $this->get_mlepay_order( $result['payload'] );
            $result['transaction_status']   = strtolower( $result['transaction_status'] );

            switch ( $result['transaction_status'] ) {
              
              case 'paid' :
                $order->update_status( 'completed', sprintf( __( 'Payment %s in ML Branch.', 'woocommerce' ), 'completed' ) );
                $order->add_order_note( __( 'Payment received in ML Branch', 'woocommerce' ) );
                $order->payment_complete();
                break;

              case 'expired' :
                $order->update_status( 'failed', sprintf( __( 'Transaction code expired.', 'woocommerce' ), 'failed' ) );
                break;

              case 'cancelled' :
                $order->update_status( 'cancelled', sprintf( __( 'Payment %s.', 'woocommerce' ), 'cancelled' ) );
              break;

              default :
              break;
            
            }

            exit;

          }

        }

      }

      /**
      * Validate the order details
      */
      function get_mlepay_order( $payload ) {

        if ( is_numeric( $payload ) ) {

          $order_id  = (int) $payload;
          $order_key = $payload;
        
        } elseif ( is_string( $payload ) ) {
        
          $order_id  = (int) $payload;
          $order_key = $payload;
        
        } else {
        
          list( $order_id ) = $payload;
        
        }

        $order = new WC_Order( $order_id );
        if ( ! isset( $order->id ) ) {
        
          $order_id   = wc_get_order_id_by_order_key( $order_key );
          $order    = new WC_Order( $order_id );
        
        }

        return $order;

      }

      /**
      * Generate random string for nonce
      */
      function randString($length) {

          $charset='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
          $str = '';
          $count = strlen($charset);

          while ($length--) {
              $str .= $charset[mt_rand(0, $count-1)];
          }

          return $str;

      }

      /**
      * Request header
      */
      function parse_request_headers() {
        
        $headers = array();
        foreach($_SERVER as $key => $value) {
        
            if (substr($key, 0, 5) <> 'HTTP_') {
                continue;
            }
        
            $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$header] = $value;
        
        }
        
        return $headers;
      
      }

      /**
      * Get due date
      */
      function due_time($hours){

        date_default_timezone_set("UTC");
        $hours = ((int)$hours) * 60 * 60;
        $expire = date("H:i:s", time()+($hours)); 
        $expiry = strtotime($expire);

        return $expiry;

      }

    }
    
    /**
    * Add the Gateway to WooCommerce
    **/
    function woocommerce_add_gateway_mlepay_gateway($methods) {

        $methods[] = 'WC_Gateway_MlePay';
        return $methods;

    }
   
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_mlepay_gateway' );
    

  }
}



?>