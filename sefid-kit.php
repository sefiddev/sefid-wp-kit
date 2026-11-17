<?php
/**
 * Plugin Name: Sefid WP Kit
 * Plugin URI: https://sefid.dev
 * Description: Add the Sefid AI assistant to your site: an on-page chat widget, product sync for in-chat search, and order tracking for WooCommerce stores.
 * Version: 3.3.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: sefid.dev
 * Author URI: https://sefid.dev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sefid-kit
 * Domain Path: /languages
 *
 * Design rules this file keeps to:
 *  - Security first. Every call is HMAC-signed; the secret never leaves the server.
 *  - Never slow the store down. Outbound calls are fire-and-forget behind a circuit breaker.
 *  - Leave the database clean. One option row, expiring transients, nothing else.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Optional per-site config baked in by Sefid, so a downloaded build installs itself.
if (file_exists(__DIR__ . '/sefid-config.php')) {
    require_once __DIR__ . '/sefid-config.php';
}
// every Sefid API is offered on api.sefid.dev (3.2.1); a build from the panel bakes the same in sefid-config.php
if (!defined('SEFID_DEFAULT_API_BASE'))    define('SEFID_DEFAULT_API_BASE', 'https://api.sefid.dev');
if (!defined('SEFID_DEFAULT_PUBLIC_KEY'))  define('SEFID_DEFAULT_PUBLIC_KEY', '');
if (!defined('SEFID_DEFAULT_SECRET_KEY'))  define('SEFID_DEFAULT_SECRET_KEY', '');
if (!defined('SEFID_DEFAULT_WIDGET_ID'))   define('SEFID_DEFAULT_WIDGET_ID', '');
if (!defined('SEFID_DEFAULT_LOCK_DOMAIN')) define('SEFID_DEFAULT_LOCK_DOMAIN', '');

// The live store (products, the customer's orders, cart, addresses, checkout), asked by Sefid with a signature.
require_once __DIR__ . '/includes/store.php';
// The abilities (WordPress 6.9+): on for every site since 3.3.0 (2026-11-13), after a real WordPress 7.1.3 registered
// all ten, served them over REST and refused a visitor and a signed-out customer as it should. A site that does not
// want them writes `define('SEFID_ABILITIES', false);` in wp-config.php. On WordPress before 6.9 nothing happens.
if (!defined('SEFID_ABILITIES') || SEFID_ABILITIES) {
    require_once __DIR__ . '/includes/abilities.php';
}

/**
 * One option row holds everything: settings and sync state.
 *
 * Earlier versions used three separate rows. Consolidating keeps the footprint to a
 * single, obvious row and means uninstall cannot miss one.
 */
define('SEFID_OPT', 'sefid_kit');
define('SEFID_OPT_LEGACY', 'sefid_wp_kit_options');

define('SEFID_SYNC_HOOK', 'sefid_kit_sync_batch');
define('SEFID_RETRY_HOOK', 'sefid_kit_sync_retry');

// Transients: caches only, all with an expiry.
define('SEFID_OFFLINE_T', 'sefid_offline');    // circuit breaker
define('SEFID_CONN_T', 'sefid_conn_cache');    // connection check result
define('SEFID_CONN_ERR_T', 'sefid_conn_err');  // last failure reason
define('SEFID_UPDATE_T', 'sefid_update_info'); // latest-version cache

define('SEFID_VERSION', '3.3.0');

/* ── Settings ─────────────────────────────────────────────────────────── */

function sefid_defaults() {
    return array(
        'public_key'     => SEFID_DEFAULT_PUBLIC_KEY,
        'secret_key'     => SEFID_DEFAULT_SECRET_KEY,
        'widget_id'      => SEFID_DEFAULT_WIDGET_ID,
        'api_base'       => SEFID_DEFAULT_API_BASE,
        'lock_domain'    => SEFID_DEFAULT_LOCK_DOMAIN,
        'widget_enabled' => '1',
        'sync_enabled'   => '1',
        // 'sync' keeps the public product data in Sefid (recommended); 'live' lets Sefid ask this shop on each search
        'product_source' => 'sync',
        'order_tracking' => '0',
        'notify_enabled' => '1',
        'notify_notes'   => '1',
        // Sync state lives here too, so the plugin owns exactly one row.
        'sync_page'      => 0,     // 0 = no catalogue sync in progress
        'initial_done'   => '0',
        'retry'          => array(), // product_id => attempts, for pushes we know failed
        'last_sync'      => 0,       // unix time of the last successful push
    );
}

/** Read options once per request. The row is non-autoloaded because it holds the secret. */
function sefid_opts() {
    if (isset($GLOBALS['sefid_opts_cache'])) return $GLOBALS['sefid_opts_cache'];
    $o = get_option(SEFID_OPT, null);
    if ($o === null) {
        // One-time migration from the pre-3.0 layout.
        $legacy = get_option(SEFID_OPT_LEGACY, array());
        $o = is_array($legacy) ? $legacy : array();
        if ($o) {
            $o['sync_page']    = (int) get_option('sefid_sync_page', 0);
            $o['initial_done'] = get_option('sefid_initial_synced') ? '1' : '0';
            update_option(SEFID_OPT, wp_parse_args($o, sefid_defaults()), false);
            delete_option(SEFID_OPT_LEGACY);
            delete_option('sefid_sync_page');
            delete_option('sefid_initial_synced');
        }
    }
    $GLOBALS['sefid_opts_cache'] = wp_parse_args(is_array($o) ? $o : array(), sefid_defaults());
    return $GLOBALS['sefid_opts_cache'];
}

function sefid_opt($key) {
    $o = sefid_opts();
    return isset($o[$key]) ? $o[$key] : '';
}

/** Write a few keys back without disturbing the rest, keeping the cache truthful. */
function sefid_opt_set($changes) {
    $o = sefid_opts();
    foreach ($changes as $k => $v) $o[$k] = $v;
    update_option(SEFID_OPT, $o, false);
    $GLOBALS['sefid_opts_cache'] = $o;
    return $o;
}

/** True when this install is allowed to run on the current domain. */
function sefid_domain_ok() {
    $lock = strtolower(trim((string) sefid_opt('lock_domain')));
    if ($lock === '') return true;
    $lock = preg_replace('/^www\./', '', $lock);
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === '') return true;
    return ($host === $lock) || (strlen($host) > strlen($lock) && substr($host, -(strlen($lock) + 1)) === '.' . $lock);
}

/** Keys present, and running on an allowed domain. */
function sefid_is_configured() {
    return sefid_opt('api_base') && sefid_opt('public_key') && sefid_opt('secret_key') && sefid_domain_ok();
}

/* ── Request signing (mirrors Sefid's signing.ts) ──────────────────────── */

function sefid_b64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Signed identity token: base64url(json) . hmac_hex(secret, body). */
function sefid_build_identity_token($payload) {
    $secret = sefid_opt('secret_key');
    if (!$secret) return '';
    $body = sefid_b64url(wp_json_encode($payload));
    return $body . '.' . hash_hmac('sha256', $body, $secret);
}

/** Canonical string: METHOD\npath\nts\nnonce\nsha256_hex(body). */
function sefid_sign_request($method, $path, $timestamp, $nonce, $body) {
    $secret = sefid_opt('secret_key');
    $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    return hash_hmac('sha256', $canonical, $secret);
}

/**
 * Signed server-to-server call.
 *
 * Non-blocking by default: the store never waits for Sefid. The circuit breaker skips
 * calls entirely while Sefid is known to be down, so an outage costs nothing.
 *
 * @return array|WP_Error|false  false when skipped (not configured, or breaker open).
 */
function sefid_send($method, $path, $array_body = null, $blocking = false) {
    if (!sefid_is_configured()) return false;
    if (!$blocking && get_transient(SEFID_OFFLINE_T)) return false;

    $base   = rtrim(sefid_opt('api_base'), '/');
    $public = sefid_opt('public_key');
    $body   = ($array_body === null) ? '' : wp_json_encode($array_body);
    $ts     = (string) round(microtime(true) * 1000);
    $nonce  = wp_generate_uuid4();
    $sig    = sefid_sign_request($method, $path, $ts, $nonce, $body);

    $res = wp_remote_request($base . $path, array(
        'method'      => strtoupper($method),
        'timeout'     => $blocking ? 6 : 3,
        'blocking'    => (bool) $blocking,
        'redirection' => 0,
        'sslverify'   => true,
        'headers'     => array(
            'Content-Type'      => 'application/json',
            'X-Sefid-Key'       => $public,
            'X-Sefid-Timestamp' => $ts,
            'X-Sefid-Nonce'     => $nonce,
            'X-Sefid-Signature' => $sig,
        ),
        'body'        => $body,
    ));

    // Only blocking calls know the real outcome, so only they move the breaker.
    if ($blocking) {
        if (is_wp_error($res)) {
            set_transient(SEFID_OFFLINE_T, 1, 5 * MINUTE_IN_SECONDS);
        } else {
            delete_transient(SEFID_OFFLINE_T);
        }
    }
    return $res;
}

