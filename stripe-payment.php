<?php
/**
 * Plugin Name: Mieux Donner Stripe Payment
 * Plugin URI: https://mieuxdonner.org
 * Description: A custom plugin to integrate Stripe Checkout in WordPress.
 * Version: 1.0
 * Author: Mieux Donner
 * Author URI: https://mieuxdonner.org
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include Stripe PHP library
require_once plugin_dir_path(__FILE__) . '../vendor/autoload.php';

// Add a shortcode to display the Stripe checkout button
function mieuxdonner_enqueue_stripe_scripts() {
    // Load Stripe.js
    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
}
add_action('wp_enqueue_scripts', 'mieuxdonner_enqueue_stripe_scripts');

// Handle the payment request
function mieuxdonner_process_payment() {
    // Validate request method
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        wp_send_json_error(['message' => 'Invalid request method.'], 405);
        exit;
    }

    // Validate nonce for CSRF protection
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mieuxdonner_stripe_payment')) {
        wp_send_json_error(['message' => 'Security check failed.'], 403);
        exit;
    }

    // Comprehensive input validation and sanitization
    $validation_errors = [];

    // Validate and sanitize amount
    $amount = isset($_POST['amount']) ? absint($_POST['amount']) : 0;
    if ($amount < 100) { // Minimum €1.00
        $validation_errors[] = 'Amount must be at least €1.00';
    }
    if ($amount > 99999900) { // Maximum €999,999.00
        $validation_errors[] = 'Amount cannot exceed €999,999.00';
    }

    // Validate and sanitize email
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    if (empty($email) || !is_email($email)) {
        $validation_errors[] = 'Valid email address is required';
    }

    // Validate and sanitize name
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    if (empty($name) || strlen($name) < 2) {
        $validation_errors[] = 'Name must be at least 2 characters long';
    }
    if (strlen($name) > 100) {
        $validation_errors[] = 'Name cannot exceed 100 characters';
    }

    // Validate and sanitize payment type
    $payment_type = isset($_POST['payment_type']) ? sanitize_text_field($_POST['payment_type']) : '';
    if (!in_array($payment_type, ['onetime', 'monthly'])) {
        $validation_errors[] = 'Invalid payment type selected';
    }

    // Validate and sanitize payment method
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'card';
    if (!in_array($payment_method, ['card', 'paypal', 'google_pay', 'apple_pay', 'express_checkout'])) {
        $validation_errors[] = 'Invalid payment method selected';
    }

    // Validate and sanitize charity (single selection)
    $charity = isset($_POST['charity']) ? sanitize_text_field($_POST['charity']) : '';
    $valid_charities = ['all_charities', 'clean_water', 'education_fund', 'medical_aid', 'hunger_relief', 'environmental', 'refugee_support', 'childrens_rights'];

    if (empty($charity)) {
        $validation_errors[] = 'Please select a charity to support';
    } elseif (!in_array($charity, $valid_charities)) {
        $validation_errors[] = 'Invalid charity selection';
    }

    // Validate and sanitize address (optional)
    $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';

    // Validate and sanitize tip percentage
    $tip_percentage = isset($_POST['tip_percentage']) ? absint($_POST['tip_percentage']) : 0;
    if ($tip_percentage < 0 || $tip_percentage > 20) {
        $validation_errors[] = 'Invalid tip percentage';
    }

    // Return validation errors if any
    if (!empty($validation_errors)) {
        wp_send_json_error(['message' => 'Validation failed', 'errors' => $validation_errors], 400);
        exit;
    }

    // Load Stripe configuration
    require_once __DIR__ . '/../../../../secrets.php';
    require_once plugin_dir_path(__FILE__) . '../vendor/stripe/stripe-php/init.php';

    if (empty($stripeSecretKey)) {
        error_log('Stripe secret key not found');
        wp_send_json_error(['message' => 'Payment system configuration error'], 500);
        exit;
    }

    \Stripe\Stripe::setApiKey($stripeSecretKey);

    try {
        // Create charity metadata
        $charity_names = [
            'all_charities' => 'All charities fund',
            'clean_water' => 'Clean Water Initiative',
            'education_fund' => 'Global Education Fund',
            'medical_aid' => 'Emergency Medical Aid',
            'hunger_relief' => 'Hunger Relief Network',
            'environmental' => 'Environmental Protection Alliance',
            'refugee_support' => 'Refugee Support Foundation',
            'childrens_rights' => 'Children\'s Rights Advocacy'
        ];

        $selected_charity_name = $charity_names[$charity] ?? $charity;

        if ($payment_type === 'onetime') {
            if ($payment_method === 'card') {
                // For card payments, use PaymentIntent
                $paymentIntent = \Stripe\PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => 'eur',
                    'receipt_email' => $email,
                    'payment_method_types' => ['card'],
                    'metadata' => [
                        'donor_name' => $name,
                        'donor_address' => $address,
                        'payment_type' => 'onetime',
                        'payment_method' => $payment_method,
                        'selected_charity' => $selected_charity_name,
                        'charity_code' => $charity,
                        'tip_percentage' => $tip_percentage,
                        'plugin_version' => '1.0'
                    ],
                ]);

                wp_send_json_success([
                    'clientSecret' => $paymentIntent->client_secret,
                    'paymentType' => 'onetime',
                    'usePaymentIntent' => true
                ]);
            } elseif ($payment_method === 'express_checkout') {
                // For express checkout (PayPal, Google Pay, Apple Pay), use PaymentIntent with multiple methods
                $payment_method_types = ['card'];
                
                // Add PayPal if available (you need to enable it in your Stripe dashboard)
                try {
                    // Check if PayPal is available for your account
                    $payment_method_types[] = 'paypal';
                } catch (\Exception $e) {
                    // PayPal not available, continue with just card
                }

                $paymentIntent = \Stripe\PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => 'eur',
                    'receipt_email' => $email ?: null,
                    'payment_method_types' => $payment_method_types,
                    'metadata' => [
                        'donor_name' => $name ?: 'Anonymous',
                        'donor_address' => $address,
                        'payment_type' => 'onetime',
                        'payment_method' => $payment_method,
                        'selected_charity' => $selected_charity_name,
                        'charity_code' => $charity,
                        'plugin_version' => '1.0'
                    ],
                ]);

                wp_send_json_success([
                    'clientSecret' => $paymentIntent->client_secret,
                    'paymentType' => 'onetime',
                    'usePaymentIntent' => true
                ]);
            } else {
                // For PayPal, Google Pay, Apple Pay - use Checkout Session
                $checkout_payment_methods = [];
                switch ($payment_method) {
                    case 'paypal':
                        $checkout_payment_methods = ['paypal'];
                        break;
                    case 'google_pay':
                        $checkout_payment_methods = ['card'];
                        break;
                    case 'apple_pay':
                        $checkout_payment_methods = ['card'];
                        break;
                }

                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => $checkout_payment_methods,
                    'customer_email' => $email,
                    'line_items' => [[
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => [
                                'name' => 'Donation to ' . $selected_charity_name,
                            ],
                            'unit_amount' => $amount,
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    'success_url' => home_url('/merci') . '?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => home_url('/donate') . '?cancelled=1',
                    'metadata' => [
                        'donor_name' => $name,
                        'donor_address' => $address,
                        'payment_type' => 'onetime',
                        'payment_method' => $payment_method,
                        'selected_charity' => $selected_charity_name,
                        'charity_code' => $charity,
                        'tip_percentage' => $tip_percentage,
                        'plugin_version' => '1.0'
                    ],
                ]);

                wp_send_json_success([
                    'checkoutUrl' => $session->url,
                    'paymentType' => 'onetime',
                    'useCheckout' => true
                ]);
            }

        } else if ($payment_type === 'monthly') {
            if ($payment_method === 'card') {
                // For card payments, use subscription with PaymentIntent
                // Create or retrieve customer
                $customers = \Stripe\Customer::all([
                    'email' => $email,
                    'limit' => 1
                ]);

                if (count($customers->data) > 0) {
                    $customer = $customers->data[0];
                } else {
                    $customer = \Stripe\Customer::create([
                        'email' => $email,
                        'name' => $name,
                        'metadata' => [
                            'plugin_version' => '1.0'
                        ]
                    ]);
                }

                // Create or retrieve product for monthly donations
                $product_name = 'Monthly Donation';
                $products = \Stripe\Product::all([
                    'limit' => 1,
                    'active' => true
                ]);

                $product = null;
                foreach ($products->data as $existing_product) {
                    if ($existing_product->name === $product_name) {
                        $product = $existing_product;
                        break;
                    }
                }

                if (!$product) {
                    $product = \Stripe\Product::create([
                        'name' => $product_name,
                        'description' => 'Monthly recurring donation'
                    ]);
                }

                // Create price for this specific amount
                $price = \Stripe\Price::create([
                    'product' => $product->id,
                    'unit_amount' => $amount,
                    'currency' => 'eur',
                    'recurring' => ['interval' => 'month']
                ]);

                // Create subscription with card payment method
                $subscription = \Stripe\Subscription::create([
                    'customer' => $customer->id,
                    'items' => [['price' => $price->id]],
                    'payment_behavior' => 'default_incomplete',
                    'payment_settings' => [
                        'save_default_payment_method' => 'on_subscription',
                        'payment_method_types' => ['card']
                    ],
                    'expand' => ['latest_invoice.payment_intent'],
                    'metadata' => [
                        'donor_name' => $name,
                        'donor_address' => $address,
                        'payment_type' => 'monthly',
                        'payment_method' => $payment_method,
                        'selected_charity' => $selected_charity_name,
                        'charity_code' => $charity,
                        'tip_percentage' => $tip_percentage,
                        'plugin_version' => '1.0'
                    ]
                ]);

                wp_send_json_success([
                    'clientSecret' => $subscription->latest_invoice->payment_intent->client_secret,
                    'paymentType' => 'monthly',
                    'subscriptionId' => $subscription->id,
                    'usePaymentIntent' => true
                ]);
            } else {
                // For PayPal monthly subscriptions, use Checkout Session with subscription mode
                $checkout_payment_methods = [];
                switch ($payment_method) {
                    case 'paypal':
                        $checkout_payment_methods = ['paypal'];
                        break;
                    case 'google_pay':
                    case 'apple_pay':
                        // Monthly subscriptions for Google Pay/Apple Pay not supported, fallback to card
                        wp_send_json_error(['message' => 'Monthly subscriptions are only supported with card or PayPal payments'], 400);
                        exit;
                }

                // Create or retrieve product for monthly donations
                $product_name = 'Monthly Donation';
                $products = \Stripe\Product::all([
                    'limit' => 1,
                    'active' => true
                ]);

                $product = null;
                foreach ($products->data as $existing_product) {
                    if ($existing_product->name === $product_name) {
                        $product = $existing_product;
                        break;
                    }
                }

                if (!$product) {
                    $product = \Stripe\Product::create([
                        'name' => $product_name,
                        'description' => 'Monthly recurring donation'
                    ]);
                }

                // Create price for this specific amount
                $price = \Stripe\Price::create([
                    'product' => $product->id,
                    'unit_amount' => $amount,
                    'currency' => 'eur',
                    'recurring' => ['interval' => 'month']
                ]);

                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => $checkout_payment_methods,
                    'customer_email' => $email,
                    'line_items' => [[
                        'price' => $price->id,
                        'quantity' => 1,
                    ]],
                    'mode' => 'subscription',
                    'success_url' => home_url('/merci') . '?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => home_url('/donate') . '?cancelled=1',
                    'metadata' => [
                        'donor_name' => $name,
                        'donor_address' => $address,
                        'payment_type' => 'monthly',
                        'payment_method' => $payment_method,
                        'selected_charity' => $selected_charity_name,
                        'charity_code' => $charity,
                        'tip_percentage' => $tip_percentage,
                        'plugin_version' => '1.0'
                    ],
                ]);

                wp_send_json_success([
                    'checkoutUrl' => $session->url,
                    'paymentType' => 'monthly',
                    'useCheckout' => true
                ]);
            }
        }

    } catch (\Stripe\Exception\InvalidRequestException $e) {
        error_log('Stripe InvalidRequestException: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Invalid payment request'], 400);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Stripe API Error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Payment service temporarily unavailable'], 503);
    } catch (\Exception $e) {
        error_log('Payment processing error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'An unexpected error occurred'], 500);
    }

    exit;
}

add_action('admin_post_mieuxdonner_stripe_payment', 'mieuxdonner_process_payment');
add_action('admin_post_nopriv_mieuxdonner_stripe_payment', 'mieuxdonner_process_payment');


function mieuxdonner_stripe_button() {
    $checkout_url = site_url('/stripe-checkout');
    return '<form action="' . $checkout_url . '" method="POST">
                <button type="submit" style="background-color:#6772E5;color:white;padding:10px 20px;font-size:16px;border:none;border-radius:5px;cursor:pointer;">
                    Donate with Stripe
                </button>
            </form>';
}


function mieuxdonner_stripe_form() {
    ob_start();
    ?>
    <style>
        .donation-form-container {
            max-width: 600px;
            margin: 0 auto;
            font-family: Arial, sans-serif;
        }
        .progress-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 20px;
            background: #f5f5f5;
            border-radius: 8px;
        }
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }
        .progress-step:not(:last-child):after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #ddd;
            z-index: 1;
        }
        .progress-step.active:not(:last-child):after {
            background: #007cba;
        }
        .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }
        .step-circle.active {
            background: #007cba;
        }
        .step-circle.completed {
            background: #28a745;
        }
        .step-label {
            font-size: 12px;
            text-align: center;
        }
        .form-step {
            display: none;
            background: #f5f5f5;
            padding: 30px;
            border-radius: 8px;
            min-height: 400px;
        }
        .form-step.active {
            display: block;
        }
        .form-step h3 {
            margin-top: 0;
            margin-bottom: 20px;
        }
        .charity-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .charity-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: white;
            border-radius: 4px;
            cursor: pointer;
        }
        .charity-option:hover {
            background: #e9ecef;
        }
        .donation-amount-section {
            text-align: center;
        }
        .payment-type-toggle {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .amount-input {
            font-size: 18px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            width: 200px;
        }
        .form-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-primary {
            background: #007cba;
            color: white;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        #express-checkout-element {
            margin-bottom: 20px;
        }
        .payment-divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }
        .payment-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #ddd;
        }
        .payment-divider span {
            background: #f5f5f5;
            padding: 0 15px;
            color: #666;
            font-size: 14px;
        }
        #payment-element {
            background: white;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        #payment-errors {
            color: #dc3545;
            margin-bottom: 10px;
        }
        #donation-summary {
            background: white;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .error-message {
            color: #dc3545;
            margin-bottom: 10px;
        }
        .tipping-section {
            margin-top: 30px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .tip-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .tip-title {
            font-weight: bold;
            color: #8B4513;
        }
        .tip-info {
            cursor: help;
            font-size: 14px;
        }
        .tip-description {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.4;
        }
        .learn-more {
            color: #007cba;
            text-decoration: none;
        }
        .learn-more:hover {
            text-decoration: underline;
        }
        .tip-slider-container {
            position: relative;
            margin-bottom: 15px;
        }
        .tip-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 12px;
            color: #666;
        }
        .tip-slider {
            margin-bottom: 15px;
            position: relative;
        }
        #tip-slider {
            width: 100%;
            height: 8px;
            background: linear-gradient(to right, #f0f0f0 0%, #ff6b6b 100%);
            border-radius: 4px;
            outline: none;
            -webkit-appearance: none;
        }
        #tip-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #ff6b6b;
            cursor: pointer;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        #tip-slider::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #ff6b6b;
            cursor: pointer;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .tip-options {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 15px;
        }
        .tip-btn {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        .tip-btn:hover {
            border-color: #ff6b6b;
            background: #fff5f5;
        }
        .tip-btn.active {
            background: #ff6b6b;
            color: white;
            border-color: #ff6b6b;
        }
        .skip-tip-btn {
            width: 100%;
            padding: 8px;
            background: transparent;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            color: #666;
            text-align: center;
            transition: all 0.2s;
        }
        .skip-tip-btn:hover {
            background: #f5f5f5;
            border-color: #999;
        }
        .tip-amount-display {
            text-align: center;
            font-weight: bold;
            color: #333;
            margin-top: 10px;
        }
    </style>

    <div class="donation-form-container">
        <div class="progress-bar">
            <div class="progress-step active" data-step="1">
                <div class="step-circle active">1</div>
                <div class="step-label">Charity/fund</div>
            </div>
            <div class="progress-step" data-step="2">
                <div class="step-circle">2</div>
                <div class="step-label">Donation amount</div>
            </div>
            <div class="progress-step" data-step="3">
                <div class="step-circle">3</div>
                <div class="step-label">Payment details</div>
            </div>
            <div class="progress-step" data-step="4">
                <div class="step-circle">4</div>
                <div class="step-label">Personal details</div>
            </div>
            <div class="progress-step" data-step="5">
                <div class="step-circle">5</div>
                <div class="step-label">Confirm donation</div>
            </div>
        </div>

        <form id="stripe-donation-form">
            <!-- Step 1: Charity Selection -->
            <div class="form-step active" data-step="1">
                <h3>Select charity to donate</h3>
                <div class="charity-options">
                    <label class="charity-option">
                        <input type="radio" name="charity" value="all_charities" required>
                        <span>All charities fund</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="clean_water" required>
                        <span>Clean Water Initiative</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="education_fund" required>
                        <span>Global Education Fund</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="medical_aid" required>
                        <span>Emergency Medical Aid</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="hunger_relief" required>
                        <span>Hunger Relief Network</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="environmental" required>
                        <span>Environmental Protection Alliance</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="refugee_support" required>
                        <span>Refugee Support Foundation</span>
                    </label>
                    <label class="charity-option">
                        <input type="radio" name="charity" value="childrens_rights" required>
                        <span>Children's Rights Advocacy</span>
                    </label>
                </div>
                <div class="form-navigation">
                    <div></div>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Next</button>
                </div>
            </div>

            <!-- Step 2: Donation Amount -->
            <div class="form-step" data-step="2">
                <div class="donation-amount-section">
                    <h3>Donate to All charities fund</h3>
                    <div class="payment-type-toggle">
                        <label><input type="radio" name="payment_type" value="onetime" checked> One time</label>
                        <label><input type="radio" name="payment_type" value="monthly"> Monthly</label>
                    </div>
                    <div class="form-group">
                        <label for="amount">Donation amount</label>
                        <input type="number" id="amount" name="amount" class="amount-input" min="1" max="999999" step="0.01" value="100" required>
                    </div>
                    
                    <!-- Tipping Section -->
                    <div class="tipping-section">
                        <div class="tip-header">
                            <span class="tip-title">Support our work</span>
                            <span class="tip-info" title="We estimate that the average dollar spent on operations generates $6 for highly effective charities.">ℹ️</span>
                        </div>
                        <p class="tip-description">We estimate that the average dollar spent on operations generates $6 for highly effective charities. <a href="#" class="learn-more">Learn more</a></p>
                        
                        <div class="tip-slider-container">
                            <div class="tip-labels">
                                <span>0%</span>
                                <span>5%</span>
                                <span>10%</span>
                                <span>15%</span>
                                <span>20%</span>
                            </div>
                            <div class="tip-slider">
                                <input type="range" id="tip-slider" min="0" max="20" step="5" value="10" onchange="updateTipAmount()">
                                <div class="tip-track"></div>
                            </div>
                            <div class="tip-options">
                                <button type="button" class="tip-btn" data-tip="0" onclick="setTip(0)">0%</button>
                                <button type="button" class="tip-btn" data-tip="5" onclick="setTip(5)">5%</button>
                                <button type="button" class="tip-btn active" data-tip="10" onclick="setTip(10)">10%</button>
                                <button type="button" class="tip-btn" data-tip="15" onclick="setTip(15)">15%</button>
                                <button type="button" class="tip-btn" data-tip="20" onclick="setTip(20)">20%</button>
                            </div>
                            <button type="button" class="skip-tip-btn" onclick="setTip(0)">Skip</button>
                        </div>
                        
                        <div class="tip-amount-display">
                            <span>Tip amount: €<span id="tip-amount">10.00</span></span>
                            <input type="hidden" id="tip-percentage" name="tip_percentage" value="10">
                        </div>
                    </div>
                </div>
                <div class="form-navigation">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Next</button>
                </div>
            </div>

            <!-- Step 3: Payment Details -->
            <div class="form-step" data-step="3">
                <h3>Payment details</h3>
                <div class="payment-methods">
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="card" checked>
                        <span>💳 Card</span>
                    </label>
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="paypal">
                        <span>💙 PayPal</span>
                    </label>
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="google_pay">
                        <span>🟡 Google Pay</span>
                    </label>
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="apple_pay">
                        <span>🍎 Apple Pay</span>
                    </label>
                </div>
                
                <div id="card-payment-section">
                    <div class="form-group">
                        <label>Card information</label>
                        <div id="payment-element">
                            <!-- Payment Element for cards -->
                        </div>
                        <div id="payment-errors" role="alert"></div>
                    </div>
                </div>
                
                <div id="express-payment-section" style="display: none;">
                    <div id="express-checkout-element">
                        <!-- Express payment buttons -->
                    </div>
                    <div id="express-payment-errors" role="alert"></div>
                </div>
                
                <div class="form-navigation">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Next</button>
                </div>
            </div>

            <!-- Step 4: Personal Details -->
            <div class="form-step" data-step="4">
                <h3>Personal details</h3>
                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="name" required minlength="2" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required maxlength="254">
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" maxlength="255">
                </div>
                <div class="form-navigation">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-primary" onclick="nextStep()">Next</button>
                </div>
            </div>

            <!-- Step 5: Confirmation -->
            <div class="form-step" data-step="5">
                <h3>Donation summary</h3>
                <div id="donation-summary">
                    <p><strong>Charity:</strong> <span id="summary-charity"></span></p>
                    <p><strong>Amount:</strong> €<span id="summary-amount"></span></p>
                    <p><strong>Frequency:</strong> <span id="summary-frequency"></span></p>
                    <p><strong>Name:</strong> <span id="summary-name"></span></p>
                    <p><strong>Email:</strong> <span id="summary-email"></span></p>
                </div>
                <div class="form-navigation">
                    <button type="button" class="btn btn-secondary" onclick="prevStep()">Back</button>
                    <button type="submit" class="btn btn-primary">Confirm</button>
                </div>
            </div>
        </form>

        <div id="validation-errors" class="error-message" style="display: none;"></div>
        <div id="payment-message" class="error-message"></div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        let currentStep = 1;
        let stripe, elements, paymentElement, expressCheckoutElement;
        let expressPaymentProcessed = false;
        let usingCardElement = false;

        document.addEventListener("DOMContentLoaded", function () {
            var publicKey = "pk_test_51QlsvrLNz5yGb5MxNJOOhClOwwpFWwFAZsh0BU3rq0zK6mQ54P5eoWD4d8ZrJB48gMaRL8dCT5csaWz2PU6kxbSP00BSMd84Hy";
            stripe = Stripe(publicKey);

            // Initialize Stripe Elements
            elements = stripe.elements({
                appearance: {
                    theme: 'stripe',
                    variables: {
                        colorPrimary: '#007cba',
                        colorBackground: '#ffffff',
                        colorText: '#30313d',
                        colorDanger: '#dc3545',
                        fontFamily: 'Arial, sans-serif',
                        spacingUnit: '4px',
                        borderRadius: '4px',
                    }
                }
            });

            document.getElementById("stripe-donation-form").addEventListener("submit", handleFormSubmit);
            
            // Add payment method change listeners
            document.addEventListener('change', function(e) {
                if (e.target.name === 'payment_method') {
                    handlePaymentMethodChange(e.target.value);
                }
            });
        });

        function handlePaymentMethodChange(selectedMethod) {
            const cardSection = document.getElementById('card-payment-section');
            const expressSection = document.getElementById('express-payment-section');
            
            if (selectedMethod === 'card') {
                cardSection.style.display = 'block';
                expressSection.style.display = 'none';
            } else {
                cardSection.style.display = 'none';
                expressSection.style.display = 'block';
                // Initialize express payment for the selected method
                initializeExpressPayment(selectedMethod);
            }
        }

        async function initializePaymentElements() {
            try {
                // Initialize card payment element
                if (!paymentElement) {
                    try {
                        paymentElement = elements.create('payment', {
                            paymentMethodTypes: ['card']
                        });

                        paymentElement.mount('#payment-element');
                        usingCardElement = false;
                        console.log('Payment Element mounted successfully');
                    } catch (error) {
                        console.error('Payment Element failed to mount:', error);
                        // Fallback to card element
                        try {
                            paymentElement = elements.create('card', {
                                style: {
                                    base: {
                                        fontSize: '16px',
                                        color: '#424770',
                                        '::placeholder': {
                                            color: '#aab7c4',
                                        },
                                    },
                                },
                            });

                            paymentElement.mount('#payment-element');
                            usingCardElement = true;
                            console.log('Card Element mounted as fallback');
                        } catch (cardError) {
                            console.error('Both Payment Element and Card Element failed:', cardError);
                            document.getElementById("payment-errors").textContent = "Unable to load payment form. Please refresh the page.";
                        }
                    }
                }

            } catch (error) {
                console.error('Failed to initialize payment elements:', error);
                document.getElementById("payment-errors").textContent = "Failed to load payment form. Please refresh the page.";
            }
        }

        async function initializeExpressPayment(paymentMethod) {
            try {
                const expressContainer = document.getElementById('express-checkout-element');
                expressContainer.innerHTML = '';

                if (paymentMethod === 'paypal') {
                    expressContainer.innerHTML = '<button class="btn btn-primary" style="background: #0070ba; width: 100%; padding: 12px;" onclick="handleExpressPayment(\'paypal\')">Continue with PayPal</button>';
                } else if (paymentMethod === 'google_pay') {
                    expressContainer.innerHTML = '<button class="btn btn-primary" style="background: #4285f4; width: 100%; padding: 12px;" onclick="handleExpressPayment(\'google_pay\')">🟡 Pay with Google</button>';
                } else if (paymentMethod === 'apple_pay') {
                    expressContainer.innerHTML = '<button class="btn btn-primary" style="background: #000; width: 100%; padding: 12px;" onclick="handleExpressPayment(\'apple_pay\')">🍎 Pay with Apple Pay</button>';
                }

            } catch (error) {
                console.error('Failed to initialize express payment:', error);
                document.getElementById("express-payment-errors").textContent = "Unable to load " + paymentMethod + " payment.";
            }
        }

        async function handleExpressCheckoutConfirm(event) {
            try {
                console.log('Express checkout event:', event);

                // Get form data
                const charity = document.querySelector('input[name="charity"]:checked')?.value || 'all_charities';
                const amount = parseFloat(document.getElementById("amount").value) || 100;
                const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'onetime';

                // Extract billing details from the express checkout event
                const billingDetails = event.billingDetails || {};
                const name = billingDetails.name || '';
                const email = billingDetails.email || '';
                const address = billingDetails.address ?
                    `${billingDetails.address.line1 || ''} ${billingDetails.address.line2 || ''}`.trim() : '';

                console.log('Billing details from express checkout:', billingDetails);

                // Create PaymentIntent on server
                const response = await createPaymentIntent({
                    amount: Math.round(amount * 100),
                    charity,
                    paymentType,
                    paymentMethod: 'express_checkout',
                    name,
                    email,
                    address
                });

                if (response.useCheckout) {
                    // For checkout sessions, redirect
                    window.location.href = response.checkoutUrl;
                } else if (response.clientSecret) {
                    // For PaymentIntent, confirm the payment
                    event.resolve({
                        clientSecret: response.clientSecret
                    });
                } else {
                    event.reject({
                        reason: 'fail'
                    });
                    document.getElementById("payment-errors").textContent = "Unable to process payment";
                }

            } catch (error) {
                console.error('Express checkout error:', error);
                event.reject({
                    reason: 'fail'
                });
                document.getElementById("payment-errors").textContent = "Payment failed: " + error.message;
            }
        }


        async function nextStep() {
            if (await validateCurrentStep()) {
                clearValidationError();
                if (currentStep < 5) {
                    hideStep(currentStep);
                    currentStep++;
                    showStep(currentStep);
                    updateProgress();

                    if (currentStep === 3) {
                        await initializePaymentElements();
                    }
                    if (currentStep === 5) {
                        updateSummary();
                    }
                }
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                clearValidationError();
                hideStep(currentStep);
                currentStep--;
                showStep(currentStep);
                updateProgress();
            }
        }

        function showStep(step) {
            document.querySelector(`.form-step[data-step="${step}"]`).classList.add('active');
        }

        function hideStep(step) {
            document.querySelector(`.form-step[data-step="${step}"]`).classList.remove('active');
        }

        function updateProgress() {
            document.querySelectorAll('.progress-step').forEach((step, index) => {
                const stepNumber = index + 1;
                const circle = step.querySelector('.step-circle');

                if (stepNumber < currentStep) {
                    circle.classList.add('completed');
                    circle.classList.remove('active');
                    step.classList.add('active');
                } else if (stepNumber === currentStep) {
                    circle.classList.add('active');
                    circle.classList.remove('completed');
                    step.classList.add('active');
                } else {
                    circle.classList.remove('active', 'completed');
                    step.classList.remove('active');
                }
            });
        }

        function showValidationError(message) {
            const errorDiv = document.getElementById('validation-errors');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function clearValidationError() {
            const errorDiv = document.getElementById('validation-errors');
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }

        async function validateCurrentStep() {
            clearValidationError();

            switch(currentStep) {
                case 1:
                    const charity = document.querySelector('input[name="charity"]:checked');
                    if (!charity) {
                        showValidationError('Please select a charity to continue');
                        return false;
                    }
                    return true;
                case 2:
                    const amount = document.getElementById('amount').value;
                    if (!amount || amount < 1 || amount > 999999) {
                        showValidationError('Please enter a valid amount between €1 and €999,999');
                        return false;
                    }
                    return true;
                case 3:
                    // For step 3, just ensure payment elements are initialized
                    // Actual validation happens during payment confirmation
                    if (!paymentElement) {
                        showValidationError('Payment form not ready. Please wait a moment and try again.');
                        return false;
                    }
                    return true;
                case 4:
                    const name = document.getElementById('full_name').value.trim();
                    const email = document.getElementById('email').value.trim();
                    if (!name || name.length < 2) {
                        showValidationError('Please enter a valid name (at least 2 characters)');
                        return false;
                    }
                    if (!email || !/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i.test(email)) {
                        showValidationError('Please enter a valid email address');
                        return false;
                    }
                    return true;
                default:
                    return true;
            }
        }

        function setTip(percentage) {
            // Update slider
            document.getElementById('tip-slider').value = percentage;
            
            // Update hidden input
            document.getElementById('tip-percentage').value = percentage;
            
            // Update active button
            document.querySelectorAll('.tip-btn').forEach(btn => {
                btn.classList.remove('active');
                if (parseInt(btn.dataset.tip) === percentage) {
                    btn.classList.add('active');
                }
            });
            
            // Update tip amount display
            updateTipAmount();
        }

        function updateTipAmount() {
            const donationAmount = parseFloat(document.getElementById('amount').value) || 0;
            const tipPercentage = parseInt(document.getElementById('tip-slider').value) || 0;
            const tipAmount = (donationAmount * tipPercentage / 100).toFixed(2);
            
            document.getElementById('tip-amount').textContent = tipAmount;
            document.getElementById('tip-percentage').value = tipPercentage;
            
            // Update active button to match slider
            document.querySelectorAll('.tip-btn').forEach(btn => {
                btn.classList.remove('active');
                if (parseInt(btn.dataset.tip) === tipPercentage) {
                    btn.classList.add('active');
                }
            });
        }

        // Update tip amount when donation amount changes
        document.addEventListener('DOMContentLoaded', function() {
            // Add listener for amount changes
            document.getElementById('amount').addEventListener('input', updateTipAmount);
        });

        function updateSummary() {
            const charity = document.querySelector('input[name="charity"]:checked');
            const amount = parseFloat(document.getElementById('amount').value) || 0;
            const tipPercentage = parseInt(document.getElementById('tip-percentage').value) || 0;
            const tipAmount = (amount * tipPercentage / 100);
            const totalAmount = amount + tipAmount;
            const paymentType = document.querySelector('input[name="payment_type"]:checked');
            const name = document.getElementById('full_name').value;
            const email = document.getElementById('email').value;

            document.getElementById('summary-charity').textContent = charity ? charity.nextElementSibling.textContent : '';
            document.getElementById('summary-amount').textContent = totalAmount.toFixed(2);
            document.getElementById('summary-frequency').textContent = paymentType ? (paymentType.value === 'monthly' ? 'Monthly' : 'One-time') : '';
            document.getElementById('summary-name').textContent = name;
            document.getElementById('summary-email').textContent = email;
        }


        async function createPaymentIntent(data) {
            const formData = new URLSearchParams();
            formData.append("amount", data.amount);
            formData.append("name", data.name);
            formData.append("email", data.email);
            formData.append("address", data.address || '');
            formData.append("payment_type", data.paymentType);
            formData.append("payment_method", data.paymentMethod);
            formData.append("charity", data.charity);
            formData.append("tip_percentage", data.tipPercentage || 0);
            formData.append("nonce", "<?php echo wp_create_nonce('mieuxdonner_stripe_payment'); ?>");

            const response = await fetch("<?php echo esc_url(admin_url('admin-post.php?action=mieuxdonner_stripe_payment')); ?>", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: formData.toString()
            });

            const result = await response.json();
            if (!result.success) {
                throw new Error(result.data?.message || "Payment processing failed");
            }

            return result.data;
        }

        async function handleExpressPayment(paymentMethod) {
            // Collect current form data
            const charity = document.querySelector('input[name="charity"]:checked')?.value || 'all_charities';
            const amount = parseFloat(document.getElementById("amount").value) || 100;
            const tipPercentage = parseInt(document.getElementById('tip-percentage').value) || 0;
            const tipAmount = amount * tipPercentage / 100;
            const totalAmount = amount + tipAmount;
            const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'onetime';
            
            try {
                // Create checkout session for express payments
                const response = await createPaymentIntent({
                    amount: Math.round(totalAmount * 100),
                    charity,
                    paymentType,
                    paymentMethod,
                    name: '',
                    email: '',
                    address: '',
                    tipPercentage: tipPercentage
                });

                if (response.checkoutUrl) {
                    // Redirect to Stripe Checkout for PayPal, Google Pay, Apple Pay
                    window.location.href = response.checkoutUrl;
                } else {
                    document.getElementById("express-payment-errors").textContent = "Unable to process " + paymentMethod + " payment.";
                }
                
            } catch (error) {
                console.error('Express payment error:', error);
                document.getElementById("express-payment-errors").textContent = "Payment failed: " + error.message;
            }
        }

        async function handleFormSubmit(event) {
            event.preventDefault();

            if (currentStep !== 5) return;

            // Skip if express payment was already processed
            if (expressPaymentProcessed) {
                return;
            }

            // Clear previous error messages
            document.getElementById("payment-errors").textContent = "";
            document.getElementById("payment-message").innerText = "";

            // Get form data
            const charity = document.querySelector('input[name="charity"]:checked').value;
            const amount = parseFloat(document.getElementById("amount").value);
            const tipPercentage = parseInt(document.getElementById('tip-percentage').value) || 0;
            const tipAmount = amount * tipPercentage / 100;
            const totalAmount = amount + tipAmount;
            const paymentType = document.querySelector('input[name="payment_type"]:checked').value;
            const selectedPaymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            const name = document.getElementById("full_name").value.trim();
            const email = document.getElementById("email").value.trim();
            const address = document.getElementById("address").value.trim();

            try {
                if (selectedPaymentMethod === 'card') {
                    // Handle card payment
                    if (!paymentElement) {
                        document.getElementById("payment-errors").textContent = "Payment form not ready. Please wait and try again.";
                        return;
                    }

                    console.log('Payment element ready, using card element:', usingCardElement);

                    // Create PaymentIntent for card payment
                    const response = await createPaymentIntent({
                        amount: Math.round(totalAmount * 100),
                        charity,
                        paymentType,
                        paymentMethod: 'card',
                        name,
                        email,
                        address,
                        tipPercentage: tipPercentage
                    });

                    console.log('PaymentIntent created:', response);

                    // Check if we're using Payment Element or Card Element
                    if (usingCardElement) {
                        // Using Card Element - use confirmCardPayment
                        const { error } = await stripe.confirmCardPayment(response.clientSecret, {
                            payment_method: {
                                card: paymentElement,
                                billing_details: {
                                    name: name,
                                    email: email,
                                    address: address ? { line1: address } : undefined
                                }
                            }
                        });

                        if (error) {
                            document.getElementById("payment-errors").textContent = error.message;
                        } else {
                            const successMessage = paymentType === 'monthly' ?
                                "Monthly subscription set up successfully! Redirecting..." :
                                "One-time donation successful! Redirecting...";
                            document.getElementById("payment-message").innerText = successMessage;
                            setTimeout(() => {
                                window.location.href = "<?php echo esc_url(home_url('/merci')); ?>";
                            }, 2000);
                        }
                    } else {
                        // Using Payment Element - use confirmPayment
                        const { error } = await stripe.confirmPayment({
                            elements,
                            clientSecret: response.clientSecret,
                            confirmParams: {
                                return_url: "<?php echo esc_url(home_url('/merci')); ?>",
                                payment_method_data: {
                                    billing_details: {
                                        name: name,
                                        email: email,
                                        address: address ? { line1: address } : undefined
                                    }
                                }
                            }
                        });

                        if (error) {
                            if (error.type === 'card_error' || error.type === 'validation_error') {
                                document.getElementById("payment-errors").textContent = error.message;
                            } else {
                                document.getElementById("payment-message").innerText = "An unexpected error occurred: " + error.message;
                            }
                        }
                        // Note: For Payment Element, successful payments redirect automatically
                    }
                } else {
                    // Handle express payments (PayPal, Google Pay, Apple Pay)
                    await handleExpressPayment(selectedPaymentMethod);
                }
            } catch (error) {
                document.getElementById("payment-message").innerText = "Network error. Please try again.";
                console.error('Payment error:', error);
            }
        }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('mieuxdonner_stripe_form', 'mieuxdonner_stripe_form');