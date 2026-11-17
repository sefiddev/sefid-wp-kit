<?php
/**
 * The live store (Sefid ADR-0077): Sefid asks this shop, signed with the site secret, for products (when the owner
 * chose "live"), and, as features the Sefid team turns on by agreement, a signed-in customer's orders, cart, saved
 * addresses and checkout. Nothing here runs unless Sefid signs the request; a customer is known only from the identity
 * token Sefid signed with the same secret. The cart is the customer's real WooCommerce cart (the persistent cart they
 * see when they next open the shop), never a copy kept by Sefid. A payment for a checkout Sefid started reports back to
 * that same chat.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Any store route: the same signature as every Sefid call, over this route's own path. */
function sefid_store_verify($request) {
    $secret = sefid_opt('secret_key');
    $public = sefid_opt('public_key');
    if (!$secret || !$public) return false;
    $key = (string) $request->get_header('x-sefid-key');
    if (!$key || !hash_equals($public, $key)) return false;
    $ts    = (string) $request->get_header('x-sefid-timestamp');
    $nonce = (string) $request->get_header('x-sefid-nonce');
    $sig   = (string) $request->get_header('x-sefid-signature');
    if (!$ts || !$nonce || !$sig) return false;
    if (abs(round(microtime(true) * 1000) - intval($ts)) > 5 * 60 * 1000) return false;
    // a nonce is used once (five minutes is the window the timestamp allows)
    $seen = 'sefid_nonce_' . md5($nonce);
    if (get_transient($seen)) return false;
    set_transient($seen, 1, 6 * MINUTE_IN_SECONDS);
    $body = $request->get_body();
    $path = '/wp-json' . $request->get_route();
    return hash_equals(sefid_sign_request('POST', $path, $ts, $nonce, $body === null ? '' : $body), $sig);
}

/**
 * The customer an ability runs for (includes/abilities.php): WordPress's own signed-in user, set for the length of one
 * ability call and cleared after it. It is a PHP value, never read from a request, so nothing a caller sends can set it.
 */
function sefid_ability_user($set = null) {
    static $uid = 0;
    if ($set !== null) $uid = absint($set);
    return $uid;
}

/** The signed-in customer Sefid vouches for (x-sefid-identity), or 0. */
function sefid_store_user($request) {
    $acting = sefid_ability_user();
    if ($acting) return get_userdata($acting) ? $acting : 0;
    $token = (string) $request->get_header('x-sefid-identity');
    $secret = sefid_opt('secret_key');
    if (!$token || !$secret || strpos($token, '.') === false) return 0;
    list($body, $mac) = explode('.', $token, 2);
    if (!hash_equals(hash_hmac('sha256', $body, $secret), (string) $mac)) return 0;
    $json = base64_decode(strtr($body, '-_', '+/') . str_repeat('=', (4 - strlen($body) % 4) % 4));
    $p = json_decode((string) $json, true);
    if (!is_array($p) || empty($p['loggedIn']) || (isset($p['exp']) && intval($p['exp']) < time())) return 0;
    $uid = isset($p['externalId']) ? absint($p['externalId']) : 0;
    if (!$uid && !empty($p['phone'])) {
        $users = get_users(array('meta_key' => 'billing_phone', 'meta_value' => sanitize_text_field($p['phone']), 'number' => 1, 'fields' => 'ID'));
        $uid = $users ? absint($users[0]) : 0;
    }
    return ($uid && get_userdata($uid)) ? $uid : 0;
}

function sefid_store_body($request) {
    $p = json_decode((string) $request->get_body(), true);
    return is_array($p) ? $p : array();
}

function sefid_store_ok($data) {
    return new WP_REST_Response(array_merge(array('ok' => true), $data), 200);
}

function sefid_store_no($reason) {
    return new WP_REST_Response(array('ok' => false, 'error' => $reason), 200);
}

