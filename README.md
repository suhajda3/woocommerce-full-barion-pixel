# Full Barion Pixel for WooCommerce

Complete Full Barion Pixel implementation for WooCommerce that tracks all mandatory events according to [Barion's documentation](https://docs.barion.com/Getting_started_with_the_Barion_Pixel).

---

## Features

✅ All mandatory events implemented
- **grantConsent** - Cookie consent
- **setEncryptedEmail** - User email tracking
- **contentView** - Product page views
- **addToCart** - Adding items to cart
- **initiateCheckout** - Starting checkout
- **purchase** - Completed purchases

✅ **Integrates with [Barion Payment Gateway for WooCommerce](https://github.com/szelpe/woocommerce-barion)**
✅ Works with both simple and variable products
✅ Tracks AJAX add to cart
✅ Admin settings page
✅ Automatic and manual consent modes
✅ Console logging for testing

---

### Prerequisites

1. WordPress 5.8 or higher
2. WooCommerce 5.0 or higher
3. PHP 7.4 or higher
4. Active Barion Pixel account with Pixel ID

### About Integration with Barion Payment Gateway

**If you already have the Barion Payment Gateway for WooCommerce plugin installed:**

✅ This plugin will **automatically detect** and use the Pixel ID from your payment gateway settings
✅ No duplicate configuration needed
✅ All events work seamlessly with your existing setup
✅ You can still manage consent and tracking settings independently

**If you don't have the Barion Payment Gateway:**

✅ This plugin works standalone too
✅ Just enter your Pixel ID in the settings
✅ All tracking features work independently

### Installation Steps

1. **Download** the latest release
2. **Upload** to your WordPress:
   - Go to WordPress Admin → Plugins → Add New
   - Click "Upload Plugin"
   - Choose the downloaded file
   - Click "Install Now"
3. **Activate** the plugin
4. **Configure**:
   - Go to WooCommerce → Barion Pixel
   
   **If you have Barion Payment Gateway installed:**
   - The plugin will show that it detected your payment gateway
   - It will automatically use the Pixel ID from your gateway settings
   - You only need to choose the consent mode
   
   **If you don't have the payment gateway:**
   - Enter your Barion Pixel ID (e.g., BP-1234567890-01)
   - Choose consent mode:
     - **Automatic**: Grants consent on page load (simple setup)
     - **Manual**: Use with cookie consent plugins (see below)
   
   - Click "Save Changes"

### Checking Your Configuration

After installation, go to **WooCommerce → Barion Pixel** and check:

- **Active Pixel ID**: Shows which Pixel ID is currently being used
- **Pixel Source**: Shows whether the ID comes from the payment gateway or from this plugin
- **Payment Gateway**: Shows if the Barion Payment Gateway is detected

### Configuration

#### Automatic Consent Mode (Default)

This mode automatically grants consent when the page loads. Suitable if:
- You don't use a cookie consent banner
- You already have user consent through other means
- Your website is for internal/testing purposes

#### Manual Consent Mode (for Cookie Consent Plugins)

If you use a cookie consent plugin (e.g., Cookie Notice, Complianz, CookieYes), select "Manual" mode and add this code when the user accepts cookies:

```javascript
// Call this when user accepts marketing cookies
bp('consent', 'grantConsent');
```

**Examples for popular plugins:**

**Cookie Notice for GDPR:**
```javascript
jQuery(document).on('setCookieNotice', function() {
    if (cnArgs.onAccept) {
        bp('consent', 'grantConsent');
    }
});
```

**Complianz:**
```javascript
document.addEventListener('cmplz_fire_categories', function() {
    if (cmplz_in_array('marketing', cmplz_categories)) {
        bp('consent', 'grantConsent');
    }
});
```

**CookieYes:**
```javascript
document.addEventListener('cookieyes_consent_update', function(eventData) {
    if (eventData.detail.accepted.includes('advertisement')) {
        bp('consent', 'grantConsent');
    }
});
```

Add these scripts to your theme's `footer.php` or use a plugin like "Insert Headers and Footers".

### Testing the Implementation

1. **Open Developer Tools**: Press F12 in your browser
2. **Go to Console tab**
3. **Test each event**:
   - Visit a product page → Look for `contentView` event
   - Click "Add to Cart" → Look for `addToCart` event
   - Go to checkout → Look for `initiateCheckout` event
   - Complete a test purchase → Look for `purchase` event

4. **Look for these messages**:
   - During testing: `"Testing message"` (events are logged but not sent to Barion)
   - After approval: `"Sending message"` (events are actually sent to Barion)

### Brand Support

The plugin automatically tries to detect product brands from:
- `product_brand` taxonomy
- `pwb-brand` taxonomy (Perfect WooCommerce Brands)
- `brand` taxonomy
- Product attributes named "brand" or "pa_brand"

If you use a different brand system, you may need to customize the `get_product_data()` method.

### Troubleshooting

**Events not appearing in console:**
- Check if Pixel ID is correctly entered
- Check if JavaScript errors are present in console
- Ensure WooCommerce is active

**Brand not showing:**
- Install a brand plugin like "Perfect WooCommerce Brands"
- Or add brand as a product attribute
- Or modify the plugin to use your custom brand field

**Consent not working with cookie plugin:**
- Verify the cookie plugin event names
- Check browser console for errors
- Test the consent trigger separately

---

## Support

For issues related to:
- **Plugin functionality**: Check WooCommerce and WordPress logs
- **Barion Pixel approval**: Contact [Barion support](hello@barion.com)
- **Event tracking**: Use browser developer console (F12)
- **Plugin issues**: Submit an issue on [GitHub](https://github.com/suhajda3/woocommerce-full-barion-pixel/issues)

---

## Changelog

### Version 1.1.1
- Declare HPOS (High-Performance Order Storage) compatibility

### Version 1.1.0
- Initial release
- All mandatory Barion Pixel events implemented
- Admin settings page
- Automatic and manual consent modes
- AJAX add to cart support
- Product brand detection

---

## Donate

<a href="https://www.buymeacoffee.com/misi" target="_blank"><img src="https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png" alt="Buy Me A Coffee" style="height: auto !important;width: auto !important;" ></a>

Please consider donating. 🙏

---

## License

This plugin is provided as-is for implementing Full Barion Pixel for WooCommerce sites.
Use at your own risk and always test thoroughly before deploying to production.