/** True when the call clearly did not reach Sefid. */
function sefid_send_failed($res) {
    return ($res === false) || is_wp_error($res);
}

/**
 * True when a blocking call did not land: it never reached Sefid, or Sefid refused it for a reason that clears up by
 * itself or by the Sefid team: not allowed yet (401/403, e.g. a new store whose API access is still off), too many
 * requests (429), or a server error (5xx). The work is then retried instead of counted as sent. Before 3.0.1 a 403
 * counted as sent: the first catalogue sync of a new store "finished" with nothing in Sefid and never ran again.
 */
function sefid_send_refused($res) {
    if (sefid_send_failed($res)) return true;
    $code = (int) wp_remote_retrieve_response_code($res);
    return $code === 401 || $code === 403 || $code === 429 || $code >= 500;
}

/* ── Chat widget ──────────────────────────────────────────────────────── */

/** Signed-in customer's mobile number, normalised. */
function sefid_current_user_phone($user) {
    $phone = get_user_meta($user->ID, 'billing_phone', true);
    if (!$phone) $phone = get_user_meta($user->ID, 'phone', true);
    if (!$phone) return '';
    $p = preg_replace('/[^0-9+]/', '', $phone);
    $p = preg_replace('/^(\+?98|0098)/', '0', $p);
    if (strlen($p) === 10 && substr($p, 0, 1) === '9') $p = '0' . $p;
    return $p;
}

/**
 * The signed-in visitor's proof for the chat, valid 10 minutes (Sefid ADR-0064, BUG-0033): until 3.0.2 it was valid
 * 30 days, so a customer who signed out of the shop stayed signed in to the chat. The widget asks for a fresh one
 * before every message (sefid_rest_identity), so signing out here signs out of the chat at once.
 */
define('SEFID_IDENTITY_TTL', 10 * 60);
function sefid_identity_for($user) {
    $now = time();
    return sefid_build_identity_token(array(
        'loggedIn'   => true,
        'externalId' => (string) $user->ID,
        'name'       => $user->display_name,
        'phone'      => sefid_current_user_phone($user),
        'email'      => $user->user_email,
        'iat'        => $now,
        'exp'        => $now + SEFID_IDENTITY_TTL,
    ));
}

/** One async script tag, the signed-in visitor's 10-minute proof, and where the widget asks for a fresh one. */
function sefid_inject_widget() {
    if (sefid_opt('widget_enabled') !== '1' || !sefid_is_configured()) return;
    $public = sefid_opt('public_key');
    $base   = rtrim(sefid_opt('api_base'), '/');

    if (is_user_logged_in()) {
        $token = sefid_identity_for(wp_get_current_user());
        if ($token) {
            echo '<script>window.__SEFID_IDENTITY__=' . wp_json_encode($token) . ';</script>' . "\n";
        }
    }
    // the shop owns sign-in: the widget asks this address (same origin, WordPress's own cookie and REST nonce)
    echo '<script>window.__SEFID_IDENTITY_URL__=' . wp_json_encode(esc_url_raw(rest_url('sefid/v1/identity'))) . ';window.__SEFID_IDENTITY_NONCE__=' . wp_json_encode(wp_create_nonce('wp_rest')) . ';window.__SEFID_LOGIN_URL__=' . wp_json_encode(esc_url_raw(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url())) . ';</script>' . "\n";

    $embed_id = sefid_opt('widget_id');
    if (!$embed_id) $embed_id = $public;
    printf(
        '<script src="%s/api/widget/loader.js" data-sefid-id="%s" async></script>' . "\n",
        esc_url($base),
        esc_attr($embed_id)
    );
}
add_action('wp_footer', 'sefid_inject_widget', 20);

/* ── Notices shown inside the widget ──────────────────────────────────── */

/**
 * Hand a notice to the widget.
 *
 * The loader exposes window.SefidNotify; if it has not loaded yet the notice waits in
 * a queue the loader drains, so script order does not matter.
 */
function sefid_notify_js($notice) {
    $json = wp_json_encode($notice);
    if (!$json) return;
    echo '<script>(function(n){'
        . 'if(window.SefidNotify){window.SefidNotify(n);}'
        . 'else{window.SefidNotifyQueue=window.SefidNotifyQueue||[];window.SefidNotifyQueue.push(n);}'
        . '})(' . $json . ');</script>' . "\n";
}

/** Thank-you page: tell the customer where the order stands, inside the chat. */
function sefid_notify_thankyou($order_id) {
    if (sefid_opt('widget_enabled') !== '1' || !sefid_is_configured()) return;
    if (!$order_id || !function_exists('wc_get_order')) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    $no = $order->get_order_number();
    $status = $order->get_status();

    // The notice id carries the order number, so a page refresh cannot repeat it.
    if ($order->is_paid() || in_array($status, array('processing', 'completed'), true)) {
        sefid_notify_js(array(
            'id'        => 'payment-succeeded:' . $no,
            'kind'      => 'payment-succeeded',
            /* translators: %s: order number */
            'text'      => sprintf(__('Payment for order %s went through. Your order is being prepared — ask me anything here.', 'sefid-kit'), $no),
            'important' => true,
        ));
        return;
    }
    if (in_array($status, array('failed', 'cancelled'), true)) {
        sefid_notify_js(array(
            'id'        => 'payment-failed:' . $no,
            'kind'      => 'payment-failed',
            /* translators: %s: order number */
            'text'      => sprintf(__('Payment for order %s did not go through, and nothing was charged. Shall I help you try again?', 'sefid-kit'), $no),
            'important' => true,
        ));
        return;
    }
    sefid_notify_js(array(
        'id'        => 'order-placed:' . $no,
        'kind'      => 'order-placed',
        /* translators: %s: order number */
        'text'      => sprintf(__('Order %s is placed and waiting for payment. If you get stuck, just ask here.', 'sefid-kit'), $no),
        'important' => true,
    ));
}
add_action('woocommerce_thankyou', 'sefid_notify_thankyou', 20, 1);

/** Status change while the customer is on the site — shown only to that customer. */
function sefid_notify_order_status($order_id, $from, $to, $order = null) {
    if (sefid_opt('widget_enabled') !== '1' || !sefid_is_configured()) return;
    if (!is_user_logged_in() || !$order) return;
    if ((int) $order->get_customer_id() !== get_current_user_id()) return;

    $no = $order->get_order_number();
    $map = array(
        /* translators: %s: order number */
        'processing' => __('Order %s is paid and being prepared.', 'sefid-kit'),
        /* translators: %s: order number */
        'completed'  => __('Order %s is complete and on its way to you.', 'sefid-kit'),
        /* translators: %s: order number */
        'failed'     => __('Payment for order %s did not go through, and nothing was charged.', 'sefid-kit'),
        /* translators: %s: order number */
        'cancelled'  => __('Order %s was cancelled.', 'sefid-kit'),
        /* translators: %s: order number */
        'refunded'   => __('Order %s has been refunded.', 'sefid-kit'),
    );
    if (!isset($map[$to])) return;
    sefid_notify_js(array(
        'id'        => 'order-status:' . $no . ':' . $to,
        'kind'      => $to === 'failed' ? 'payment-failed' : 'order-placed',
        'text'      => sprintf($map[$to], $no),
        'important' => true,
    ));
}
add_action('woocommerce_order_status_changed', 'sefid_notify_order_status', 25, 4);

/* ── Turning a WooCommerce product into a Sefid record ─────────────────── */

/**
 * Build the raw record Sefid maps on its own side.
 *
 * Protected meta (leading underscore) and anything that looks like a secret or a cost
 * price is never sent — a chat assistant has no business knowing either.
 */