/** One product as Sefid reads it: public data only. */
function sefid_store_product($p) {
    if (!$p || !is_a($p, 'WC_Product')) return null;
    $img = wp_get_attachment_image_url($p->get_image_id(), 'medium');
    $cats = array();
    foreach (wc_get_product_terms($p->get_id(), 'product_cat', array('fields' => 'names')) as $c) $cats[] = $c;
    $vars = array();
    if ($p->is_type('variable')) {
        foreach (array_slice($p->get_children(), 0, 30) as $vid) {
            $v = wc_get_product($vid);
            if (!$v) continue;
            $vars[] = array('id' => (string) $vid, 'attributes' => $v->get_attributes(), 'price' => (float) $v->get_price(), 'inStock' => $v->is_in_stock());
        }
    }
    return array(
        'id'          => (string) $p->get_id(),
        'title'       => $p->get_name(),
        'price'       => $p->get_regular_price() !== '' ? (float) $p->get_regular_price() : (float) $p->get_price(),
        'salePrice'   => $p->get_sale_price() !== '' ? (float) $p->get_sale_price() : null,
        'inStock'     => $p->is_in_stock(),
        'stockQty'    => $p->get_stock_quantity(),
        'url'         => get_permalink($p->get_id()),
        'image'       => $img ? $img : null,
        'categories'  => $cats,
        'description' => wp_trim_words(wp_strip_all_tags($p->get_short_description() ? $p->get_short_description() : $p->get_description()), 60),
        'variations'  => $vars,
    );
}

function sefid_store_products_search($request) {
    if (!function_exists('wc_get_products')) return sefid_store_no('woocommerce_inactive');
    $p = sefid_store_body($request);
    $q = isset($p['q']) ? sanitize_text_field($p['q']) : '';
    if ($q === '') return sefid_store_ok(array('products' => array()));
    $found = wc_get_products(array('status' => 'publish', 'limit' => min(20, max(1, isset($p['limit']) ? absint($p['limit']) : 10)), 's' => $q));
    $out = array();
    foreach ($found as $prod) {
        $row = sefid_store_product($prod);
        if ($row) $out[] = $row;
    }
    return sefid_store_ok(array('products' => $out));
}

function sefid_store_products_get($request) {
    $p = sefid_store_body($request);
    $row = sefid_store_product(wc_get_product(isset($p['id']) ? absint($p['id']) : 0));
    return $row ? sefid_store_ok(array('products' => array($row))) : sefid_store_no('not_found');
}

/** The customer's orders: the same reader as orders/mine, by the vouched-for customer. */
function sefid_store_orders_mine($request) {
    $uid = sefid_store_user($request);
    if (!$uid) return sefid_store_no('not_signed_in');
    $fake = new WP_REST_Request('POST', '/sefid/v1/orders/mine');
    $p = sefid_store_body($request);
    $fake->set_body(wp_json_encode(array('userId' => $uid, 'limit' => isset($p['limit']) ? absint($p['limit']) : 10)));
    return sefid_rest_orders_mine($fake);
}

/* ── The customer's real cart (WooCommerce's persistent cart) ─────────── */

function sefid_cart_key() {
    return '_woocommerce_persistent_cart_' . get_current_blog_id();
}

function sefid_cart_read($uid) {
    $raw = get_user_meta($uid, sefid_cart_key(), true);
    return (is_array($raw) && isset($raw['cart']) && is_array($raw['cart'])) ? $raw['cart'] : array();
}

function sefid_cart_write($uid, $cart) {
    update_user_meta($uid, sefid_cart_key(), array('cart' => $cart));
}

function sefid_cart_view($cart) {
    $lines = array();
    $total = 0;
    foreach ($cart as $key => $line) {
        $pid = !empty($line['variation_id']) ? absint($line['variation_id']) : absint($line['product_id']);
        $prod = wc_get_product($pid);
        if (!$prod) continue;
        $qty = max(1, intval($line['quantity']));
        $price = (float) $prod->get_price();
        $total += $price * $qty;
        $lines[] = array('lineId' => (string) $key, 'productId' => (string) $line['product_id'], 'name' => $prod->get_name(), 'quantity' => $qty, 'price' => $price, 'total' => $price * $qty);
    }
    return array('lines' => $lines, 'total' => $total, 'currency' => get_woocommerce_currency());
}

function sefid_store_cart($request) {
    if (!function_exists('wc_get_product')) return sefid_store_no('woocommerce_inactive');
    $uid = sefid_store_user($request);
    if (!$uid) return sefid_store_no('not_signed_in');
    $op = basename($request->get_route());
    $p = sefid_store_body($request);
    $cart = sefid_cart_read($uid);
    if ($op === 'add') {
        $pid = isset($p['productId']) ? absint(preg_replace('/^live:/', '', (string) $p['productId'])) : 0;
        $vid = isset($p['variationId']) ? absint($p['variationId']) : 0;
        $qty = max(1, min(99, isset($p['quantity']) ? intval($p['quantity']) : 1));
        $prod = wc_get_product($vid ? $vid : $pid);
        if (!$prod || !$prod->is_purchasable() || !$prod->is_in_stock()) return sefid_store_no('not_available');
        $key = md5($pid . '|' . $vid);
        if (isset($cart[$key])) {
            $cart[$key]['quantity'] = intval($cart[$key]['quantity']) + $qty;
        } else {
            $cart[$key] = array('key' => $key, 'product_id' => $pid, 'variation_id' => $vid, 'variation' => array(), 'quantity' => $qty, 'data_hash' => '');
        }
    } elseif ($op === 'remove') {
        $line = isset($p['lineId']) ? sanitize_text_field($p['lineId']) : '';
        if (!isset($cart[$line])) return sefid_store_no('no_such_line');
        unset($cart[$line]);
    } elseif ($op === 'clear') {
        $cart = array();
    }
    if ($op !== 'get') sefid_cart_write($uid, $cart);
    return sefid_store_ok(sefid_cart_view($cart));
}

