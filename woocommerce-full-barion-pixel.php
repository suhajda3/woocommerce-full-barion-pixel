<?php
/**
 * Plugin Name: Full Barion Pixel for WooCommerce
 * Plugin URI: https://github.com/suhajda3/woocommerce-full-barion-pixel
 * Description: Full Barion Pixel implementation for WooCommerce with all mandatory events
 * Version: 1.1.1
 * Author: Mihaly Balassy
 * Author URI: https://linktr.ee/misi
 * Text Domain: barion-pixel-wc
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Declare HPOS (High-Performance Order Storage) compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

class Barion_Pixel_WooCommerce {
    
    private static $instance = null;
    private $pixel_id = '';
    private $barion_gateway = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize plugin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Hook into existing Barion gateway if available
        add_action('woocommerce_barion_init', array($this, 'integrate_with_barion_gateway'), 10, 2);
        
        // Get Pixel ID - first try our own setting, then check Barion gateway settings
        $this->pixel_id = $this->get_pixel_id();
        
        if (!empty($this->pixel_id)) {
            // Add bp placeholder to prevent "bp is not defined" errors from other plugins
            add_action('wp_head', array($this, 'add_bp_placeholder'), 0);
            
            // Check if Barion Payment Gateway already added the base pixel
            $gateway_has_pixel = $this->gateway_has_base_pixel();
            
            // Only add base pixel if gateway hasn't added it
            if (!$gateway_has_pixel) {
                add_action('wp_head', array($this, 'add_base_pixel'), 1);
            }
            
            // Add tracking scripts
            add_action('wp_footer', array($this, 'add_tracking_scripts'), 999);
            
            // Product page - contentView event
            add_action('woocommerce_after_single_product', array($this, 'track_content_view'));
            
            // Add to cart - addToCart event
            add_action('woocommerce_after_add_to_cart_button', array($this, 'track_add_to_cart_button'));
            add_action('wp_footer', array($this, 'track_ajax_add_to_cart'));
            
            // Checkout page - initiateCheckout event
            add_action('woocommerce_before_checkout_form', array($this, 'track_initiate_checkout'));
            
            // Order received - purchase event
            add_action('woocommerce_thankyou', array($this, 'track_purchase'), 10, 1);
            
            // Email tracking
            if (get_option('barion_pixel_track_email', '1') === '1') {
                add_action('wp_footer', array($this, 'track_user_email'));
            }
        }
    }
    
    /**
     * Add bp placeholder function to prevent "bp is not defined" errors
     */
    public function add_bp_placeholder() {
        ?>
<!-- Barion Pixel Placeholder (prevents "bp is not defined" errors) -->
<script>
window.bp = window.bp || function() {
    window.bp.q = window.bp.q || [];
    window.bp.q.push(arguments);
};
</script>
<!-- End Barion Pixel Placeholder -->
        <?php
    }
    
    /**
     * Check if Barion Payment Gateway has already added base pixel
     */
    private function gateway_has_base_pixel() {
        // Check if the gateway class exists and has pixel tracking
        if (!class_exists('WC_Gateway_Barion')) {
            return false;
        }
        
        // Check if gateway has pixel ID configured
        $barion_gateway_settings = get_option('woocommerce_barion_settings', array());
        
        // If gateway has pixel ID, it likely already added the base pixel
        return isset($barion_gateway_settings['barion_pixel_id']) && !empty($barion_gateway_settings['barion_pixel_id']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Barion Pixel', 'barion-pixel-wc'),
            __('Barion Pixel', 'barion-pixel-wc'),
            'manage_woocommerce',
            'barion-pixel-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Get Pixel ID from various sources
     */
    private function get_pixel_id() {
        // First, check our own setting
        $pixel_id = get_option('barion_pixel_id', '');
        
        if (!empty($pixel_id)) {
            return $pixel_id;
        }
        
        // Check if Barion payment gateway is active and has Pixel ID
        $barion_gateway_settings = get_option('woocommerce_barion_settings', array());
        
        if (isset($barion_gateway_settings['barion_pixel_id']) && !empty($barion_gateway_settings['barion_pixel_id'])) {
            return $barion_gateway_settings['barion_pixel_id'];
        }
        
        return '';
    }
    
    /**
     * Integrate with existing Barion payment gateway
     */
    public function integrate_with_barion_gateway($barion_client, $gateway) {
        $this->barion_gateway = $gateway;
        
        // If we don't have our own Pixel ID, try to use the gateway's
        if (empty($this->pixel_id) && isset($gateway->settings['barion_pixel_id'])) {
            $this->pixel_id = $gateway->settings['barion_pixel_id'];
        }
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('barion_pixel_settings', 'barion_pixel_id');
        register_setting('barion_pixel_settings', 'barion_pixel_consent_mode');
        register_setting('barion_pixel_settings', 'barion_pixel_track_email');
        register_setting('barion_pixel_settings', 'barion_pixel_testing_mode');
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        $barion_gateway_installed = class_exists('WC_Gateway_Barion');
        $barion_gateway_settings = get_option('woocommerce_barion_settings', array());
        $gateway_has_pixel = isset($barion_gateway_settings['barion_pixel_id']) && !empty($barion_gateway_settings['barion_pixel_id']);
        ?>
        <div class="wrap">
            <h1><?php _e('Barion Pixel Settings', 'barion-pixel-wc'); ?></h1>
            
            <?php if ($barion_gateway_installed): ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php _e('Barion Payment Gateway detected', 'barion-pixel-wc'); ?></strong><br>
                    <?php if ($gateway_has_pixel): ?>
                        <?php _e('Your Barion Payment Gateway already has a Pixel ID configured. This plugin will use that ID automatically.', 'barion-pixel-wc'); ?>
                        <br><?php _e('Pixel ID from gateway:', 'barion-pixel-wc'); ?> <code><?php echo esc_html($barion_gateway_settings['barion_pixel_id']); ?></code>
                    <?php else: ?>
                        <?php _e('You can configure the Pixel ID either here or in your Barion Payment Gateway settings.', 'barion-pixel-wc'); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('barion_pixel_settings');
                do_settings_sections('barion_pixel_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="barion_pixel_id"><?php _e('Barion Pixel ID', 'barion-pixel-wc'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="barion_pixel_id" name="barion_pixel_id" 
                                   value="<?php echo esc_attr(get_option('barion_pixel_id')); ?>" 
                                   class="regular-text" placeholder="BP-1234567890-01" 
                                   <?php echo $gateway_has_pixel ? 'disabled' : ''; ?> />
                            <?php if ($gateway_has_pixel): ?>
                            <p class="description">
                                <?php _e('Using Pixel ID from Barion Payment Gateway. To change it, edit the payment gateway settings.', 'barion-pixel-wc'); ?>
                            </p>
                            <?php else: ?>
                            <p class="description">
                                <?php _e('Enter your Barion Pixel ID (e.g., BP-1234567890-01)', 'barion-pixel-wc'); ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="barion_pixel_consent_mode"><?php _e('Consent Mode', 'barion-pixel-wc'); ?></label>
                        </th>
                        <td>
                            <select id="barion_pixel_consent_mode" name="barion_pixel_consent_mode">
                                <option value="auto" <?php selected(get_option('barion_pixel_consent_mode', 'auto'), 'auto'); ?>>
                                    <?php _e('Automatic (grantConsent on page load)', 'barion-pixel-wc'); ?>
                                </option>
                                <option value="manual" <?php selected(get_option('barion_pixel_consent_mode'), 'manual'); ?>>
                                    <?php _e('Manual (use cookie consent plugin)', 'barion-pixel-wc'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Choose "Automatic" to grant consent on page load, or "Manual" if you use a cookie consent plugin.', 'barion-pixel-wc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="barion_pixel_track_email"><?php _e('Track User Emails', 'barion-pixel-wc'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="barion_pixel_track_email" name="barion_pixel_track_email" 
                                       value="1" <?php checked(get_option('barion_pixel_track_email', '1'), '1'); ?> />
                                <?php _e('Enable email tracking (setEncryptedEmail)', 'barion-pixel-wc'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Tracks logged-in users by email. Disable if you see email-related errors in console.', 'barion-pixel-wc'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="barion_pixel_testing_mode"><?php _e('Testing Mode', 'barion-pixel-wc'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="barion_pixel_testing_mode" name="barion_pixel_testing_mode" 
                                       value="1" <?php checked(get_option('barion_pixel_testing_mode', '0'), '1'); ?> />
                                <?php _e('Enable testing mode (show debug messages in browser console)', 'barion-pixel-wc'); ?>
                            </label>
                            <p class="description">
                                <?php _e('When enabled, detailed event tracking information will be logged to the browser console for debugging. Disable in production.', 'barion-pixel-wc'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php if (!$gateway_has_pixel): ?>
                <?php submit_button(); ?>
                <?php else: ?>
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" 
                           value="<?php esc_attr_e('Save Settings', 'barion-pixel-wc'); ?>">
                </p>
                <?php endif; ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Active Configuration', 'barion-pixel-wc'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Active Pixel ID:', 'barion-pixel-wc'); ?></th>
                    <td>
                        <?php if (!empty($this->pixel_id)): ?>
                            <?php echo esc_html($this->pixel_id); ?>
                            <span style="color: green;">✓ <?php _e('Active', 'barion-pixel-wc'); ?></span>
                        <?php else: ?>
                            <span style="color: red;">✗ <?php _e('No Pixel ID configured', 'barion-pixel-wc'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Pixel Source:', 'barion-pixel-wc'); ?></th>
                    <td>
                        <?php 
                        if ($gateway_has_pixel && empty(get_option('barion_pixel_id'))) {
                            _e('Barion Payment Gateway Settings', 'barion-pixel-wc');
                        } elseif (!empty(get_option('barion_pixel_id'))) {
                            _e('Barion Pixel Plugin Settings', 'barion-pixel-wc');
                        } else {
                            _e('Not configured', 'barion-pixel-wc');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Payment Gateway:', 'barion-pixel-wc'); ?></th>
                    <td>
                        <?php if ($barion_gateway_installed): ?>
                            <span style="color: green;">✓ <?php _e('Installed', 'barion-pixel-wc'); ?></span>
                        <?php else: ?>
                            <span style="color: orange;">ℹ <?php _e('Not installed (optional)', 'barion-pixel-wc'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <hr>
            
            <h2><?php _e('Implementation Checklist', 'barion-pixel-wc'); ?></h2>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><?php _e('✓ Base Pixel - Added automatically', 'barion-pixel-wc'); ?></li>
                <li><?php _e('✓ grantConsent - Automatic or manual mode', 'barion-pixel-wc'); ?></li>
                <li><?php _e('✓ setEncryptedEmail - Tracks logged-in users', 'barion-pixel-wc'); ?></li>
                <li><?php _e('✓ contentView - Product pages', 'barion-pixel-wc'); ?></li>
                <li><?php _e('✓ addToCart - Add to cart buttons', 'barion-pixel-wc'); ?></li>
                <li><?php _e('✓ initiateCheckout - Checkout page', 'barion-pixel-wc'); ?></li>
                <li><?php _e('✓ purchase - Order confirmation page', 'barion-pixel-wc'); ?></li>
                <?php if ($barion_gateway_installed): ?>
                <li style="color: green;"><?php _e('✓ Integrated with Barion Payment Gateway', 'barion-pixel-wc'); ?></li>
                <?php endif; ?>
            </ul>
            <hr>
            
            <h2><?php _e('Testing Instructions', 'barion-pixel-wc'); ?></h2>
            
            <?php if (get_option('barion_pixel_testing_mode', '0') === '1'): ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('✓ Testing Mode Enabled', 'barion-pixel-wc'); ?></strong>
                </p>
            </div>
            
            <h3><?php _e('How to Test Your Implementation:', 'barion-pixel-wc'); ?></h3>
            <ol style="line-height: 1.8;">
                <li><?php _e('Open your website in a browser', 'barion-pixel-wc'); ?></li>
                <li><?php _e('Press <strong>F12</strong> to open Developer Tools', 'barion-pixel-wc'); ?></li>
                <li><?php _e('Go to the <strong>Console</strong> tab', 'barion-pixel-wc'); ?></li>
                <li><?php _e('Navigate through your site and perform actions:', 'barion-pixel-wc'); ?>
                    <ul style="margin-top: 8px;">
                        <li><?php _e('Visit a product page → Look for <code>contentView</code> event', 'barion-pixel-wc'); ?></li>
                        <li><?php _e('Click "Add to Cart" → Look for <code>addToCart</code> event', 'barion-pixel-wc'); ?></li>
                        <li><?php _e('Go to checkout → Look for <code>initiateCheckout</code> event', 'barion-pixel-wc'); ?></li>
                        <li><?php _e('Complete a test purchase → Look for <code>purchase</code> event', 'barion-pixel-wc'); ?></li>
                    </ul>
                </li>
                <li><?php _e('Look for console messages showing event tracking', 'barion-pixel-wc'); ?></li>
            </ol>
            
            <h3><?php _e('What to Look For:', 'barion-pixel-wc'); ?></h3>
            <p>
                <strong><?php _e('Before Barion Approval:', 'barion-pixel-wc'); ?></strong><br>
                <?php _e('You will see messages with "Testing message" - this means events are being tracked correctly but not yet sent to Barion.', 'barion-pixel-wc'); ?>
            </p>
            <p>
                <strong><?php _e('After Barion Approval:', 'barion-pixel-wc'); ?></strong><br>
                <?php _e('Messages will change to "Sending message" - this means events are being sent to Barion servers.', 'barion-pixel-wc'); ?>
            </p>
            
            <div class="notice notice-warning inline" style="margin-top: 15px;">
                <p>
                    <strong><?php _e('⚠️ Important:', 'barion-pixel-wc'); ?></strong><br>
                    <?php _e('Remember to <strong>disable testing mode</strong> before going live to avoid console clutter for your customers.', 'barion-pixel-wc'); ?>
                </p>
            </div>
            
            <?php else: ?>
            
            <div class="notice notice-success inline">
                <p>
                    <strong><?php _e('Production Mode Active', 'barion-pixel-wc'); ?></strong><br>
                    <?php _e('Testing mode is disabled. Events are being tracked silently without console logs.', 'barion-pixel-wc'); ?>
                </p>
            </div>
            
            <p><?php _e('To test your implementation, enable <strong>Testing Mode</strong> above and follow the testing instructions.', 'barion-pixel-wc'); ?></p>
            
            <?php endif; ?>
            
            <hr>
            
            <h2><?php _e('Need Help?', 'barion-pixel-wc'); ?></h2>
            <ul style="list-style: disc; padding-left: 20px;">
                <li><?php _e('For Barion Pixel approval: Contact <a href="mailto:hello@barion.com">Barion support</a>', 'barion-pixel-wc'); ?></li>
                <li><?php _e('For plugin issues: Submit an issue on <a href="https://github.com/suhajda3/woocommerce-full-barion-pixel/issues" target="_blank">GitHub</a>', 'barion-pixel-wc'); ?></li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Add Base Barion Pixel
     */
    public function add_base_pixel() {
        ?>
<!-- Barion Pixel Base Code -->
<script>
(function(w,r,i,t,e,_,b,a,s,ep){w['barion']=_;w[_]=w[_]||function()
{(w[_].q=w[_].q||[]).push(arguments)};b=r.createElement(i);b.async=1;
b.src=t;a=r.getElementsByTagName(i)[0];a.parentNode.insertBefore(b,a);
})(window,document,'script','//pixel.barion.com/bp.js','bp');
bp('init', 'addBarionPixelId', '<?php echo esc_js($this->pixel_id); ?>');
</script>
<!-- End Barion Pixel Base Code -->
        <?php
    }
    
    /**
     * Add global tracking scripts
     */
    public function add_tracking_scripts() {
        $consent_mode = get_option('barion_pixel_consent_mode', 'auto');
        $testing_mode = get_option('barion_pixel_testing_mode', '0') === '1';
        ?>
<script>
// Wait for Barion Pixel to be ready
(function() {
    var barionTestingMode = <?php echo $testing_mode ? 'true' : 'false'; ?>;
    
    function initBarionPixel() {
        if (typeof bp === 'undefined') {
            setTimeout(initBarionPixel, 100);
            return;
        }
        
        // Grant consent (automatic mode)
        <?php if ($consent_mode === 'auto'): ?>
        bp('consent', 'grantConsent');
        <?php endif; ?>
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBarionPixel);
    } else {
        initBarionPixel();
    }
})();

// Helper function to safely call bp
function barionTrack(event, properties) {
    if (typeof bp !== 'undefined') {
        bp('track', event, properties);
        
        // Only log in testing mode
        if (window.barionTestingMode) {
            console.log('[Barion Pixel] Event tracked:', event, properties);
        }
    } else {
        if (window.barionTestingMode) {
            console.warn('[Barion Pixel] Pixel not loaded yet for event:', event);
        }
    }
}

// Store testing mode in window for access
window.barionTestingMode = <?php echo $testing_mode ? 'true' : 'false'; ?>;

// Helper function to format price
function barionFormatPrice(price) {
    return parseFloat(price).toFixed(2);
}

// Helper function to get product category path
function barionGetCategoryPath(categories) {
    if (!categories || categories.length === 0) return '';
    return categories.join(', ');
}
</script>
        <?php
    }
    
    /**
     * Track user email (setEncryptedEmail)
     */
    public function track_user_email() {
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $email = $current_user->user_email;
            $testing_mode = get_option('barion_pixel_testing_mode', '0') === '1';
            
            // Validate email
            if (!empty($email) && is_email($email)) {
                // Send plain email - Barion will hash it properly on their side
                // This is more reliable than trying to match their hashing algorithm
                ?>
<script>
// Wait for bp to be available and set email
(function() {
    var emailAttempts = 0;
    var maxAttempts = 50; // 5 seconds max wait
    var testingMode = <?php echo $testing_mode ? 'true' : 'false'; ?>;
    
    function setBpEmail() {
        emailAttempts++;
        
        if (typeof bp !== 'undefined' && typeof bp === 'function') {
            try {
                // Use 'set' method as per Barion documentation
                bp('set', 'setEncryptedEmail', '<?php echo esc_js($email); ?>');
                
                if (testingMode) {
                    console.log('[Barion Pixel] Email set:', '<?php echo esc_js(substr($email, 0, 3)); ?>***');
                }
            } catch(e) {
                if (testingMode) {
                    console.error('[Barion Pixel] Email error:', e);
                }
            }
        } else if (emailAttempts < maxAttempts) {
            setTimeout(setBpEmail, 100);
        }
    }
    
    // Wait for DOM and bp to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setBpEmail);
    } else {
        setTimeout(setBpEmail, 500); // Small delay to ensure bp is initialized
    }
})();
</script>
                <?php
            }
        }
    }
    
    /**
     * Track product view (contentView)
     */
    public function track_content_view() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $product_data = $this->get_product_data($product);
        ?>
<script>
(function() {
    function trackContentView() {
        const contentViewProperties = {
            'contentType': 'Product',
            'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
            'id': '<?php echo esc_js($product_data['id']); ?>',
            'name': '<?php echo esc_js($product_data['name']); ?>',
            'quantity': <?php echo $product_data['stock_quantity']; ?>,
            'unit': 'pcs',
            'unitPrice': <?php echo $product_data['price']; ?>,
            'brand': '<?php echo esc_js($product_data['brand']); ?>',
            'category': '<?php echo esc_js($product_data['category']); ?>',
            'imageUrl': '<?php echo esc_js($product_data['image']); ?>',
            'list': 'ProductPage'
        };
        
        barionTrack('contentView', contentViewProperties);
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackContentView);
    } else {
        trackContentView();
    }
})();
</script>
        <?php
    }
    
    /**
     * Track add to cart button (addToCart event)
     */
    public function track_add_to_cart_button() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $product_data = $this->get_product_data($product);
        ?>
<script>
(function() {
    if (typeof jQuery === 'undefined') return;
    
    jQuery(document).ready(function($) {
        $('form.cart').on('submit', function(e) {
            var quantity = $(this).find('input.qty').val() || 1;
            
            const addToCartProperties = {
                'contentType': 'Product',
                'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
                'id': '<?php echo esc_js($product_data['id']); ?>',
                'name': '<?php echo esc_js($product_data['name']); ?>',
                'quantity': parseFloat(quantity),
                'totalItemPrice': parseFloat(quantity) * <?php echo $product_data['price']; ?>,
                'unit': 'pcs',
                'unitPrice': <?php echo $product_data['price']; ?>,
                'brand': '<?php echo esc_js($product_data['brand']); ?>',
                'category': '<?php echo esc_js($product_data['category']); ?>'
            };
            
            barionTrack('addToCart', addToCartProperties);
        });
    });
})();
</script>
        <?php
    }
    
    /**
     * Track AJAX add to cart (for shop/category pages)
     */
    public function track_ajax_add_to_cart() {
        ?>
<script>
(function() {
    if (typeof jQuery === 'undefined') return;
    
    jQuery(document).ready(function($) {
        // Track AJAX add to cart (shop/category pages)
        $(document.body).on('added_to_cart', function(e, fragments, cart_hash, button) {
            var productId = $(button).data('product_id');
            var quantity = $(button).data('quantity') || 1;
            
            // Get product data via AJAX
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'get_barion_product_data',
                    product_id: productId,
                    quantity: quantity
                },
                success: function(response) {
                    if (response.success) {
                        barionTrack('addToCart', response.data);
                    }
                }
            });
        });
    });
})();
</script>
        <?php
    }
    
    /**
     * Track checkout initiation (initiateCheckout)
     */
    public function track_initiate_checkout() {
        $cart = WC()->cart;
        
        if ($cart->is_empty()) {
            return;
        }
        
        $cart_contents = array();
        $cart_total = 0;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_data = $this->get_product_data($product, $cart_item['quantity']);
            
            $cart_contents[] = array(
                'contentType' => 'Product',
                'currency' => get_woocommerce_currency(),
                'id' => $product_data['id'],
                'name' => $product_data['name'],
                'quantity' => $cart_item['quantity'],
                'totalItemPrice' => $cart_item['line_total'],
                'unit' => 'pcs',
                'unitPrice' => $product_data['price'],
                'brand' => $product_data['brand'],
                'category' => $product_data['category']
            );
            
            $cart_total += $cart_item['line_total'];
        }
        ?>
<script>
(function() {
    function trackCheckout() {
        const checkoutProperties = {
            'contents': <?php echo json_encode($cart_contents); ?>,
            'currency': '<?php echo esc_js(get_woocommerce_currency()); ?>',
            'revenue': <?php echo $cart_total; ?>,
            'step': 1
        };
        
        barionTrack('initiateCheckout', checkoutProperties);
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackCheckout);
    } else {
        trackCheckout();
    }
})();
</script>
        <?php
    }
    
    /**
     * Track purchase (purchase event)
     */
    public function track_purchase($order_id) {
        if (!$order_id) {
            return;
        }
        
        // Check if already tracked
        if (get_post_meta($order_id, '_barion_pixel_tracked', true)) {
            return;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $order_contents = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }
            
            $product_data = $this->get_product_data($product, $item->get_quantity());
            
            $order_contents[] = array(
                'contentType' => 'Product',
                'currency' => $order->get_currency(),
                'id' => $product_data['id'],
                'name' => $product_data['name'],
                'quantity' => $item->get_quantity(),
                'totalItemPrice' => $item->get_total(),
                'unit' => 'pcs',
                'unitPrice' => $product_data['price'],
                'brand' => $product_data['brand'],
                'category' => $product_data['category']
            );
        }
        
        // Get user email if available and email tracking is enabled
        $user_email = '';
        $track_email = get_option('barion_pixel_track_email', '1') === '1';
        $testing_mode = get_option('barion_pixel_testing_mode', '0') === '1';
        
        if ($track_email) {
            $user_email = $order->get_billing_email();
            if (!empty($user_email) && !is_email($user_email)) {
                $user_email = ''; // Clear invalid email
            }
        }
        ?>
