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
    require_once __DIR__ . '/../../../../secrets.php'; // Load Stripe secret key
    require_once plugin_dir_path(__FILE__) . '../vendor/stripe/stripe-php/init.php';
    \Stripe\Stripe::setApiKey($stripeSecretKey);
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        // Validate input
        $amount = isset($_POST['amount']) ? intval($_POST['amount']) : 0;
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        try {
            // Create Payment Intent
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'eur',
                'receipt_email' => $email,
                // Optionally pass additional metadata
                'metadata' => [
                    'integration_check' => 'accept_a_payment',
                ],
            ]);
            echo json_encode(['clientSecret' => $paymentIntent->client_secret]);
        } catch (\Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

    } else {
        echo json_encode(['error' => 'Invalid request.']);
        http_response_code(400);
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
        <label for="name">Name3:</label>
        <input type="text" id="name" name="name" required>

        <label for="email">Email:</label>
        <input type="email" id="email" name="email" required>

        <label for="amount">Donation Amount (€):</label>
        <input type="number" id="amount" name="amount" min="1" required>

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
                let amount = document.getElementById("amount").value * 100; // Convert to cents
                let name = document.getElementById("name").value;
                let email = document.getElementById("email").value;
                // Create URL encoded form data
                let formData = new URLSearchParams();
                formData.append("amount", amount);
                formData.append("name", name);
                formData.append("email", email);
                // Call backend to create a PaymentIntent
                let response = await fetch("<?php echo esc_url(admin_url('admin-post.php?action=mieuxdonner_stripe_payment')); ?>", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData.toString()
                });

                let data = await response.json();
                if (data.error) {
                    document.getElementById("payment-message").innerText = "Payment failed: " + data.error;
                    return;
                }

                // Confirm payment with Stripe
                let { paymentIntent, error } = await stripe.confirmCardPayment(data.clientSecret, {
                    payment_method: { card: card }
                });

                if (error) {
                    document.getElementById("card-errors").textContent = error.message;
                } else {
                    document.getElementById("payment-message").innerText = "Payment successful!";
                    window.location.href = "<?php echo home_url('/merci'); ?>"; // Redirect on success
                }

            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('mieuxdonner_stripe_form', 'mieuxdonner_stripe_form');