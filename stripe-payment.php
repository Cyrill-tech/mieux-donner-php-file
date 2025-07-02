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

            // Create one-time Payment Intent
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'eur',
                'receipt_email' => $email,
                'metadata' => [
                    'donor_name' => $name,
                    'donor_address' => $address,
                    'payment_type' => 'onetime',
                    'selected_charity' => $selected_charity_name,
                    'charity_code' => $charity,
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
                    'donor_address' => $address,
                    'payment_type' => 'monthly',
                    'selected_charity' => $selected_charity_name,
                    'charity_code' => $charity,
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
        .payment-methods {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .payment-method {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        #card-element {
            background: white;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        #card-errors {
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
                        <span>Card</span>
                    </label>
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="bank_transfer">
                        <span>Bank transfer</span>
                    </label>
                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="google_pay">
                        <span>Google pay</span>
                    </label>
                </div>
                <div id="card-payment-section">
                    <div class="form-group">
                        <label>Card information</label>
                        <div id="card-element"></div>
                        <div id="card-errors" role="alert"></div>
                    </div>
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

        <div id="payment-message" class="error-message"></div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        let currentStep = 1;
        let stripe, elements, card;
        
        document.addEventListener("DOMContentLoaded", function () {
            var publicKey = "pk_test_51QlsvrLNz5yGb5MxNJOOhClOwwpFWwFAZsh0BU3rq0zK6mQ54P5eoWD4d8ZrJB48gMaRL8dCT5csaWz2PU6kxbSP00BSMd84Hy";
            stripe = Stripe(publicKey);
            elements = stripe.elements();
            
            // Initialize card element when step 3 becomes active
            initializeCardElement();
            
            document.getElementById("stripe-donation-form").addEventListener("submit", handleFormSubmit);
        });

        function initializeCardElement() {
            if (!card) {
                card = elements.create("card");
                card.mount("#card-element");
            }
        }

        function nextStep() {
            if (validateCurrentStep()) {
                if (currentStep < 5) {
                    hideStep(currentStep);
                    currentStep++;
                    showStep(currentStep);
                    updateProgress();
                    
                    if (currentStep === 3) {
                        initializeCardElement();
                    }
                    if (currentStep === 5) {
                        updateSummary();
                    }
                }
            }
        }

        function prevStep() {
            if (currentStep > 1) {
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

        function validateCurrentStep() {
            switch(currentStep) {
                case 1:
                    const charity = document.querySelector('input[name="charity"]:checked');
                    if (!charity) {
                        alert('Please select a charity');
                        return false;
                    }
                    return true;
                case 2:
                    const amount = document.getElementById('amount').value;
                    if (!amount || amount < 1 || amount > 999999) {
                        alert('Please enter a valid amount between €1 and €999,999');
                        return false;
                    }
                    return true;
                case 3:
                    return true; // Card validation happens during payment
                case 4:
                    const name = document.getElementById('full_name').value.trim();
                    const email = document.getElementById('email').value.trim();
                    if (!name || name.length < 2) {
                        alert('Please enter a valid name');
                        return false;
                    }
                    if (!email || !/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i.test(email)) {
                        alert('Please enter a valid email address');
                        return false;
                    }
                    return true;
                default:
                    return true;
            }
        }

        function updateSummary() {
            const charity = document.querySelector('input[name="charity"]:checked');
            const amount = document.getElementById('amount').value;
            const paymentType = document.querySelector('input[name="payment_type"]:checked');
            const name = document.getElementById('full_name').value;
            const email = document.getElementById('email').value;

            document.getElementById('summary-charity').textContent = charity ? charity.nextElementSibling.textContent : '';
            document.getElementById('summary-amount').textContent = amount;
            document.getElementById('summary-frequency').textContent = paymentType ? (paymentType.value === 'monthly' ? 'Monthly' : 'One-time') : '';
            document.getElementById('summary-name').textContent = name;
            document.getElementById('summary-email').textContent = email;
        }

        async function handleFormSubmit(event) {
            event.preventDefault();
            
            if (currentStep !== 5) return;
            
            // Clear previous error messages
            document.getElementById("card-errors").textContent = "";
            document.getElementById("payment-message").innerText = "";
            
            // Get form data
            const charity = document.querySelector('input[name="charity"]:checked').value;
            const amount = parseFloat(document.getElementById("amount").value);
            const paymentType = document.querySelector('input[name="payment_type"]:checked').value;
            const name = document.getElementById("full_name").value.trim();
            const email = document.getElementById("email").value.trim();
            const address = document.getElementById("address").value.trim();
            
            // Convert to cents for Stripe
            const amountInCents = Math.round(amount * 100);
            
            // Create form data
            const formData = new URLSearchParams();
            formData.append("amount", amountInCents);
            formData.append("name", name);
            formData.append("email", email);
            formData.append("address", address);
            formData.append("payment_type", paymentType);
            formData.append("charity", charity);
            formData.append("nonce", "<?php echo wp_create_nonce('mieuxdonner_stripe_payment'); ?>");
            
            try {
                // Create PaymentIntent
                const response = await fetch("<?php echo esc_url(admin_url('admin-post.php?action=mieuxdonner_stripe_payment')); ?>", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData.toString()
                });

                const data = await response.json();
                
                if (!data.success) {
                    let errorMessage = data.data && data.data.message ? data.data.message : "Payment processing failed.";
                    if (data.data && data.data.errors && Array.isArray(data.data.errors)) {
                        errorMessage += "\n" + data.data.errors.join("\n");
                    }
                    document.getElementById("payment-message").innerText = errorMessage;
                    return;
                }

                // Confirm payment
                const { paymentIntent, error } = await stripe.confirmCardPayment(data.data.clientSecret, {
                    payment_method: { 
                        card: card,
                        billing_details: {
                            name: name,
                            email: email,
                            address: address ? { line1: address } : undefined
                        }
                    }
                });

                if (error) {
                    document.getElementById("card-errors").textContent = error.message;
                } else {
                    const successMessage = paymentType === 'monthly' ? 
                        "Monthly subscription set up successfully! Redirecting..." : 
                        "One-time donation successful! Redirecting...";
                    document.getElementById("payment-message").innerText = successMessage;
                    setTimeout(() => {
                        window.location.href = "<?php echo esc_url(home_url('/merci')); ?>";
                    }, 2000);
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