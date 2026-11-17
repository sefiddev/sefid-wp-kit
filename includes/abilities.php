<?php
/**
 * The store's jobs as WordPress abilities (the Abilities API, in WordPress core since 6.9), beside Sefid's own signed
 * routes (includes/store.php), which stay exactly as they are. Soroush, 2026-11-12: «به صورت هیبریدی باشه. یعنی هم
 * راه خودمون باشه هم راه تازه وردپرس.»
 *
 * Two ways in, one set of functions:
 *   - Sefid's way: POST /wp-json/sefid/v1/store/..., signed with the site's secret; the customer is the one Sefid
 *     vouches for. This is how the chat works, on every WordPress the plugin supports.
 *   - WordPress's way: an ability such as sefid-kit/products-search, found and run by whatever speaks abilities on
 *     this site (the MCP adapter, an AI client). Who may run it is WordPress's own question: the catalogue for any
 *     signed-in user, and a customer's orders, cart and addresses only for that customer, who is WordPress's current
 *     user. No secret of Sefid's is involved, and nobody acts for anybody else.
 *
 * On a WordPress without the Abilities API this file does nothing.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The abilities: name, the store job it runs, whether it is for the signed-in customer, and what it takes. */
function sefid_abilities() {
    $none = array();
    return array(
        'sefid-kit/products-search' => array(
            'route' => '/store/products/search', 'customer' => false, 'readonly' => true,
            'label' => __('Search the shop\'s products', 'sefid-kit'),
            'description' => __('Finds published products by a word or a name, with price and stock.', 'sefid-kit'),
            'input' => array('type' => 'object', 'required' => array('q'), 'additionalProperties' => false, 'properties' => array(
                'q' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'What to look for.'),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'description' => 'How many at most (10 when left out).'),
            )),
        ),
        'sefid-kit/products-get' => array(
            'route' => '/store/products/get', 'customer' => false, 'readonly' => true,
            'label' => __('Read one product', 'sefid-kit'),
            'description' => __('One product by its id, with price and stock.', 'sefid-kit'),
            'input' => array('type' => 'object', 'required' => array('id'), 'additionalProperties' => false, 'properties' => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            )),
        ),
        'sefid-kit/orders-mine' => array(
            'route' => '/store/orders/mine', 'customer' => true, 'readonly' => true,
            'label' => __('My orders', 'sefid-kit'),
            'description' => __('The signed-in customer\'s own latest orders and their status.', 'sefid-kit'),
            'input' => array('type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 20),
            )),
        ),
        'sefid-kit/cart-get' => array(
            'route' => '/store/cart/get', 'customer' => true, 'readonly' => true,
            'label' => __('My cart', 'sefid-kit'),
            'description' => __('What is in the signed-in customer\'s own cart.', 'sefid-kit'),
            'input' => $none,
        ),
        'sefid-kit/cart-add' => array(
            'route' => '/store/cart/add', 'customer' => true, 'readonly' => false,
            'label' => __('Add to my cart', 'sefid-kit'),
            'description' => __('Puts a product in the signed-in customer\'s own cart.', 'sefid-kit'),
            'input' => array('type' => 'object', 'required' => array('productId'), 'additionalProperties' => false, 'properties' => array(
                'productId' => array('type' => 'integer', 'minimum' => 1),
                'variationId' => array('type' => 'integer', 'minimum' => 0),
                'quantity' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 99),
            )),
        ),
        'sefid-kit/cart-remove' => array(
            'route' => '/store/cart/remove', 'customer' => true, 'readonly' => false,
            'label' => __('Remove from my cart', 'sefid-kit'),
            'description' => __('Takes one line out of the signed-in customer\'s own cart.', 'sefid-kit'),
            'input' => array('type' => 'object', 'required' => array('lineId'), 'additionalProperties' => false, 'properties' => array(
                'lineId' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 64),
            )),
        ),
        'sefid-kit/cart-clear' => array(
            'route' => '/store/cart/clear', 'customer' => true, 'readonly' => false, 'destructive' => true,
            'label' => __('Empty my cart', 'sefid-kit'),
            'description' => __('Empties the signed-in customer\'s own cart.', 'sefid-kit'),
            'input' => $none,
        ),
        'sefid-kit/addresses-list' => array(
            'route' => '/store/addresses/list', 'customer' => true, 'readonly' => true,
            'label' => __('My addresses', 'sefid-kit'),
            'description' => __('The signed-in customer\'s own saved addresses.', 'sefid-kit'),
            'input' => $none,
        ),
        'sefid-kit/addresses-add' => array(
            'route' => '/store/addresses/add', 'customer' => true, 'readonly' => false,
            'label' => __('Save an address', 'sefid-kit'),
            'description' => __('Saves a delivery address for the signed-in customer.', 'sefid-kit'),
            'input' => array('type' => 'object', 'required' => array('fields'), 'additionalProperties' => false, 'properties' => array(
                'fields' => array('type' => 'object', 'required' => array('city', 'address'), 'additionalProperties' => false, 'properties' => array(
                    'label' => array('type' => 'string', 'maxLength' => 80),
                    'city' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 80),
                    'address' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 300),
                    'postcode' => array('type' => 'string', 'maxLength' => 20),
                    'recipient' => array('type' => 'string', 'maxLength' => 120),
                    'phone' => array('type' => 'string', 'maxLength' => 30),
                )),
            )),
        ),
        'sefid-kit/checkout-start' => array(
            'route' => '/store/checkout/start', 'customer' => true, 'readonly' => false,
            'label' => __('Start my checkout', 'sefid-kit'),
            'description' => __('Makes an order from the signed-in customer\'s own cart, for one of their saved addresses, and gives the page to pay on. Nothing is paid here.', 'sefid-kit'),
            'input' => array('type' => 'object', 'required' => array('addressId'), 'additionalProperties' => false, 'properties' => array(
                'addressId' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 40),
                'coupon' => array('type' => 'string', 'maxLength' => 60),
            )),
        ),
    );
}

