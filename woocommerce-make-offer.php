<?php
/**
 * Plugin Name: WooCommerce Make Offer
 * Plugin URI: https://yoursite.com
 * Description: Allow customers to make offers on products with automatic acceptance/rejection based on minimum thresholds.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WooCommerce_Make_Offer {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_data_panel'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
        
        // Frontend hooks
        add_action('woocommerce_single_product_summary', array($this, 'maybe_replace_add_to_cart'), 25);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_submit_offer', array($this, 'handle_offer_submission'));
        add_action('wp_ajax_nopriv_submit_offer', array($this, 'handle_offer_submission'));
        
        // Hide price on frontend for offer-enabled products
        add_filter('woocommerce_get_price_html', array($this, 'maybe_hide_price'), 10, 2);
        
        // Replace add to cart button in shop loops
        add_filter('woocommerce_loop_add_to_cart_link', array($this, 'replace_loop_add_to_cart_button'), 10, 2);
        
        // Hook to modify cart item price
        add_action('woocommerce_before_calculate_totals', array($this, 'modify_cart_item_price'));
        
        // Session management
        add_action('init', array($this, 'start_session'));
        
        // Alternative session storage using WP transients if sessions aren't working
        add_action('wp_ajax_get_offer_attempts', array($this, 'get_offer_attempts'));
        add_action('wp_ajax_nopriv_get_offer_attempts', array($this, 'get_offer_attempts'));
    }
    
    public function start_session() {
        if (!session_id()) {
            session_start();
        }
    }
    
    // Better approach using cookies for attempt tracking
    private function get_attempts($product_id) {
        $cookie_name = 'offer_attempts_' . $product_id;
        $attempts = isset($_COOKIE[$cookie_name]) ? intval($_COOKIE[$cookie_name]) : 0;
        
        error_log('Make Offer Debug - Getting attempts from cookie: ' . $cookie_name . ', value: ' . $attempts);
        return $attempts;
    }
    
    private function set_attempts($product_id, $attempts) {
        $cookie_name = 'offer_attempts_' . $product_id;
        
        // Set cookie for 24 hours
        setcookie($cookie_name, $attempts, time() + (24 * 60 * 60), '/');
        $_COOKIE[$cookie_name] = $attempts; // Set immediately for this request
        
        error_log('Make Offer Debug - Setting attempts in cookie: ' . $cookie_name . ', value: ' . $attempts);
        return $attempts;
    }
    
    private function clear_attempts($product_id) {
        $cookie_name = 'offer_attempts_' . $product_id;
        
        // Delete cookie by setting it to expire in the past
        setcookie($cookie_name, '', time() - 3600, '/');
        unset($_COOKIE[$cookie_name]);
        
        error_log('Make Offer Debug - Clearing attempts cookie: ' . $cookie_name);
    }
    
    // Admin Menu
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Make Offer Settings',
            'Make Offer',
            'manage_options',
            'make-offer-settings',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('make_offer_settings', 'make_offer_options');
        
        add_settings_section(
            'make_offer_general',
            'General Settings',
            null,
            'make_offer_settings'
        );
        
        add_settings_field(
            'email_notifications',
            'Email Notifications to Admin',
            array($this, 'email_notifications_callback'),
            'make_offer_settings',
            'make_offer_general'
        );
        
        add_settings_field(
            'first_counter_percentage',
            'First Counter Offer Percentage Above Minimum',
            array($this, 'first_counter_percentage_callback'),
            'make_offer_settings',
            'make_offer_general'
        );
        
        add_settings_field(
            'second_counter_percentage',
            'Second Counter Offer Percentage Above Minimum',
            array($this, 'second_counter_percentage_callback'),
            'make_offer_settings',
            'make_offer_general'
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Make Offer Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('make_offer_settings');
                do_settings_sections('make_offer_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    public function email_notifications_callback() {
        $options = get_option('make_offer_options');
        $value = isset($options['email_notifications']) ? $options['email_notifications'] : 1;
        echo '<input type="checkbox" name="make_offer_options[email_notifications]" value="1" ' . checked(1, $value, false) . ' />';
        echo '<label>Send email notifications when offers are made</label>';
    }
    
    public function first_counter_percentage_callback() {
        $options = get_option('make_offer_options');
        $value = isset($options['first_counter_percentage']) ? $options['first_counter_percentage'] : 25;
        echo '<input type="number" name="make_offer_options[first_counter_percentage]" value="' . esc_attr($value) . '" min="1" max="100" />';
        echo '<label>% above minimum for first counter offer (default: 25%)</label>';
    }
    
    public function second_counter_percentage_callback() {
        $options = get_option('make_offer_options');
        $value = isset($options['second_counter_percentage']) ? $options['second_counter_percentage'] : 15;
        echo '<input type="number" name="make_offer_options[second_counter_percentage]" value="' . esc_attr($value) . '" min="1" max="100" />';
        echo '<label>% above minimum for second counter offer (default: 15%)</label>';
    }
    
    // Product Data Tab
    public function add_product_data_tab($tabs) {
        $tabs['make_offer'] = array(
            'label' => 'Make Offer',
            'target' => 'make_offer_product_data',
            'class' => array('show_if_simple'),
        );
        return $tabs;
    }
    
    public function add_product_data_panel() {
        global $post;
        ?>
        <div id="make_offer_product_data" class="panel woocommerce_options_panel">
            <?php
            woocommerce_wp_checkbox(array(
                'id' => '_enable_make_offer',
                'label' => 'Enable Make Offer',
                'description' => 'Allow customers to make offers on this product'
            ));
            
            echo '<p class="form-field"><strong>Note:</strong> The regular price above will be used as the minimum acceptable offer and will be hidden from customers.</p>';
            ?>
        </div>
        <?php
    }
    
    public function save_product_meta($post_id) {
        $enable_make_offer = isset($_POST['_enable_make_offer']) ? 'yes' : 'no';
        update_post_meta($post_id, '_enable_make_offer', $enable_make_offer);
    }
    
    // Frontend Functions
    public function maybe_hide_price($price, $product) {
        if ($this->is_offer_enabled($product)) {
            return '';
        }
        return $price;
    }
    
    public function replace_loop_add_to_cart_button($button, $product) {
        if ($this->is_offer_enabled($product)) {
            return '<a href="' . get_permalink($product->get_id()) . '" class="button alt make-offer-loop-btn">Make Offer</a>';
        }
        return $button;
    }
    
    public function maybe_replace_add_to_cart() {
        global $product;
        
        if (!$this->is_offer_enabled($product)) {
            return;
        }
        
        // Remove default add to cart button
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        
        // Add our make offer form
        $this->display_make_offer_form($product);
    }
    
    public function display_make_offer_form($product) {
        $session_key = 'offer_attempts_' . $product->get_id();
        $attempts = isset($_SESSION[$session_key]) ? $_SESSION[$session_key] : 0;
        
        ?>
        <div id="make-offer-container">
            <form id="make-offer-form" method="post">
                <div class="make-offer-field">
                    <label for="offer-amount">Your Offer:</label>
                    <input type="number" id="offer-amount" name="offer_amount" min="0" step="0.01" placeholder="Enter your offer" required />
                    <span class="currency-symbol"><?php echo get_woocommerce_currency_symbol(); ?></span>
                </div>
                
                <input type="hidden" name="product_id" value="<?php echo $product->get_id(); ?>" />
                <input type="hidden" name="action" value="submit_offer" />
                <?php wp_nonce_field('make_offer_nonce', 'make_offer_nonce'); ?>
                
                <button type="submit" class="button alt make-offer-btn">Make Offer</button>
            </form>
            
            <div id="offer-response" style="display: none;"></div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            console.log('Inline Make Offer JavaScript loaded');
            
            // Handle accepting counter offers with inline script
            $(document).on('click', '.accept-counter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                console.log('INLINE: Accept counter button clicked!');
                
                var button = $(this);
                var counterAmount = button.data('amount');
                var productId = $('input[name="product_id"]').val();
                
                console.log('INLINE: Product ID:', productId, 'Amount:', counterAmount);
                
                button.prop('disabled', true).text('Processing...');
                
                // Actually do the AJAX call so we can debug what's happening
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'accept_counter_offer',
                        product_id: productId,
                        counter_amount: counterAmount,
                        make_offer_nonce: '<?php echo wp_create_nonce('make_offer_nonce'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('INLINE: AJAX Success:', response);
                        window.location.href = '<?php echo wc_get_cart_url(); ?>';
                    },
                    error: function(xhr, status, error) {
                        console.log('INLINE: AJAX Error:', error);
                        console.log('INLINE: Response text:', xhr.responseText);
                        window.location.href = '<?php echo wc_get_cart_url(); ?>';
                    }
                });
            });
        });
        </script>
        
        <style>
        .make-offer-field {
            position: relative;
            margin-bottom: 15px;
        }
        .make-offer-field input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .currency-symbol {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        .make-offer-btn {
            width: 100%;
            padding: 12px;
            background-color: #0073aa;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .make-offer-btn:hover {
            background-color: #005a87;
        }
        .make-offer-loop-btn {
            background-color: #0073aa !important;
            color: white !important;
            text-decoration: none !important;
        }
        .make-offer-loop-btn:hover {
            background-color: #005a87 !important;
        }
        #offer-response {
            margin-top: 15px;
            padding: 15px;
            border-radius: 4px;
        }
        .offer-accepted {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .offer-rejected {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .counter-offer {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        </style>
        <?php
    }
    
    public function enqueue_scripts() {
        if (is_product()) {
            // First try to enqueue the external file
            wp_enqueue_script('make-offer-script', plugin_dir_url(__FILE__) . 'make-offer.js', array('jquery'), '1.0.0', true);
            wp_localize_script('make-offer-script', 'make_offer_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('make_offer_nonce'),
                'cart_url' => wc_get_cart_url()
            ));
            
            // Also add inline JavaScript as backup
            $this->add_inline_javascript();
        }
    }
    
    public function add_inline_javascript() {
        ?>
        <script type="text/javascript">
        console.log('Make Offer JavaScript loading...');
        jQuery(document).ready(function($) {
            console.log('Make Offer JavaScript ready!');
            
            // Handle accepting counter offers
            $(document).on('click', '.accept-counter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                console.log('Accept counter button clicked!');
                
                var button = $(this);
                var counterAmount = button.data('amount');
                var productId = $('#make-offer-form input[name="product_id"]').val();
                
                console.log('Accept counter clicked - Product ID:', productId, 'Amount:', counterAmount);
                
                button.prop('disabled', true).text('Processing...');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'accept_counter_offer',
                        product_id: productId,
                        counter_amount: counterAmount,
                        make_offer_nonce: '<?php echo wp_create_nonce('make_offer_nonce'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        console.log('AJAX Success - Full response:', response);
                        
                        // Just redirect to cart regardless of response
                        console.log('Redirecting to cart...');
                        window.location.href = '<?php echo wc_get_cart_url(); ?>';
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error - redirecting to cart anyway');
                        window.location.href = '<?php echo wc_get_cart_url(); ?>';
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function handle_offer_submission() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['make_offer_nonce'], 'make_offer_nonce')) {
            wp_die('Security check failed');
        }
        
        $product_id = intval($_POST['product_id']);
        $offer_amount = floatval($_POST['offer_amount']);
        
        $product = wc_get_product($product_id);
        if (!$product || !$this->is_offer_enabled($product)) {
            wp_send_json_error('Invalid product');
            return;
        }
        
        $minimum_price = floatval($product->get_regular_price());
        $attempts = $this->get_attempts($product_id);
        
        // Send email notification if enabled
        $this->maybe_send_email_notification($product, $offer_amount);
        
        // Debug: Let's add some logging to see what's happening
        error_log('Make Offer Debug - Product ID: ' . $product_id . ', Attempts before: ' . $attempts . ', Offer: ' . $offer_amount . ', Minimum: ' . $minimum_price);
        
        if ($offer_amount >= $minimum_price) {
            // Offer accepted
            $this->add_product_to_cart($product, $offer_amount);
            $this->clear_attempts($product_id); // Reset attempts
            
            wp_send_json_success(array(
                'status' => 'accepted',
                'message' => 'Great! Your offer has been accepted. The item has been added to your cart.',
                'redirect' => wc_get_cart_url()
            ));
            
        } else {
            // Offer too low - increment attempts FIRST
            $attempts++;
            $this->set_attempts($product_id, $attempts);
            
            // Debug logging
            error_log('Make Offer Debug - Attempts after increment: ' . $attempts);
            
            $options = get_option('make_offer_options');
            
            if ($attempts == 1) {
                // First counter offer
                $counter_percentage = isset($options['first_counter_percentage']) ? intval($options['first_counter_percentage']) : 25;
                $counter_offer = $minimum_price * (1 + ($counter_percentage / 100));
                
                error_log('Make Offer Debug - First counter: ' . $counter_percentage . '%, Amount: ' . $counter_offer);
                
                wp_send_json_success(array(
                    'status' => 'counter_offer',
                    'message' => 'Thanks for your offer! How about ' . wc_price($counter_offer) . '? You can also make another offer if you prefer.',
                    'counter_amount' => $counter_offer,
                    'show_counter_form' => true,
                    'attempt_number' => $attempts,
                    'debug_info' => 'Attempt: ' . $attempts . ', Percentage: ' . $counter_percentage . '%'
                ));
                
            } elseif ($attempts == 2) {
                // Second counter offer
                $counter_percentage = isset($options['second_counter_percentage']) ? intval($options['second_counter_percentage']) : 15;
                $counter_offer = $minimum_price * (1 + ($counter_percentage / 100));
                
                error_log('Make Offer Debug - Second counter: ' . $counter_percentage . '%, Amount: ' . $counter_offer);
                
                wp_send_json_success(array(
                    'status' => 'counter_offer',
                    'message' => 'How about ' . wc_price($counter_offer) . '? You can also make one more offer if you prefer.',
                    'counter_amount' => $counter_offer,
                    'show_counter_form' => true,
                    'attempt_number' => $attempts,
                    'debug_info' => 'Attempt: ' . $attempts . ', Percentage: ' . $counter_percentage . '%'
                ));
                
            } else {
                // Third attempt or more - offer minimum price as final offer
                error_log('Make Offer Debug - Final offer at minimum: ' . $minimum_price);
                
                wp_send_json_success(array(
                    'status' => 'final_offer',
                    'message' => 'Our final offer is ' . wc_price($minimum_price) . '. This is the minimum we can accept for this item.',
                    'counter_amount' => $minimum_price,
                    'show_accept_button' => true,
                    'attempt_number' => $attempts,
                    'debug_info' => 'Attempt: ' . $attempts . ', Final offer'
                ));
            }
        }
    }
    
    private function add_product_to_cart($product, $price) {
        // Add product to cart with custom price
        $cart_item_key = WC()->cart->add_to_cart($product->get_id());
        
        if ($cart_item_key) {
            // Store the custom price
            WC()->cart->cart_contents[$cart_item_key]['custom_offer_price'] = $price;
            WC()->cart->set_session();
            
            // Hook to modify price in cart
            add_action('woocommerce_before_calculate_totals', array($this, 'modify_cart_item_price'));
        }
    }
    
    public function modify_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_before_calculate_totals') >= 2) return;
        
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['custom_offer_price'])) {
                $cart_item['data']->set_price($cart_item['custom_offer_price']);
            }
        }
    }
    
    private function maybe_send_email_notification($product, $offer_amount) {
        $options = get_option('make_offer_options');
        if (!isset($options['email_notifications']) || !$options['email_notifications']) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $subject = 'New Offer Received - ' . $product->get_name();
        $message = "A new offer has been received:\n\n";
        $message .= "Product: " . $product->get_name() . "\n";
        $message .= "Offer Amount: " . wc_price($offer_amount) . "\n";
        $message .= "Minimum Price: " . wc_price($product->get_regular_price()) . "\n";
        $message .= "Product URL: " . get_permalink($product->get_id()) . "\n";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    private function is_offer_enabled($product) {
        return $product->get_meta('_enable_make_offer') === 'yes';
    }
}

// Initialize the plugin
new WooCommerce_Make_Offer();

// Handle accepting counter offers via AJAX
add_action('wp_ajax_accept_counter_offer', 'handle_accept_counter_offer');
add_action('wp_ajax_nopriv_accept_counter_offer', 'handle_accept_counter_offer');

function handle_accept_counter_offer() {
    if (!wp_verify_nonce($_POST['make_offer_nonce'], 'make_offer_nonce')) {
        wp_die('Security check failed');
    }
    
    $product_id = intval($_POST['product_id']);
    $counter_amount = floatval($_POST['counter_amount']);
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error('Invalid product');
        return;
    }
    
    // Add to cart at counter offer price
    $cart_item_key = WC()->cart->add_to_cart($product->get_id());
    
    if ($cart_item_key) {
        WC()->cart->cart_contents[$cart_item_key]['custom_offer_price'] = $counter_amount;
        WC()->cart->set_session();
        
        // Clear session attempts
        $this->clear_attempts($product_id);
        
        wp_send_json_success(array(
            'status' => 'accepted',
            'message' => 'Perfect! Your item has been added to cart.',
            'redirect' => wc_get_cart_url()
        ));
    } else {
        wp_send_json_error('Failed to add to cart');
    }
}
?>