/* ── Saved addresses: WooCommerce's own billing and shipping, plus extra ones ─ */

function sefid_addresses($uid) {
    $c = new WC_Customer($uid);
    $out = array();
    if ($c->get_shipping_address_1()) $out[] = array('id' => 'shipping', 'label' => __('Shipping address', 'sefid-kit'), 'city' => $c->get_shipping_city(), 'address' => $c->get_shipping_address_1() . ' ' . $c->get_shipping_address_2(), 'postcode' => $c->get_shipping_postcode(), 'recipient' => trim($c->get_shipping_first_name() . ' ' . $c->get_shipping_last_name()));
    if ($c->get_billing_address_1()) $out[] = array('id' => 'billing', 'label' => __('Billing address', 'sefid-kit'), 'city' => $c->get_billing_city(), 'address' => $c->get_billing_address_1() . ' ' . $c->get_billing_address_2(), 'postcode' => $c->get_billing_postcode(), 'recipient' => trim($c->get_billing_first_name() . ' ' . $c->get_billing_last_name()));
    $extra = get_user_meta($uid, '_sefid_addresses', true);
    foreach (is_array($extra) ? $extra : array() as $a) $out[] = $a;
    return $out;
}

function sefid_store_addresses($request) {
    if (!class_exists('WC_Customer')) return sefid_store_no('woocommerce_inactive');
    $uid = sefid_store_user($request);
    if (!$uid) return sefid_store_no('not_signed_in');
    if (basename($request->get_route()) === 'add') {
        $p = sefid_store_body($request);
        $f = isset($p['fields']) && is_array($p['fields']) ? $p['fields'] : array();
        $a = array(
            'id'        => 'a' . substr(md5(wp_json_encode($f) . microtime()), 0, 8),
            'label'     => sanitize_text_field(isset($f['label']) ? $f['label'] : __('Address from the chat', 'sefid-kit')),
            'city'      => sanitize_text_field(isset($f['city']) ? $f['city'] : ''),
            'address'   => sanitize_text_field(isset($f['address']) ? $f['address'] : ''),
            'postcode'  => sanitize_text_field(isset($f['postcode']) ? $f['postcode'] : ''),
            'recipient' => sanitize_text_field(isset($f['recipient']) ? $f['recipient'] : ''),
            'phone'     => sanitize_text_field(isset($f['phone']) ? $f['phone'] : ''),
        );
        if ($a['address'] === '' || $a['city'] === '') return sefid_store_no('address_incomplete');
        $extra = get_user_meta($uid, '_sefid_addresses', true);
        $extra = is_array($extra) ? $extra : array();
        $extra[] = $a;
        update_user_meta($uid, '_sefid_addresses', array_slice($extra, -10));
    }
    return sefid_store_ok(array('addresses' => sefid_addresses($uid)));
}

/* ── Checkout: an order from the cart, its pay page, and the result back to the chat ─ */

