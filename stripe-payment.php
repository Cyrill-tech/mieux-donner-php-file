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
        if ($payment_type === 'onetime') {
            // Create one-time Payment Intent
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'eur',
                'receipt_email' => $email,
                'metadata' => [
                    'donor_name' => $name,
                    'payment_type' => 'onetime',
                    'plugin_version' => '1.0'
                ],
            ]);

            wp_send_json_success([
                'clientSecret' => $paymentIntent->client_secret,
                'paymentType' => 'onetime'
            ]);

        } else if ($payment_type === 'monthly') {
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

            // Create subscription
            $subscription = \Stripe\Subscription::create([
                'customer' => $customer->id,
                'items' => [['price' => $price->id]],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => [
                    'save_default_payment_method' => 'on_subscription'
                ],
                'expand' => ['latest_invoice.payment_intent'],
                'metadata' => [
                    'donor_name' => $name,
                    'payment_type' => 'monthly',
                    'plugin_version' => '1.0'
                ]
            ]);

            wp_send_json_success([
                'clientSecret' => $subscription->latest_invoice->payment_intent->client_secret,
                'paymentType' => 'monthly',
                'subscriptionId' => $subscription->id
            ]);
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
    <form id="stripe-donation-form">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" required minlength="2" maxlength="100" pattern="[A-Za-z\s\-\']+" title="Please enter a valid name (letters, spaces, hyphens, and apostrophes only)">

        <label for="email">Email:</label>
        <input type="email" id="email" name="email" required maxlength="254" pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$" title="Please enter a valid email address">

        <label for="payment_type">Payment Type:</label>
        <select id="payment_type" name="payment_type" required>
            <option value="onetime">One-time Donation</option>
            <option value="monthly">Monthly Donation</option>
        </select>

        <label for="amount">Donation Amount (€):</label>
        <input type="number" id="amount" name="amount" min="1" max="999999" step="0.01" required title="Please enter an amount between €1.00 and €999,999.00">

        <label>Card Info:</label>
        <div id="card-element"></div>
        <div id="card-errors" role="alert"></div>

        <button type="submit">Donate Now</button>
    </form>

    <div id="payment-message"></div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var publicKey = "pk_test_51QlsvrLNz5yGb5MxNJOOhClOwwpFWwFAZsh0BU3rq0zK6mQ54P5eoWD4d8ZrJB48gMaRL8dCT5csaWz2PU6kxbSP00BSMd84Hy"; // key for test mode
            // var publicKey = "pk_live_51QlsvrLNz5yGb5MxXEsj7lLLDZ8bPQ6asHdix3n3RFuszdqDkcx0Hcf5AkGjKQtxQZjgwjUJKIibYVarAsaNqiDC00ZDmdKg4p"; // key for live mode
            var stripe = Stripe(publicKey); // Use your live publishable key
            var elements = stripe.elements();
            var card = elements.create("card");
            card.mount("#card-element");

            document.getElementById("stripe-donation-form").addEventListener("submit", async function(event) {
                event.preventDefault();
                
                // Clear previous error messages
                document.getElementById("card-errors").textContent = "";
                document.getElementById("payment-message").innerText = "";
                
                // Client-side validation
                let amount = parseFloat(document.getElementById("amount").value);
                let name = document.getElementById("name").value.trim();
                let email = document.getElementById("email").value.trim();
                let paymentType = document.getElementById("payment_type").value;
                
                // Validate inputs on client side
                if (!name || name.length < 2 || name.length > 100) {
                    document.getElementById("payment-message").innerText = "Name must be between 2 and 100 characters.";
                    return;
                }
                
                if (!email || !/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i.test(email)) {
                    document.getElementById("payment-message").innerText = "Please enter a valid email address.";
                    return;
                }
                
                if (!amount || amount < 1 || amount > 999999) {
                    document.getElementById("payment-message").innerText = "Amount must be between €1.00 and €999,999.00.";
                    return;
                }

                if (!paymentType || !["onetime", "monthly"].includes(paymentType)) {
                    document.getElementById("payment-message").innerText = "Please select a valid payment type.";
                    return;
                }
                
                // Convert to cents for Stripe
                let amountInCents = Math.round(amount * 100);
                
                // Create URL encoded form data with nonce
                let formData = new URLSearchParams();
                formData.append("amount", amountInCents);
                formData.append("name", name);
                formData.append("email", email);
                formData.append("payment_type", paymentType);
                formData.append("nonce", "<?php echo wp_create_nonce('mieuxdonner_stripe_payment'); ?>");
                
                // Call backend to create a PaymentIntent
                try {
                    let response = await fetch("<?php echo esc_url(admin_url('admin-post.php?action=mieuxdonner_stripe_payment')); ?>", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: formData.toString()
                    });

                    let data = await response.json();
                    
                    if (!data.success) {
                        let errorMessage = data.data && data.data.message ? data.data.message : "Payment processing failed.";
                        if (data.data && data.data.errors && Array.isArray(data.data.errors)) {
                            errorMessage += "\n" + data.data.errors.join("\n");
                        }
                        document.getElementById("payment-message").innerText = errorMessage;
                        return;
                    }

                    // Handle payment confirmation based on type
                    if (data.data.paymentType === 'onetime') {
                        // Confirm one-time payment
                        let { paymentIntent, error } = await stripe.confirmCardPayment(data.data.clientSecret, {
                            payment_method: { 
                                card: card,
                                billing_details: {
                                    name: name,
                                    email: email
                                }
                            }
                        });

                        if (error) {
                            document.getElementById("card-errors").textContent = error.message;
                        } else {
                            document.getElementById("payment-message").innerText = "One-time donation successful! Redirecting...";
                            setTimeout(() => {
                                window.location.href = "<?php echo esc_url(home_url('/merci')); ?>";
                            }, 2000);
                        }
                    } else if (data.data.paymentType === 'monthly') {
                        // Confirm subscription payment
                        let { paymentIntent, error } = await stripe.confirmCardPayment(data.data.clientSecret, {
                            payment_method: { 
                                card: card,
                                billing_details: {
                                    name: name,
                                    email: email
                                }
                            }
                        });

                        if (error) {
                            document.getElementById("card-errors").textContent = error.message;
                        } else {
                            document.getElementById("payment-message").innerText = "Monthly subscription set up successfully! Redirecting...";
                            setTimeout(() => {
                                window.location.href = "<?php echo esc_url(home_url('/merci')); ?>";
                            }, 2000);
                        }
                    }
                } catch (error) {
                    document.getElementById("payment-message").innerText = "Network error. Please try again.";
                    console.error('Payment error:', error);
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('mieuxdonner_stripe_form', 'mieuxdonner_stripe_form');