<script>
(function() {
    var purchaseAttempts = 0;
    var maxAttempts = 50;
    var testingMode = <?php echo $testing_mode ? 'true' : 'false'; ?>;
    
    function trackPurchase() {
        purchaseAttempts++;
        
        if (typeof bp !== 'undefined' && typeof bp === 'function') {
            try {
                <?php if (!empty($user_email)): ?>
                // Set user email for purchase tracking
                bp('set', 'setEncryptedEmail', '<?php echo esc_js($user_email); ?>');
                
                if (testingMode) {
                    console.log('[Barion Pixel] Purchase email set');
                }
                <?php endif; ?>
                
                const purchaseProperties = {
                    'contents': <?php echo json_encode($order_contents); ?>,
                    'currency': '<?php echo esc_js($order->get_currency()); ?>',
                    'orderId': '<?php echo esc_js($order->get_order_number()); ?>',
                    'revenue': <?php echo $order->get_total(); ?>,
                    'shipping': <?php echo $order->get_shipping_total(); ?>,
                    'tax': <?php echo $order->get_total_tax(); ?>
                };
                
                // Use barionTrack if available, otherwise call bp directly
                if (typeof barionTrack === 'function') {
                    barionTrack('purchase', purchaseProperties);
                } else {
                    bp('track', 'purchase', purchaseProperties);
                    if (testingMode) {
                        console.log('[Barion Pixel] Purchase tracked:', purchaseProperties);
                    }
                }
            } catch(e) {
                if (testingMode) {
                    console.error('[Barion Pixel] Purchase error:', e);
                }
            }
        } else if (purchaseAttempts < maxAttempts) {
            setTimeout(trackPurchase, 100);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackPurchase);
    } else {
        setTimeout(trackPurchase, 500);
    }
})();
</script>
        <?php
        
        // Mark as tracked
        update_post_meta($order_id, '_barion_pixel_tracked', true);
    }
    
    /**
     * Get product data helper
     */
    private function get_product_data($product, $quantity = null) {
        $categories = array();
        $terms = get_the_terms($product->get_id(), 'product_cat');
        
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = $term->name;
            }
        }
        
        // Try to get brand (common brand taxonomies/attributes)
        $brand = '';
        $brand_taxonomy = array('product_brand', 'pwb-brand', 'brand');
        
        foreach ($brand_taxonomy as $taxonomy) {
            $brand_terms = get_the_terms($product->get_id(), $taxonomy);
            if ($brand_terms && !is_wp_error($brand_terms)) {
                $brand = $brand_terms[0]->name;
                break;
            }
        }
        
        // If no brand found, try product attributes
        if (empty($brand)) {
            $attributes = $product->get_attributes();
            if (isset($attributes['brand']) || isset($attributes['pa_brand'])) {
                $brand_attr = isset($attributes['brand']) ? $attributes['brand'] : $attributes['pa_brand'];
                if (is_a($brand_attr, 'WC_Product_Attribute')) {
                    $brand = $brand_attr->get_options()[0] ?? '';
                }
            }
        }
        
        $stock_quantity = $product->get_stock_quantity();
        if (null === $quantity) {
            $quantity = $stock_quantity ? $stock_quantity : 1;
        }
        
        return array(
            'id' => 'product_' . $product->get_id(),
            'name' => $product->get_name(),
            'price' => (float) $product->get_price(),
            'sku' => $product->get_sku(),
            'brand' => $brand,
            'category' => implode('|', $categories),
            'image' => wp_get_attachment_url($product->get_image_id()),
            'stock_quantity' => $quantity
        );
    }
}

// Initialize plugin
add_action('plugins_loaded', array('Barion_Pixel_WooCommerce', 'get_instance'));

// AJAX handler for product data
add_action('wp_ajax_get_barion_product_data', 'barion_ajax_get_product_data');
add_action('wp_ajax_nopriv_get_barion_product_data', 'barion_ajax_get_product_data');

function barion_ajax_get_product_data() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    if (!$product_id) {
        wp_send_json_error();
        return;
    }
    
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error();
        return;
    }
    
    $instance = Barion_Pixel_WooCommerce::get_instance();
    $reflection = new ReflectionClass($instance);
    $method = $reflection->getMethod('get_product_data');
    $method->setAccessible(true);
    $product_data = $method->invoke($instance, $product, $quantity);
    
    $response = array(
        'contentType' => 'Product',
        'currency' => get_woocommerce_currency(),
        'id' => $product_data['id'],
        'name' => $product_data['name'],
        'quantity' => (float) $quantity,
        'totalItemPrice' => (float) $quantity * $product_data['price'],
        'unit' => 'pcs',
        'unitPrice' => $product_data['price'],
        'brand' => $product_data['brand'],
        'category' => $product_data['category']
    );
    
    wp_send_json_success($response);
}