function sefid_collect_raw_product($product) {
    if (!$product || !is_a($product, 'WC_Product')) return null;
    $id = $product->get_id();

    $rec = array(
        'product_id'        => (string) $id,
        'name'              => $product->get_name(),
        'regular_price'     => $product->get_regular_price(),
        'sale_price'        => $product->get_sale_price(),
        'sku'               => $product->get_sku(),
        'stock_status'      => $product->get_stock_status(),
        'stock_qty'         => $product->get_stock_quantity(),
        'short_description' => wp_strip_all_tags($product->get_short_description()),
        'description'       => wp_strip_all_tags($product->get_description()),
        'weight'            => $product->get_weight(),
        'product_url'       => get_permalink($id),
        'shippable'         => (!($product->is_virtual() || $product->is_downloadable())) ? '1' : '0',
        'currency_code'     => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
    );

    $img_id = $product->get_image_id();
    if ($img_id) {
        $url = wp_get_attachment_image_url($img_id, 'medium');
        if ($url) $rec['image_url'] = $url;
    }

    $cats = array();
    foreach (wp_get_post_terms($id, 'product_cat') as $t) {
        if (is_object($t)) $cats[] = $t->name;
    }
    if ($cats) $rec['categories'] = implode(', ', $cats);

    foreach ($product->get_attributes() as $attr) {
        if (!is_object($attr)) continue;
        $name = $attr->get_name();
        $label = function_exists('wc_attribute_label') ? wc_attribute_label($name) : $name;
        $opts = array();
        if (method_exists($attr, 'is_taxonomy') && $attr->is_taxonomy()) {
            foreach (wp_get_post_terms($id, $name) as $t) if (is_object($t)) $opts[] = $t->name;
        } else {
            $opts = $attr->get_options();
        }
        if ($label && $opts) {
            $rec['attr_' . sanitize_key($label)] = implode(', ', array_map('strval', (array) $opts));
        }
    }

    // Public custom meta, capped and filtered. Sensitive-looking keys are skipped outright.
    $metas = get_post_meta($id);
    if (is_array($metas)) {
        $n = 0;
        foreach ($metas as $key => $vals) {
            if ($n >= 30) break;
            if (strpos($key, '_') === 0) continue;
            if (isset($rec[$key])) continue;
            if (!is_array($vals) || !isset($vals[0]) || !is_scalar($vals[0])) continue;
            $v = (string) $vals[0];
            if ($v === '' || strlen($v) > 500) continue;
            if (preg_match('/(pass|secret|token|api[_-]?key|hash|cvv|iban|card|account|cost|buy|purchase|wholesale|profit|margin)/i', $key)) continue;
            $rec[$key] = $v;
            $n++;
        }
    }

    if ($product->is_type('variable')) {
        $variations = array();
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if (!$v) continue;
            $attrs = array();
            foreach ($v->get_variation_attributes() as $k => $val) {
                $attrs[str_replace('attribute_', '', $k)] = $val;
            }
            $variations[] = array(
                'attributes' => $attrs,
                'price'      => $v->get_price() !== '' ? (int) round((float) $v->get_price()) : null,
                'inStock'    => $v->is_in_stock(),
                'stockQty'   => $v->get_stock_quantity(),
                'sku'        => $v->get_sku(),
                'externalId' => (string) $vid,
            );
        }
        if ($variations) $rec['variations'] = $variations;
    }

    return $rec;
}

/* ── Product sync ─────────────────────────────────────────────────────── */

/**
 * Retry queue.
 *
 * A live push is fire-and-forget, so it cannot report success — but it can report the
 * two failures we actually know about: the breaker being open, and an immediate
 * transport error. Those products are parked here and retried on a schedule instead of
 * being silently lost, which is the difference between a catalogue that drifts out of
 * date and one that heals itself.
 *
 * The queue is a plain map of product id => attempts, capped so a long outage cannot
 * grow the option row without bound.
 */
define('SEFID_RETRY_MAX', 200);
define('SEFID_RETRY_ATTEMPTS', 5);

function sefid_retry_add($product_id) {
    $retry = sefid_opt('retry');
    if (!is_array($retry)) $retry = array();
    $id = (string) (int) $product_id;
    if (!isset($retry[$id]) && count($retry) >= SEFID_RETRY_MAX) return;
    $retry[$id] = isset($retry[$id]) ? ((int) $retry[$id]) : 0;
    sefid_opt_set(array('retry' => $retry));
    sefid_retry_schedule();
}

function sefid_retry_drop($product_id) {
    $retry = sefid_opt('retry');
    if (!is_array($retry)) return;
    unset($retry[(string) (int) $product_id]);
    sefid_opt_set(array('retry' => $retry));
}

/** Back off as attempts pile up: 2, 4, 8, 16, 32 minutes. */
function sefid_retry_schedule($attempts = 0) {
    if (wp_next_scheduled(SEFID_RETRY_HOOK)) return;
    $delay = min(32, pow(2, max(1, (int) $attempts + 1))) * MINUTE_IN_SECONDS;
    wp_schedule_single_event(time() + $delay, SEFID_RETRY_HOOK);
}

/**
 * Work through the retry queue.
 *
 * Blocking on purpose: this runs on cron, nobody is waiting, and we need the real
 * outcome to decide whether the product is done or should wait for another round.
 */
function sefid_run_retry() {
    if (sefid_opt('sync_enabled') !== '1' || !sefid_is_configured()) return;
    $retry = sefid_opt('retry');
    if (!is_array($retry) || !$retry) return;

    $slice = array_slice($retry, 0, 10, true);
    $worst = 0;
    foreach ($slice as $id => $attempts) {
        $attempts = (int) $attempts;
        $pid = (int) $id;
        $status = get_post_status($pid);

        if ($status !== 'publish') {
            // Gone or unpublished while it waited: the delete is the correct final state.
            $res = sefid_send('DELETE', '/api/ingest/products/' . rawurlencode((string) $pid), null, true);
        } else {
            $rec = sefid_collect_raw_product(wc_get_product($pid));
            $res = $rec
                ? sefid_send('POST', '/api/ingest/products/raw', array('entity' => 'product', 'products' => array($rec)), true)
                : false;
        }

        if (!sefid_send_refused($res)) {
            unset($retry[$id]);
            continue;
        }
        $attempts++;
        if ($attempts >= SEFID_RETRY_ATTEMPTS) {
            // Give up on this one; the next catalogue sync will pick it up anyway.
            unset($retry[$id]);
            continue;
        }
        $retry[$id] = $attempts;
        if ($attempts > $worst) $worst = $attempts;
    }

    sefid_opt_set(array('retry' => $retry, 'last_sync' => time()));
    if ($retry) sefid_retry_schedule($worst);
}
add_action(SEFID_RETRY_HOOK, 'sefid_run_retry');

/**
 * Push one product the moment it changes.
 *
 * An unpublished product is deleted on Sefid's side rather than updated, so the
 * assistant can never offer something a customer cannot buy.
 */
function sefid_push_product($product_id) {
    if (sefid_opt('sync_enabled') !== '1' || !sefid_is_configured()) return;
    if (wp_is_post_revision($product_id) || wp_is_post_autosave($product_id)) return;

    $status = get_post_status($product_id);
    if ($status !== 'publish') {
        $res = sefid_send('DELETE', '/api/ingest/products/' . rawurlencode((string) $product_id), null, false);
        if (sefid_send_failed($res)) sefid_retry_add($product_id);
        return;
    }
    $rec = sefid_collect_raw_product(wc_get_product($product_id));
    if (!$rec) return;
    $res = sefid_send('POST', '/api/ingest/products/raw', array('entity' => 'product', 'products' => array($rec)), false);
    if (sefid_send_failed($res)) {
        sefid_retry_add($product_id);
    } else {
        sefid_opt_set(array('last_sync' => time()));
    }
}
add_action('woocommerce_update_product', 'sefid_push_product', 20, 1);
add_action('woocommerce_new_product', 'sefid_push_product', 20, 1);

/** Deleting or trashing a product removes it from Sefid too. */
function sefid_delete_product($post_id) {
    if (sefid_opt('sync_enabled') !== '1' || !sefid_is_configured()) return;
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'product') return;
    $res = sefid_send('DELETE', '/api/ingest/products/' . rawurlencode((string) $post_id), null, false);
    if (sefid_send_failed($res)) sefid_retry_add($post_id);
}
add_action('before_delete_post', 'sefid_delete_product', 20, 1);
add_action('wp_trash_post', 'sefid_delete_product', 20, 1);

/* ── First catalogue sync (background, wp-cron) ───────────────────────── */

function sefid_start_initial_sync() {
    sefid_opt_set(array('sync_page' => 1));
    if (!wp_next_scheduled(SEFID_SYNC_HOOK)) {
        wp_schedule_single_event(time() + 5, SEFID_SYNC_HOOK);
    }
}

/** Runs itself once the connection is live — no button to find, nothing to remember. */
function sefid_maybe_autosync() {
    if (sefid_opt('sync_enabled') !== '1' || !sefid_is_configured()) return;
    if (sefid_opt('initial_done') === '1') return;
    sefid_opt_set(array('initial_done' => '1'));
    sefid_start_initial_sync();
}
add_action('admin_init', 'sefid_maybe_autosync');
register_activation_hook(__FILE__, 'sefid_maybe_autosync');

/**
 * One page of the catalogue per run.
 *
 * Blocking, because a failed batch that we do not notice is a catalogue that quietly
 * ends up incomplete; on failure the page is retried rather than skipped.
 */
function sefid_run_sync_batch() {
    $page = (int) sefid_opt('sync_page');
    if ($page < 1) return;

    $q = new WP_Query(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'paged'          => $page,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));
    if (empty($q->posts)) {
        sefid_opt_set(array('sync_page' => 0, 'last_sync' => time()));
        return;
    }

    $batch = array();
    foreach ($q->posts as $pid) {
        $r = sefid_collect_raw_product(wc_get_product($pid));
        if ($r) $batch[] = $r;
    }

    $failed = false;
    if (!empty($batch)) {
        $res = sefid_send('POST', '/api/ingest/products/raw', array('entity' => 'product', 'products' => $batch), true);
        $failed = sefid_send_refused($res);
    }

    if ($failed) {
        // Stay on this page and come back later; a gap in the middle is worse than a delay.
        wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, SEFID_SYNC_HOOK);
        return;
    }

    sefid_opt_set(array('sync_page' => $page + 1, 'last_sync' => time()));
    wp_schedule_single_event(time() + 10, SEFID_SYNC_HOOK);
}
add_action(SEFID_SYNC_HOOK, 'sefid_run_sync_batch');