/** Runs one store job for an ability: the same function the signed route calls, for WordPress's current user. */
function sefid_ability_run($name, $input = null) {
    $all = sefid_abilities();
    $routes = sefid_store_routes();
    if (!isset($all[$name]) || !isset($routes[$all[$name]['route']])) return new WP_Error('sefid_unknown_ability', 'Unknown ability.');
    $a = $all[$name];
    $uid = $a['customer'] ? get_current_user_id() : 0;
    if ($a['customer'] && !$uid) return new WP_Error('sefid_not_signed_in', 'Sign in first.');
    $request = new WP_REST_Request('POST', '/sefid/v1' . $a['route']);
    $request->set_body(wp_json_encode(is_array($input) ? $input : new stdClass()));
    sefid_ability_user($uid);
    try {
        $res = call_user_func($routes[$a['route']], $request);
    } finally {
        sefid_ability_user(0); // never left set: the next request in this process is nobody's
    }
    $data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
    if (!is_array($data)) return new WP_Error('sefid_no_answer', 'The shop gave no answer.');
    if (empty($data['ok'])) return new WP_Error('sefid_' . (isset($data['error']) ? sanitize_key($data['error']) : 'failed'), 'The shop could not do this.');
    return $data;
}

/** Who may run an ability: the catalogue for any signed-in user, the rest only for a signed-in customer (their own). */
function sefid_ability_allowed($name) {
    $all = sefid_abilities();
    if (!isset($all[$name]) || !sefid_is_configured()) return false;
    return $all[$name]['customer'] ? is_user_logged_in() : current_user_can('read');
}

if (function_exists('wp_register_ability')) {
    add_action('wp_abilities_api_categories_init', function () {
        wp_register_ability_category('sefid-store', array(
            'label' => __('Sefid store', 'sefid-kit'),
            'description' => __('The shop\'s catalogue and a customer\'s own orders, cart and addresses.', 'sefid-kit'),
        ));
    });
    add_action('wp_abilities_api_init', function () {
        foreach (sefid_abilities() as $name => $a) {
            wp_register_ability($name, array(
                'label' => $a['label'],
                'description' => $a['description'],
                'category' => 'sefid-store',
                'input_schema' => $a['input'],
                'output_schema' => array('type' => 'object', 'required' => array('ok'), 'properties' => array('ok' => array('type' => 'boolean'))),
                // with an empty input schema WordPress calls these with no argument at all
                'execute_callback' => function ($input = null) use ($name) {
                    return sefid_ability_run($name, $input);
                },
                'permission_callback' => function ($input = null) use ($name) {
                    return sefid_ability_allowed($name);
                },
                'meta' => array(
                    'show_in_rest' => true,
                    'annotations' => array('readonly' => $a['readonly'], 'destructive' => !empty($a['destructive']), 'idempotent' => $a['readonly']),
                ),
            ));
        }
    });
}