function sefid_store_checkout($request) {
    if (!function_exists('wc_create_order')) return sefid_store_no('woocommerce_inactive');
    $uid = sefid_store_user($request);
    if (!$uid) return sefid_store_no('not_signed_in');
    $p = sefid_store_body($request);
    $cart = sefid_cart_read($uid);
    if (!$cart) return sefid_store_no('cart_empty');
    $addr = null;
    foreach (sefid_addresses($uid) as $a) if ((string) $a['id'] === (string) (isset($p['addressId']) ? $p['addressId'] : '')) $addr = $a;
    if (!$addr) return sefid_store_no('no_address');
    $order = wc_create_order(array('customer_id' => $uid, 'created_via' => 'sefid'));
    if (is_wp_error($order)) return sefid_store_no('order_failed');
    foreach ($cart as $line) {
        $prod = wc_get_product(!empty($line['variation_id']) ? $line['variation_id'] : $line['product_id']);
        if ($prod) $order->add_product($prod, max(1, intval($line['quantity'])));
    }
    $names = explode(' ', (string) $addr['recipient'], 2);
    $order->set_address(array('first_name' => $names[0], 'last_name' => isset($names[1]) ? $names[1] : '', 'address_1' => $addr['address'], 'city' => $addr['city'], 'postcode' => $addr['postcode']), 'shipping');
    $order->set_address(array('first_name' => $names[0], 'last_name' => isset($names[1]) ? $names[1] : '', 'address_1' => $addr['address'], 'city' => $addr['city'], 'postcode' => $addr['postcode']), 'billing');
    if (!empty($p['coupon'])) {
        $applied = $order->apply_coupon(sanitize_text_field($p['coupon']));
        if (is_wp_error($applied)) {
            $order->delete(true);
            return sefid_store_no('coupon_refused');
        }
    }
    if (!empty($p['options']) && is_array($p['options'])) {
        $note = array();
        foreach ($p['options'] as $k => $v) $note[] = sanitize_text_field($k) . ': ' . sanitize_text_field(is_scalar($v) ? (string) $v : wp_json_encode($v));
        if ($note) $order->set_customer_note(implode("\n", $note));
    }
    if (!empty($p['sefidConversation'])) $order->update_meta_data('_sefid_conversation', sanitize_text_field($p['sefidConversation']));
    $order->calculate_totals();
    $order->update_status('pending');
    $order->save();
    sefid_cart_write($uid, array());
    return sefid_store_ok(array('orderId' => (string) $order->get_order_number(), 'total' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'payUrl' => $order->get_checkout_payment_url()));
}

/** A checkout Sefid started was paid or failed: the result goes back to that chat. */
function sefid_store_payment_result($order_id, $from, $to, $order = null) {
    $order = $order ? $order : wc_get_order($order_id);
    if (!$order) return;
    $conv = (string) $order->get_meta('_sefid_conversation');
    if ($conv === '') return;
    if (in_array($to, array('processing', 'completed'), true) && !$order->get_meta('_sefid_paid_told')) {
        $order->update_meta_data('_sefid_paid_told', '1');
        $order->save_meta_data();
        sefid_send('POST', '/api/ingest/payment-result', array('orderId' => (string) $order->get_order_number(), 'status' => 'paid', 'sefidConversation' => $conv), false);
    } elseif ($to === 'failed') {
        sefid_send('POST', '/api/ingest/payment-result', array('orderId' => (string) $order->get_order_number(), 'status' => 'failed', 'sefidConversation' => $conv, 'link' => $order->get_checkout_payment_url()), false);
    }
}
add_action('woocommerce_order_status_changed', 'sefid_store_payment_result', 30, 4);

/** Every store job and the function that does it: read by the REST routes below and by the abilities (includes/abilities.php). */
function sefid_store_routes() {
    return array(
        '/store/products/search' => 'sefid_store_products_search',
        '/store/products/get'    => 'sefid_store_products_get',
        '/store/orders/mine'     => 'sefid_store_orders_mine',
        '/store/cart/get'        => 'sefid_store_cart',
        '/store/cart/add'        => 'sefid_store_cart',
        '/store/cart/remove'     => 'sefid_store_cart',
        '/store/cart/clear'      => 'sefid_store_cart',
        '/store/addresses/list'  => 'sefid_store_addresses',
        '/store/addresses/add'   => 'sefid_store_addresses',
        '/store/checkout/start'  => 'sefid_store_checkout',
    );
}

add_action('rest_api_init', function () {
    foreach (sefid_store_routes() as $route => $cb) {
        register_rest_route('sefid/v1', $route, array('methods' => 'POST', 'callback' => $cb, 'permission_callback' => 'sefid_store_verify'));
    }
});

/* ── Sync mode: a stock or price change reaches Sefid at once, like any product save ─ */

function sefid_push_stock($product) {
    $id = is_object($product) ? $product->get_id() : absint($product);
    $prod = wc_get_product($id);
    if ($prod && $prod->get_parent_id()) $id = $prod->get_parent_id();
    sefid_push_product($id);
}
add_action('woocommerce_product_set_stock', 'sefid_push_stock', 20, 1);
add_action('woocommerce_variation_set_stock', 'sefid_push_stock', 20, 1);
add_action('woocommerce_product_set_stock_status', 'sefid_push_stock', 20, 1);
add_action('woocommerce_update_product_variation', 'sefid_push_stock', 20, 1);