/** Keep baked-in keys after an auto-update removes the config file. */
function sefid_persist_baked_config() {
    if (!SEFID_DEFAULT_PUBLIC_KEY || !SEFID_DEFAULT_SECRET_KEY) return;
    if (sefid_opt('public_key')) return;
    sefid_opt_set(array(
        'public_key'  => SEFID_DEFAULT_PUBLIC_KEY,
        'secret_key'  => SEFID_DEFAULT_SECRET_KEY,
        'widget_id'   => SEFID_DEFAULT_WIDGET_ID,
        'api_base'    => SEFID_DEFAULT_API_BASE,
        'lock_domain' => SEFID_DEFAULT_LOCK_DOMAIN,
    ));
}
add_action('admin_init', 'sefid_persist_baked_config');
register_activation_hook(__FILE__, 'sefid_persist_baked_config');

/** Warn clearly when a domain-locked build is installed somewhere else. */
function sefid_domain_notice() {
    if (!current_user_can('manage_options')) return;
    $lock = strtolower(trim((string) sefid_opt('lock_domain')));
    if ($lock === '' || sefid_domain_ok()) return;
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('Sefid:', 'sefid-kit') . '</strong> '
        . esc_html(sprintf(
            /* translators: %s: the domain this build is licensed for */
            __('This build is licensed for %s and will not run here. Download the build for this domain from your Sefid panel.', 'sefid-kit'),
            $lock
        )) . '</p></div>';
}
add_action('admin_notices', 'sefid_domain_notice');

/* ── Order status mirroring ───────────────────────────────────────────── */

function sefid_push_order_status($order_id, $from = '', $to = '', $order = null) {
    if (!sefid_feature_on('orderTracking') || !sefid_is_configured()) return;
    if (!$order) $order = wc_get_order($order_id);
    if (!$order) return;
    $status = $to ?: $order->get_status();
    $items = array();
    foreach ($order->get_items() as $item) {
        $items[] = array(
            'title'     => $item->get_name(),
            'qty'       => (int) $item->get_quantity(),
            'unitPrice' => (int) round((float) $order->get_item_total($item, false, false)),
        );
    }
    $phone = $order->get_billing_phone();
    $phone = $phone ? preg_replace('/[^0-9]/', '', preg_replace('/^(\+?98|0098)/', '0', $phone)) : '';
    sefid_send('POST', '/api/ingest/order-status', array(
        'externalOrderId' => (string) $order_id,
        'phone'           => $phone,
        'status'          => $status,
        'trackingCode'    => $order->get_meta('_tracking_code') ?: null,
        'total'           => (int) round((float) $order->get_total()),
        'items'           => $items,
        // WooCommerce's own answer (wc_get_is_paid_statuses), so a store's custom "sent" status still reads paid in Sefid
        'paid'            => $order->is_paid() || in_array($status, array('processing', 'completed'), true),
    ), false);
}
add_action('woocommerce_order_status_changed', 'sefid_push_order_status', 20, 4);

/* ── Store-to-customer notices ────────────────────────────────────────── */

/**
 * Send one notice to a customer, shown in the on-page chat.
 *
 * @param mixed  $who   user id, WP_User, or a phone/email directly.
 * @param string $title the text the customer sees.
 * @param string $link  optional follow-up link.
 */
function sefid_notify_customer($who, $title, $link = '') {
    if (sefid_opt('notify_enabled') !== '1' || !sefid_is_configured()) return false;
    $title = trim(wp_strip_all_tags((string) $title));
    if ($title === '') return false;

    $phone = '';
    $email = '';
    if (is_numeric($who) || $who instanceof WP_User) {
        $user = $who instanceof WP_User ? $who : get_user_by('id', (int) $who);
        if (!$user) return false;
        $phone = sefid_current_user_phone($user);
        $email = $user->user_email;
    } elseif (is_string($who) && strpos($who, '@') !== false) {
        $email = $who;
    } elseif (is_string($who)) {
        $phone = preg_replace('/[^0-9]/', '', preg_replace('/^(\+?98|0098)/', '0', $who));
    }
    if (!$phone && !$email) return false;

    $body = array('title' => mb_substr($title, 0, 200));
    if ($phone) $body['phone'] = $phone;
    if ($email) $body['email'] = $email;
    if ($link)  $body['link']  = esc_url_raw($link);
    sefid_send('POST', '/api/ingest/notify', $body, false);
    return true;
}

/** Public hook for themes and other plugins. */
add_action('sefid_notify', 'sefid_notify_customer', 10, 3);

/** A WooCommerce "note to customer" is the store deliberately talking to one person. */
function sefid_notify_on_customer_note($args) {
    if (sefid_opt('notify_notes') !== '1') return;
    $order = isset($args['order']) ? $args['order'] : null;
    $note  = isset($args['customer_note']) ? trim(wp_strip_all_tags($args['customer_note'])) : '';
    if (!$order || $note === '') return;
    sefid_notify_customer(
        $order->get_billing_phone() ?: $order->get_billing_email(),
        sprintf(
            /* translators: 1: order number, 2: the note text */
            __('About order %1$s: %2$s', 'sefid-kit'),
            $order->get_order_number(),
            $note
        )
    );
}
add_action('woocommerce_new_customer_note', 'sefid_notify_on_customer_note', 20, 1);

/* ── Shipping rates, straight from WooCommerce ─────────────────────────
 * Sefid sends a signed request; we run the store's own shipping engine (zones,
 * methods, carrier plugins) for that cart and destination. Nothing is re-implemented:
 * these are WooCommerce's own rates. */

/** Normalise Persian text so a province name can be matched to its WooCommerce code. */
function sefid_norm_fa($s) {
    $s = (string) $s;
    $s = str_replace(array("\xd9\x8a", "\xd9\x83", "\xe2\x80\x8c"), array("\xdb\x8c", "\xda\xa9", " "), $s); // Arabic ya/kaf and ZWNJ
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Verify an inbound request from Sefid, using the same canonical scheme as outbound. */
function sefid_rest_verify_sig($request) {
    $secret = sefid_opt('secret_key');
    $public = sefid_opt('public_key');
    if (!$secret || !$public) return false;
    $key = $request->get_header('x-sefid-key');
    if (!$key || !hash_equals($public, (string) $key)) return false;
    $ts    = (string) $request->get_header('x-sefid-timestamp');
    $nonce = (string) $request->get_header('x-sefid-nonce');
    $sig   = (string) $request->get_header('x-sefid-signature');
    if (!$ts || !$nonce || !$sig) return false;
    if (abs(round(microtime(true) * 1000) - intval($ts)) > 5 * 60 * 1000) return false; // +/- 5 minutes
    $body     = $request->get_body();
    $path     = '/wp-json/sefid/v1/shipping/quote';
    $expected = sefid_sign_request('POST', $path, $ts, $nonce, $body === null ? '' : $body);
    return hash_equals($expected, $sig);
}

/**
 * Whether a WooCommerce shipping rate is collecting in person: the classic "Local pickup" method or the block
 * checkout's pickup locations. Sefid then needs no street address for the order (audit 2026-10-06).
 */
function sefid_rate_is_pickup($method_id) {
    return in_array((string) $method_id, array('local_pickup', 'pickup_location'), true);
}

/** Quote shipping for the given cart and destination. */
function sefid_rest_shipping_quote($request) {
    if (!function_exists('WC') || !WC()->shipping()) {
        return new WP_REST_Response(array('ok' => false, 'reason' => 'woocommerce_inactive'), 200);
    }
    $p     = json_decode($request->get_body(), true);
    $items = (isset($p['items']) && is_array($p['items'])) ? $p['items'] : array();
    $dest  = (isset($p['destination']) && is_array($p['destination'])) ? $p['destination'] : array();

    // Province name to WooCommerce state code, in the store's own country (it was always Iran, so a European
    // store was quoted its "rest of the world" zone; audit 2026-10-06)
    $country = WC()->countries ? WC()->countries->get_base_country() : 'IR';
    $state = '';
    $prov  = isset($dest['province']) ? $dest['province'] : '';
    if ($prov && WC()->countries) {
        $states = WC()->countries->get_states($country);
        if (is_array($states)) {
            $target = sefid_norm_fa($prov);
            foreach ($states as $code => $label) {
                if (sefid_norm_fa($label) === $target) { $state = $code; break; }
            }
        }
    }

    // Build a WooCommerce package from the cart lines.
    $contents = array();
    $cost     = 0;
    foreach ($items as $it) {
        $pid = isset($it['externalId']) ? intval($it['externalId']) : 0;
        $qty = isset($it['qty']) ? max(1, intval($it['qty'])) : 1;
        if (!$pid) continue;
        $product = wc_get_product($pid);
        if (!$product || !$product->needs_shipping()) continue;
        $line = floatval($product->get_price()) * $qty;
        $cost += $line;
        $contents[$pid . '_' . $qty] = array(
            'data'          => $product,
            'quantity'      => $qty,
            'line_total'    => $line,
            'line_subtotal' => $line,
        );
    }

    $package = array(
        'contents'        => $contents,
        'contents_cost'   => $cost,
        'applied_coupons' => array(),
        'user'            => array('ID' => 0),
        'destination'     => array(
            'country'   => $country,
            'state'     => $state,
            'postcode'  => isset($dest['postcode']) ? (string) $dest['postcode'] : '',
            'city'      => isset($dest['city']) ? (string) $dest['city'] : '',
            'address'   => '',
            'address_2' => '',
        ),
        'cart_subtotal'   => $cost,
    );

    $options = array();
    try {
        $calc = WC()->shipping()->calculate_shipping_for_package($package);
        if (!empty($calc['rates']) && is_array($calc['rates'])) {
            foreach ($calc['rates'] as $rate) {
                $c = floatval($rate->get_cost());
                $options[] = array(
                    'method' => wp_strip_all_tags($rate->get_label()),
                    'cost'   => (int) round($c),
                    'free'   => $c <= 0,
                    'pickup' => sefid_rate_is_pickup($rate->get_method_id()),
                );
            }
        }
    } catch (\Throwable $e) {
        return new WP_REST_Response(array('ok' => false, 'reason' => 'calc_error'), 200);
    }

    return new WP_REST_Response(array(
        'ok'       => true,
        'currency' => get_woocommerce_currency(),
        'options'  => $options,
    ), 200);
}

add_action('rest_api_init', function () {
    register_rest_route('sefid/v1', '/shipping/quote', array(
        'methods'             => 'POST',
        'callback'            => 'sefid_rest_shipping_quote',
        'permission_callback' => 'sefid_rest_verify_sig',
    ));
});

/* ── Creating a real WooCommerce order from the chat ───────────────────
 * The assistant sends a cart; this builds an ordinary WooCommerce order (lines,
 * address, a real shipping rate, coupons via WooCommerce itself) and returns the
 * store's own payment URL. Coupon errors come back in WooCommerce's own words. */

/** Same signature check, different path. */
function sefid_rest_verify_sig_orders($request) {
    $secret = sefid_opt('secret_key');
    if (!$secret) return false;
    $public = sefid_opt('public_key');
    $key    = (string) $request->get_header('x-sefid-key');
    if ($public && $key && !hash_equals($public, $key)) return false; // if present, it must match
    $ts    = (string) $request->get_header('x-sefid-timestamp');
    $nonce = (string) $request->get_header('x-sefid-nonce');
    $sig   = (string) $request->get_header('x-sefid-signature');
    if (!$ts || !$nonce || !$sig) return false;
    if (abs(round(microtime(true) * 1000) - intval($ts)) > 5 * 60 * 1000) return false; // +/- 5 minutes
    $body     = $request->get_body();
    $expected = sefid_sign_request('POST', '/wp-json/sefid/v1/orders/create', $ts, $nonce, $body === null ? '' : $body);
    return hash_equals($expected, $sig);
}

/** Create the order and return its official payment link. */
function sefid_rest_orders_create($request) {
    if (!function_exists('WC') || !function_exists('wc_create_order')) {
        return new WP_REST_Response(array('ok' => false, 'reason' => 'woocommerce_inactive'), 200);
    }
    $p     = json_decode($request->get_body(), true);
    $items = (isset($p['items']) && is_array($p['items'])) ? $p['items'] : array();
    $dest  = (isset($p['destination']) && is_array($p['destination'])) ? $p['destination'] : array();
    $cust  = (isset($p['customer']) && is_array($p['customer'])) ? $p['customer'] : array();
    if (!count($items)) return new WP_REST_Response(array('ok' => false, 'reason' => 'product_not_found'), 200);

    // externalId is the WooCommerce product ID we sent during sync.
    $rows = array();
    foreach ($items as $it) {
        $pid = isset($it['externalId']) ? intval($it['externalId']) : 0;
        $qty = isset($it['qty']) ? max(1, intval($it['qty'])) : 1;
        $product = $pid ? wc_get_product($pid) : false;
        if (!$product || 'publish' !== $product->get_status()) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'product_not_found'), 200);
        }
        $rows[] = array('product' => $product, 'qty' => $qty);
    }

    $order = null;
    try {
        $order = wc_create_order(array('created_via' => 'sefid-chatbot'));
        if (is_wp_error($order)) return new WP_REST_Response(array('ok' => false, 'reason' => 'create_failed'), 200);

        $needs_shipping = false;
        foreach ($rows as $row) {
            $order->add_product($row['product'], $row['qty']);
            if ($row['product']->needs_shipping()) $needs_shipping = true;
        }

        // Recipient address; province name resolved against the store's base country.
        $country = (WC()->countries) ? WC()->countries->get_base_country() : 'IR';
        $state   = '';
        $prov    = isset($dest['province']) ? $dest['province'] : '';
        if ($prov && WC()->countries) {
            $states = WC()->countries->get_states($country);
            if (is_array($states)) {
                $target = sefid_norm_fa($prov);
                foreach ($states as $code => $label) {
                    if (sefid_norm_fa($label) === $target) { $state = $code; break; }
                }
            }
        }
        $name = (isset($dest['receiver']) && $dest['receiver']) ? $dest['receiver'] : (isset($cust['name']) ? $cust['name'] : '');
        $addr = array(
            'first_name' => sanitize_text_field($name),
            'phone'      => isset($cust['phone']) ? sanitize_text_field($cust['phone']) : '',
            'email'      => isset($cust['email']) ? sanitize_email($cust['email']) : '',
            'address_1'  => isset($dest['address']) ? sanitize_text_field($dest['address']) : '',
            'city'       => isset($dest['city']) ? sanitize_text_field($dest['city']) : '',
            'state'      => $state,
            'postcode'   => isset($dest['postcode']) ? sanitize_text_field($dest['postcode']) : '',
            'country'    => $country,
        );
        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        // Coupons go through WooCommerce; a rejected code deletes the half-built order.
        $coupon = isset($p['coupon']) ? trim((string) $p['coupon']) : '';
        if ($coupon !== '') {
            $applied = $order->apply_coupon(function_exists('wc_format_coupon_code') ? wc_format_coupon_code($coupon) : $coupon);
            if (is_wp_error($applied)) {
                $msg = wp_strip_all_tags($applied->get_error_message());
                $order->delete(true);
                return new WP_REST_Response(array('ok' => false, 'reason' => 'coupon_invalid', 'message' => $msg), 200);
            }
        }

        // Real rates for this cart; the customer's choice, or the cheapest.
        if ($needs_shipping && WC()->shipping()) {
            $contents = array();
            $cost     = 0;
            foreach ($rows as $row) {
                if (!$row['product']->needs_shipping()) continue;
                $line = floatval($row['product']->get_price()) * $row['qty'];
                $cost += $line;
                $contents[$row['product']->get_id() . '_' . $row['qty']] = array(
                    'data'          => $row['product'],
                    'quantity'      => $row['qty'],
                    'line_total'    => $line,
                    'line_subtotal' => $line,
                );
            }
            $package = array(
                'contents'        => $contents,
                'contents_cost'   => $cost,
                'applied_coupons' => array(),
                'user'            => array('ID' => 0),
                'destination'     => array(
                    'country'   => $country,
                    'state'     => $state,
                    'postcode'  => $addr['postcode'],
                    'city'      => $addr['city'],
                    'address'   => $addr['address_1'],
                    'address_2' => '',
                ),
                'cart_subtotal'   => $cost,
            );
            $chosen = null;
            $want   = sefid_norm_fa(isset($p['shippingMethod']) ? (string) $p['shippingMethod'] : '');
            try {
                $calc = WC()->shipping()->calculate_shipping_for_package($package);
                if (!empty($calc['rates']) && is_array($calc['rates'])) {
                    if ($want) {
                        foreach ($calc['rates'] as $rate) {
                            if (sefid_norm_fa($rate->get_label()) === $want) { $chosen = $rate; break; }
                        }
                    }
                    if (!$chosen) {
                        foreach ($calc['rates'] as $rate) {
                            if (!$chosen || floatval($rate->get_cost()) < floatval($chosen->get_cost())) $chosen = $rate;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $chosen = null; // carry on without a shipping line; the seller can add one
            }
            if ($chosen && class_exists('WC_Order_Item_Shipping')) {
                $ship = new WC_Order_Item_Shipping();
                $ship->set_method_title(wp_strip_all_tags($chosen->get_label()));
                $ship->set_method_id($chosen->get_method_id());
                $ship->set_total(floatval($chosen->get_cost()));
                $order->add_item($ship);
            }
        }

        $note = isset($p['note']) ? sanitize_text_field((string) $p['note']) : '';
        if ($note) $order->set_customer_note($note);

        $order->calculate_totals();

        return new WP_REST_Response(array(
            'ok'       => true,
            'orderId'  => (string) $order->get_id(),
            'total'    => (int) round(floatval($order->get_total())),
            'discount' => (int) round(floatval($order->get_discount_total())),
            'currency' => get_woocommerce_currency(),
            'payUrl'   => $order->get_checkout_payment_url(),
        ), 200);
    } catch (\Throwable $e) {
        if ($order && !is_wp_error($order)) { try { $order->delete(true); } catch (\Throwable $e2) { /* ignore */ } }
        return new WP_REST_Response(array('ok' => false, 'reason' => 'create_failed'), 200);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('sefid/v1', '/orders/create', array(
        'methods'             => 'POST',
        'callback'            => 'sefid_rest_orders_create',
        'permission_callback' => 'sefid_rest_verify_sig_orders',
    ));
});


/* ── Who is signed in, and their orders (Sefid ADR-0064) ─────────────── */

/**
 * The current visitor's fresh proof: a 10-minute token when signed in, else loggedIn false. Same origin only: the
 * REST cookie check needs WordPress's own nonce, so another site cannot read it. Never cached.
 */
function sefid_rest_identity($request) {
    nocache_headers();
    if (!is_user_logged_in()) return new WP_REST_Response(array('loggedIn' => false), 200);
    $token = sefid_identity_for(wp_get_current_user());
    return new WP_REST_Response($token ? array('loggedIn' => true, 'token' => $token) : array('loggedIn' => false), 200);
}

/** Same signature check as orders/create, for orders/mine. */
function sefid_rest_verify_sig_mine($request) {
    $secret = sefid_opt('secret_key');
    if (!$secret) return false;
    $ts    = (string) $request->get_header('x-sefid-timestamp');
    $nonce = (string) $request->get_header('x-sefid-nonce');
    $sig   = (string) $request->get_header('x-sefid-signature');
    if (!$ts || !$nonce || !$sig) return false;
    if (abs(round(microtime(true) * 1000) - intval($ts)) > 5 * 60 * 1000) return false;
    $body     = $request->get_body();
    $expected = sefid_sign_request('POST', '/wp-json/sefid/v1/orders/mine', $ts, $nonce, $body === null ? '' : $body);
    return hash_equals($expected, $sig);
}

/** Tracking fields shipping and tracking plugins add to an order: read only, never the owner's notes. */
function sefid_tracking_meta($order) {
    $out = array();
    foreach ($order->get_meta_data() as $m) {
        $d = $m->get_data();
        $key = (string) $d['key'];
        if (!preg_match('/track|tracking|barcode|consignment|post_code|shipment|پیگیری|رهگیری/i', $key)) continue;
        $v = $d['value'];
        if (is_array($v) || is_object($v)) $v = wp_json_encode($v);
        $v = trim(wp_strip_all_tags((string) $v));
        if ($v === '' || strlen($v) > 400) continue;
        $out[ltrim($key, '_')] = $v;
        if (count($out) >= 6) break;
    }
    return $out;
}

/**
 * A signed-in customer's own orders, newest first, read only (Sefid calls this for the chat, signed with the site
 * secret): number, dates, status, total, items, shipping, tracking. Never another customer's, never a write.
 */
function sefid_rest_orders_mine($request) {
    if (!function_exists('wc_get_orders')) return new WP_REST_Response(array('ok' => false, 'reason' => 'woocommerce_inactive'), 200);
    $p = json_decode((string) $request->get_body(), true);
    $uid = isset($p['userId']) ? absint($p['userId']) : 0;
    if (!$uid) return new WP_REST_Response(array('ok' => false, 'reason' => 'no_user'), 200);
    $args = array('customer_id' => $uid, 'limit' => min(20, max(1, isset($p['limit']) ? absint($p['limit']) : 10)), 'orderby' => 'date', 'order' => 'DESC');
    if (!empty($p['before'])) $args['date_created'] = '<' . strtotime((string) $p['before']);
    $out = array();
    foreach (wc_get_orders($args) as $o) {
        if (!is_a($o, 'WC_Order') || (int) $o->get_customer_id() !== $uid) continue;
        $items = array();
        foreach ($o->get_items() as $it) $items[] = array('name' => $it->get_name(), 'qty' => $it->get_quantity(), 'total' => (float) $it->get_total());
        $created = $o->get_date_created();
        $done = $o->get_date_completed();
        $out[] = array(
            'number'   => (string) $o->get_order_number(),
            'date'     => $created ? $created->date('c') : null,
            'status'   => wc_get_order_status_name($o->get_status()),
            'total'    => (float) $o->get_total(),
            'currency' => $o->get_currency(),
            'items'    => $items,
            'shipping' => $o->get_shipping_method(),
            'paid'     => $o->is_paid(),
            'completed'=> $done ? $done->date('c') : null,
            'tracking' => sefid_tracking_meta($o),
            'note'     => (string) $o->get_customer_note(),
        );
    }
    return new WP_REST_Response(array('ok' => true, 'orders' => $out), 200);
}

add_action('rest_api_init', function () {
    register_rest_route('sefid/v1', '/identity', array(
        'methods'             => 'GET',
        'callback'            => 'sefid_rest_identity',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('sefid/v1', '/orders/mine', array(
        'methods'             => 'POST',
        'callback'            => 'sefid_rest_orders_mine',
        'permission_callback' => 'sefid_rest_verify_sig_mine',
    ));
});


/* ── Connection check ─────────────────────────────────────────────────── */

/**
 * Check the connection for the settings screen.
 *
 * Blocking with a short timeout, and cached for a minute or two so opening the page
 * stays fast. Network failures are kept separate from HTTP failures because they lead
 * the store owner somewhere completely different.
 */
function sefid_check_connection() {
    if (!sefid_is_configured()) return false;
    $cached = get_transient(SEFID_CONN_T);
    if ($cached !== false) {
        return ($cached === 'down') ? false : $cached;
    }
    $res = sefid_send('POST', '/api/ingest/verify', array('from' => 'wp-kit'), true);
    if (is_wp_error($res)) {
        set_transient(SEFID_CONN_ERR_T, 'network:' . $res->get_error_message(), 60);
        set_transient(SEFID_CONN_T, 'down', 60);
        return false;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        set_transient(SEFID_CONN_ERR_T, 'http:' . $code, 60);
        set_transient(SEFID_CONN_T, 'down', 60);
        return false;
    }
    delete_transient(SEFID_CONN_ERR_T);
    $data = json_decode(wp_remote_retrieve_body($res), true);
    $ok = (is_array($data) && !empty($data['ok'])) ? $data : false;
    set_transient(SEFID_CONN_T, $ok ? $ok : 'down', $ok ? 120 : 60);
    return $ok;
}

/** Sefid's mark, as the admin menu icon. */
function sefid_menu_icon() {
    $svg = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">'
        . '<path fill="#a7aaad" d="m2.394 13.742 4.743 3.62 7.616-8.704-1.506-1.316-6.384 7.296-3.257-2.486z"/>'
        . '<path fill="#a7aaad" opacity="0.4" d="M21.753 8.658l-1.506-1.316-6.369 7.279-.753-.602-1.25 1.562 2.247 1.798z"/>'
        . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/* ── Self-hosted updates ──────────────────────────────────────────────── */

/** Latest version info, cached for hours; only fetched when WordPress checks. */
function sefid_update_info() {
    $cached = get_transient(SEFID_UPDATE_T);
    if ($cached !== false) return ($cached === 'none') ? null : $cached;
    $base = rtrim(sefid_opt('api_base'), '/');
    if (!$base) return null;
    $res = wp_remote_get($base . '/api/plugin/wp/info', array('timeout' => 5, 'sslverify' => true));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        set_transient(SEFID_UPDATE_T, 'none', 2 * HOUR_IN_SECONDS);
        return null;
    }
    $info = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($info) || empty($info['version'])) {
        set_transient(SEFID_UPDATE_T, 'none', 2 * HOUR_IN_SECONDS);
        return null;
    }
    set_transient(SEFID_UPDATE_T, $info, 6 * HOUR_IN_SECONDS);
    return $info;
}

/** Offer the new version on the Plugins screen. */
function sefid_check_update($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $info = sefid_update_info();
    if (!$info) return $transient;
    $file = plugin_basename(__FILE__);
    $cur = isset($transient->checked[$file]) ? $transient->checked[$file] : SEFID_VERSION;
    if (version_compare($info['version'], $cur, '>')) {
        $transient->response[$file] = (object) array(
            'slug'         => 'sefid-kit',
            'plugin'       => $file,
            'new_version'  => $info['version'],
            'package'      => isset($info['download_url']) ? $info['download_url'] : '',
            'url'          => isset($info['homepage']) ? $info['homepage'] : 'https://sefid.dev',
            'tested'       => isset($info['tested']) ? $info['tested'] : '',
            'requires_php' => isset($info['requires_php']) ? $info['requires_php'] : '',
        );
    } else {
        $transient->no_update[$file] = (object) array(
            'slug' => 'sefid-kit', 'plugin' => $file, 'new_version' => $cur, 'package' => '', 'url' => 'https://sefid.dev',
        );
    }
    return $transient;
}
add_filter('pre_set_site_transient_update_plugins', 'sefid_check_update');

/** Details for the "View details" popup. */
function sefid_plugins_api($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'sefid-kit') return $result;
    $info = sefid_update_info();
    if (!$info) return $result;
    return (object) array(
        'name'          => isset($info['name']) ? $info['name'] : 'Sefid WP Kit',
        'slug'          => 'sefid-kit',
        'version'       => $info['version'],
        'author'        => isset($info['author']) ? $info['author'] : 'sefid.dev',
        'homepage'      => isset($info['homepage']) ? $info['homepage'] : 'https://sefid.dev',
        'requires'      => isset($info['requires']) ? $info['requires'] : '',
        'tested'        => isset($info['tested']) ? $info['tested'] : '',
        'requires_php'  => isset($info['requires_php']) ? $info['requires_php'] : '',
        'download_link' => isset($info['download_url']) ? $info['download_url'] : '',
        'sections'      => array('description' => __('The Sefid AI assistant for WooCommerce — chat, product sync and order tracking.', 'sefid-kit')),
    );
}
add_filter('plugins_api', 'sefid_plugins_api', 20, 3);

/* ── Settings screen ──────────────────────────────────────────────────── */

function sefid_admin_menu() {
    add_menu_page('Sefid', __('Sefid', 'sefid-kit'), 'manage_options', 'sefid-kit', 'sefid_settings_page', sefid_menu_icon(), 58);
}
add_action('admin_menu', 'sefid_admin_menu');

function sefid_register_settings() {
    // Non-autoloaded: the row holds the secret and must not sit in alloptions.
    register_setting('sefid_kit_group', SEFID_OPT, array(
        'sanitize_callback' => 'sefid_sanitize_options',
        'autoload'          => false,
    ));
    if (function_exists('wp_set_option_autoload')) {
        wp_set_option_autoload(SEFID_OPT, false);
    }
}
add_action('admin_init', 'sefid_register_settings');

/** Persian appears only when WordPress itself is in Persian; everyone else sees English. */
function sefid_load_textdomain() {
    load_plugin_textdomain('sefid-kit', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'sefid_load_textdomain');

/**
 * Sanitise the settings form.
 *
 * Only the fields the form owns are read; sync state in the same row is carried over
 * untouched, so saving settings can never wipe a sync in progress.
 */
function sefid_sanitize_options($input) {
    $out = sefid_opts();
    $before = isset($out['product_source']) ? $out['product_source'] : 'sync';
    $out['public_key'] = isset($input['public_key']) ? sanitize_text_field($input['public_key']) : '';
    $out['secret_key'] = isset($input['secret_key']) ? sanitize_text_field($input['secret_key']) : '';
    if (empty($out['api_base'])) $out['api_base'] = SEFID_DEFAULT_API_BASE;
    foreach (array('widget_enabled', 'notify_enabled', 'notify_notes') as $flag) {
        $out[$flag] = (isset($input[$flag]) && $input[$flag] === '1') ? '1' : '0';
    }
    // where products come from: kept in Sefid (sync) or asked live; order tracking is never the owner's switch
    $out['product_source'] = (isset($input['product_source']) && $input['product_source'] === 'live') ? 'live' : 'sync';
    $out['sync_enabled'] = $out['product_source'] === 'sync' ? '1' : '0';
    if ($out['product_source'] !== $before) {
        sefid_send('POST', '/api/ingest/product-source', array('source' => strtoupper($out['product_source'])), false);
        if ($out['product_source'] === 'sync') $out['initial_done'] = '0';
    }
    // Settings changed: re-check everything rather than trusting stale caches.
    delete_transient(SEFID_CONN_T);
    delete_transient(SEFID_CONN_ERR_T);
    delete_transient(SEFID_OFFLINE_T);
    delete_transient(SEFID_UPDATE_T);
    return $out;
}

/** Human sentence for a failed connection, chosen by what actually went wrong. */
function sefid_connection_problem() {
    $err = get_transient(SEFID_CONN_ERR_T);
    if (is_string($err) && strpos($err, 'network:') === 0) {
        return __('Your server could not reach Sefid. This is not about your keys — it usually means your host blocks outgoing connections. Ask your host to allow outbound HTTPS to sefid.dev.', 'sefid-kit');
    }
    if (is_string($err) && (strpos($err, 'http:401') === 0 || strpos($err, 'http:403') === 0)) {
        return __('Those keys were not accepted. Download the build for this site again from your Sefid panel.', 'sefid-kit');
    }
    if (is_string($err) && strpos($err, 'http:') === 0) {
        return __('Sefid replied unexpectedly. Try again shortly; if it keeps happening, contact the Sefid team.', 'sefid-kit');
    }
    return __('Could not reach Sefid. Try again shortly.', 'sefid-kit');
}

/** The features the Sefid team turned on for this shop, from the last connection check (cached two minutes). */
function sefid_feature_on($key) {
    $conn = sefid_check_connection();
    return is_array($conn) && isset($conn['features']) && is_array($conn['features']) && !empty($conn['features'][$key]);
}

/** Where products come from: two choices, the recommended one first, the live one with its cost said plainly. */
function sefid_source_rows($o) {
    $cur = isset($o['product_source']) && $o['product_source'] === 'live' ? 'live' : 'sync';
    $name = esc_attr(SEFID_OPT) . '[product_source]';
    ?>
    <fieldset class="sefid-row sefid-source">
        <legend><strong><?php echo esc_html__('Where the assistant reads your products from', 'sefid-kit'); ?></strong></legend>
        <label class="sefid-choice">
            <input type="radio" name="<?php echo $name; ?>" value="sync" <?php checked($cur, 'sync'); ?> />
            <span class="sefid-row-text">
                <strong><?php echo esc_html__('Synced with Sefid (recommended)', 'sefid-kit'); ?></strong>
                <em><?php echo esc_html__('Only the public product data (name, price, stock, photo) is kept in Sefid, and every change you make here reaches Sefid at once. Chat answers are fast.', 'sefid-kit'); ?></em>
            </span>
        </label>
        <label class="sefid-choice">
            <input type="radio" name="<?php echo $name; ?>" value="live" <?php checked($cur, 'live'); ?> />
            <span class="sefid-row-text">
                <strong><?php echo esc_html__('Asked live from this site', 'sefid-kit'); ?></strong>
                <em><?php echo esc_html__('No product is kept in Sefid; each time a customer looks for one, Sefid asks this site. Chat answers then depend on how fast your site responds, and may take 5 to 10 seconds longer.', 'sefid-kit'); ?></em>
            </span>
        </label>
    </fieldset>
    <?php
}

/** A feature the Sefid team switches: its state, never a switch here. */
function sefid_locked_row($label, $help, $on) {
    ?>
    <div class="sefid-row sefid-locked">
        <span class="sefid-row-text">
            <strong><?php echo esc_html($label); ?> · <?php echo $on ? esc_html__('On', 'sefid-kit') : esc_html__('Off', 'sefid-kit'); ?></strong>
            <em><?php echo esc_html($help); ?></em>
        </span>
    </div>
    <?php
}

/** A row in the settings list: one switch, one plain sentence about what it does. */
function sefid_toggle_row($key, $label, $help, $value) {
    ?>
    <div class="sefid-row">
        <label class="sefid-switch">
            <input type="checkbox" name="<?php echo esc_attr(SEFID_OPT); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked($value, '1'); ?> />
            <span class="sefid-switch-track" aria-hidden="true"><span class="sefid-switch-dot"></span></span>
            <span class="sefid-row-text">
                <strong><?php echo esc_html($label); ?></strong>
                <em><?php echo esc_html($help); ?></em>
            </span>
        </label>
    </div>
    <?php
}

function sefid_settings_page() {
    if (!current_user_can('manage_options')) return;
    $o    = sefid_opts();
    $conn = sefid_check_connection();
    $retry_count = is_array($o['retry']) ? count($o['retry']) : 0;
    $syncing = ((int) $o['sync_page']) > 0;
    ?>
    <div class="wrap sefid-wrap">
        <h1 class="sefid-title"><?php echo esc_html__('Sefid', 'sefid-kit'); ?></h1>
        <p class="sefid-sub"><?php echo esc_html__('Your AI assistant on this site: it answers visitors, finds your products, and follows up on orders.', 'sefid-kit'); ?></p>

        <?php if ($conn) : ?>
            <div class="sefid-card sefid-ok">
                <div class="sefid-status">
                    <span class="sefid-dot sefid-dot-ok" aria-hidden="true"></span>
                    <strong><?php echo esc_html__('Connected', 'sefid-kit'); ?></strong>
                    <?php if (!empty($conn['site']['name'])) : ?>
                        <span class="sefid-muted">— <?php echo esc_html($conn['site']['name']); ?></span>
                    <?php endif; ?>
                </div>
                <p class="sefid-muted">
                    <?php
                    $pc = isset($conn['site']['productCount']) ? (int) $conn['site']['productCount'] : 0;
                    if ($syncing) {
                        echo esc_html__('The first catalogue sync is running in the background. You can leave this page.', 'sefid-kit');
                    } elseif ($pc > 0) {
                        /* translators: %s: number of products */
                        echo esc_html(sprintf(__('%s products are ready for the assistant to search.', 'sefid-kit'), number_format_i18n($pc)));
                    } else {
                        echo esc_html__('Everything is set up. Your products will appear here once they sync.', 'sefid-kit');
                    }
                    if ($retry_count > 0) {
                        echo ' <span class="sefid-warn">' . esc_html(sprintf(
                            /* translators: %s: number of products waiting */
                            _n('%s product is waiting to be re-sent and will retry on its own.', '%s products are waiting to be re-sent and will retry on their own.', $retry_count, 'sefid-kit'),
                            number_format_i18n($retry_count)
                        )) . '</span>';
                    }
                    ?>
                </p>
            </div>
        <?php elseif (sefid_is_configured()) : ?>
            <div class="sefid-card sefid-bad">
                <div class="sefid-status">
                    <span class="sefid-dot sefid-dot-bad" aria-hidden="true"></span>
                    <strong><?php echo esc_html__('Not connected', 'sefid-kit'); ?></strong>
                </div>
                <p class="sefid-muted"><?php echo esc_html(sefid_connection_problem()); ?></p>
            </div>
        <?php else : ?>
            <div class="sefid-card sefid-todo">
                <div class="sefid-status">
                    <span class="sefid-dot sefid-dot-todo" aria-hidden="true"></span>
                    <strong><?php echo esc_html__('One step left', 'sefid-kit'); ?></strong>
                </div>
                <p class="sefid-muted"><?php echo esc_html__('Paste the two keys from your Sefid panel below and press Save. That is all.', 'sefid-kit'); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php" class="sefid-form">
            <?php settings_fields('sefid_kit_group'); ?>

            <div class="sefid-card">
                <h2><?php echo esc_html__('Connection', 'sefid-kit'); ?></h2>
                <p class="sefid-help"><?php echo esc_html__('Both keys are in your Sefid panel, under Install.', 'sefid-kit'); ?></p>

                <label class="sefid-field">
                    <span><?php echo esc_html__('Public key', 'sefid-kit'); ?></span>
                    <input type="text" dir="ltr" name="<?php echo esc_attr(SEFID_OPT); ?>[public_key]"
                           value="<?php echo esc_attr($o['public_key']); ?>" autocomplete="off" spellcheck="false" />
                </label>

                <label class="sefid-field">
                    <span><?php echo esc_html__('Secret key', 'sefid-kit'); ?></span>
                    <input type="password" dir="ltr" name="<?php echo esc_attr(SEFID_OPT); ?>[secret_key]"
                           value="<?php echo esc_attr($o['secret_key']); ?>" autocomplete="new-password" spellcheck="false" />
                    <em><?php echo esc_html__('This one stays on your server and never reaches a browser.', 'sefid-kit'); ?></em>
                </label>
            </div>

            <div class="sefid-card">
                <h2><?php echo esc_html__('What the assistant does here', 'sefid-kit'); ?></h2>

                <?php
                sefid_toggle_row('widget_enabled', __('Show the chat on my site', 'sefid-kit'),
                    __('A small chat button in the corner of every page.', 'sefid-kit'), $o['widget_enabled']);

                sefid_source_rows($o);

                sefid_toggle_row('notify_enabled', __('Let my store message customers in the chat', 'sefid-kit'),
                    __('For a customer who is signed in and on the site.', 'sefid-kit'), $o['notify_enabled']);

                sefid_toggle_row('notify_notes', __('Send order notes to the customer as well', 'sefid-kit'),
                    __('Your "note to customer" on an order also appears in their chat.', 'sefid-kit'), $o['notify_notes']);

                sefid_locked_row(__('Order tracking in the chat', 'sefid-kit'),
                    __('Turned on by the Sefid team after you agree it with them; it is not a switch here.', 'sefid-kit'), sefid_feature_on('orderTracking'));
                ?>
            </div>

            <?php submit_button(__('Save', 'sefid-kit'), 'primary sefid-save'); ?>
        </form>

        <details class="sefid-card sefid-details">
            <summary><?php echo esc_html__('For developers', 'sefid-kit'); ?></summary>
            <p class="sefid-help"><?php echo esc_html__('Send a notice to a customer from your own theme or plugin:', 'sefid-kit'); ?></p>
            <code class="sefid-code" dir="ltr">do_action('sefid_notify', $user_id, 'Your message', 'https://example.com/link');</code>
            <p class="sefid-help"><?php echo esc_html__('In place of the user id you may pass a phone number or an email address. The notice is only visible to that customer while they are signed in.', 'sefid-kit'); ?></p>
        </details>
    </div>

    <style>
        /* Sefid's look, inside the WordPress admin: calm, roomy, one decision per line. */
        .sefid-wrap { max-width: 720px; }
        .sefid-title { font-size: 26px; font-weight: 700; margin: 18px 0 4px; }
        .sefid-sub { font-size: 14px; color: #50575e; margin: 0 0 20px; max-width: 60ch; line-height: 1.7; }
        .sefid-card { background: #fff; border: 1px solid #e3e5e8; border-radius: 14px; padding: 20px 22px; margin: 0 0 16px; }
        .sefid-card h2 { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
        .sefid-help { font-size: 13px; color: #646970; margin: 0 0 16px; line-height: 1.7; }
        .sefid-muted { color: #50575e; font-size: 13px; line-height: 1.8; margin: 8px 0 0; }
        .sefid-warn { color: #8a6100; }
        .sefid-status { display: flex; align-items: center; gap: 8px; font-size: 15px; }
        .sefid-dot { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 auto; }
        .sefid-dot-ok { background: #14804a; }
        .sefid-dot-bad { background: #b32d2e; }
        .sefid-dot-todo { background: #dba617; }
        .sefid-ok { border-color: #b6e0c8; }
        .sefid-bad { border-color: #f0c2c2; }
        .sefid-todo { border-color: #f5e3b3; }
        .sefid-field { display: block; margin: 0 0 16px; }
        .sefid-field > span { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
        .sefid-field input { width: 100%; max-width: 460px; height: 42px; border-radius: 10px;
            border: 1px solid #cdd2d7; padding: 0 12px; font-size: 14px; }
        .sefid-field input:focus { border-color: #2271b1; box-shadow: 0 0 0 3px rgba(34,113,177,.12); outline: none; }
        .sefid-field em { display: block; font-style: normal; font-size: 12px; color: #646970; margin: 6px 0 0; }
        .sefid-row { border-top: 1px solid #f0f0f1; padding: 14px 0; }
        .sefid-row:first-of-type { border-top: 0; padding-top: 4px; }
        .sefid-switch { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
        .sefid-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .sefid-switch-track { width: 42px; height: 24px; border-radius: 999px; background: #d3d5d8;
            flex: 0 0 auto; margin-top: 2px; position: relative; transition: background .18s ease; }
        .sefid-switch-dot { position: absolute; top: 3px; inset-inline-start: 3px; width: 18px; height: 18px;
            border-radius: 50%; background: #fff; transition: transform .18s ease; }
        .sefid-switch input:checked + .sefid-switch-track { background: #14804a; }
        .sefid-switch input:checked + .sefid-switch-track .sefid-switch-dot { transform: translateX(18px); }
        body.rtl .sefid-switch input:checked + .sefid-switch-track .sefid-switch-dot { transform: translateX(-18px); }
        .sefid-switch input:focus-visible + .sefid-switch-track { box-shadow: 0 0 0 3px rgba(34,113,177,.35); }
        .sefid-row-text strong { display: block; font-size: 14px; font-weight: 600; }
        .sefid-row-text em { display: block; font-style: normal; font-size: 13px; color: #646970;
            margin-top: 3px; line-height: 1.6; }
        .sefid-details summary { cursor: pointer; font-weight: 600; font-size: 14px; }
        .sefid-details[open] summary { margin-bottom: 14px; }
        .sefid-code { display: block; background: #f6f7f7; border: 1px solid #e3e5e8; border-radius: 8px;
            padding: 10px 12px; font-size: 12px; overflow-x: auto; }
        .sefid-save.button { height: 42px; padding: 0 26px; border-radius: 10px; font-size: 14px; }
    </style>
    <?php
}

/** Clean up scheduled work on deactivation. Stored data only goes on uninstall. */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(SEFID_SYNC_HOOK);
    wp_clear_scheduled_hook(SEFID_RETRY_HOOK);
    delete_transient(SEFID_CONN_T);
    delete_transient(SEFID_CONN_ERR_T);
    delete_transient(SEFID_OFFLINE_T);
    delete_transient(SEFID_UPDATE_T);
});
