<?php
/**
 * Chatway admin assets enqueue
 *
 * @author  : Chatway
 * @license : GPLv3
 * */

namespace Chatway\App;

class Front
{
    use Singleton;

    private $cart_updated_from_session = false;

    public function __construct()
    {
        add_action( 'wp_ajax_sync_chatway_data', [$this, 'check_for_conversation'] );
        add_action( 'wp_ajax_nopriv_sync_chatway_data', [$this, 'check_for_conversation'] );
        add_action( 'woocommerce_add_to_cart', [$this, 'add_to_cart'], 10, 6 );
        add_action( 'woocommerce_after_cart_item_quantity_update', [$this, 'product_updated'], 10, 2 );
        add_action( 'woocommerce_cart_item_removed', [$this, 'item_removed'], 10, 2 );
        add_action( 'woocommerce_remove_cart_item_from_session', [$this, 'cart_item_removed_from_session'], 10, 2 );
        add_action( 'woocommerce_cart_loaded_from_session', [$this, 'cart_loaded_from_session'], 10, 1 );
        add_action( 'woocommerce_thankyou', [$this, 'handle_order_placed'], 10, 1 );
    }

    /**
     * Handles checking for an ongoing conversation for the current user and updates user status, visitor data, and cookies accordingly.
     *
     * This method retrieves the current user's ID and verifies if necessary cookies and data are available to establish a conversation.
     * It ensures the user's chat status is set, validates email and other user metadata, and securely sends visitor information
     * using an external API. If the API interaction is successful, the user's conversation status is updated.
     *
     * @return void
     */
    public function check_for_conversation() {
        $token = sanitize_text_field(filter_input(INPUT_GET, 'token'));
        if (empty($token) || !wp_verify_nonce($token, 'chatway_nonce')) {
            return;
        }
        $this->update_cart_data();
        $user_id = get_current_user_id();
        if(!empty($user_id)) {
            $token = get_option('chatway_user_identifier', '');
            if (!empty($token)) {
                if ((isset($_COOKIE['ch_cw_contact_id_' . $token]) || isset($_GET['ch_contact_id'])) && isset($_COOKIE['ch_cw_token_' . $token])) {
                    $contact_id    = isset($_COOKIE['ch_cw_contact_id_' . $token])?sanitize_text_field($_COOKIE['ch_cw_contact_id_' . $token]):"";
                    if(empty($contact_id) && isset($_GET['ch_contact_id'])) {
                        $contact_id = sanitize_text_field($_GET['ch_contact_id']);
                    }
                    $contact_token = sanitize_text_field($_COOKIE['ch_cw_token_' . $token]);
                    if (!empty($contact_id) && !empty($contact_token)) {
                        $user_status = get_user_meta($user_id, 'chatway_status_'.esc_attr($contact_id), true);
                        if($user_status) {
                            setcookie('ch_cw_user_status_' . $contact_id, 'yes', time() + YEAR_IN_SECONDS, "/");
                            return;
                        }

                        $user = get_userdata($user_id);

                        if (!isset($user->data->user_email) || empty($user->data->user_email)) {
                            return;
                        }
                        $email = $user->data->user_email;

                        $secret_key = ExternalApi::get_chatway_secret_key();

                        if (empty($secret_key)) {
                            return;
                        }

                        $first_name = get_user_meta($user_id, 'first_name', true);
                        $last_name = get_user_meta($user_id, 'last_name', true);
                        $name = trim($first_name . ' ' . $last_name);
                        $user_info = [
                            'email' => esc_attr($email),
                            'id' => esc_attr($user_id)
                        ];
                        if (!empty($name)) {
                            $user_info['name'] = $name;
                        }

                        $avatar = get_avatar_url($user_id);
                        if(!empty($avatar)) {
                            $user_info['avatar'] = $avatar;
                        }

                        $hmac = hash_hmac(
                            'sha256',
                            json_encode($user_info),
                            esc_attr($secret_key)
                        );

                        $response_code = ExternalApi::send_visitor_data($hmac, $contact_id, $user_info, $contact_token);

                        if (is_array($response_code) && isset($response_code['message']) && $response_code['message'] == 'Success') {
                            setcookie('ch_cw_user_status_' . $contact_id, 'yes', time() + YEAR_IN_SECONDS, "/");
                            add_user_meta($user_id, 'chatway_status_'.esc_attr($contact_id), $contact_id);

                            $version = get_option('chatway_wp_plugin_version', '');
                            if($version != \Chatway::version()) {
                                ExternalApi::sync_wp_plugin_version();
                            }
                        }

                        echo json_encode($response_code);
                        exit;
                    }
                }
            }
        }
    }

    /**
     * Sends the card information based on the provided cart item details.
     *
     * @param string $cart_item_key The unique key identifying the cart item.
     * @param int $product_id The ID of the product being sent.
     * @param int $quantity The quantity of the product in the cart.
     * @param int $variation_id The ID of the product variation, if applicable.
     * @param array $variation The details of the product variation, if applicable.
     * @param array $cart_item_data Additional data associated with the cart item.
     * @return void
     */
    public function add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        $this->update_cart_data();
    }

    /**
     * Updates the product information in the cart based on the provided cart item key and cart data.
     *
     * @param string $cart_item_key The unique key identifying the cart item.
     * @param array $cart The current cart data associated with the item.
     * @return void
     */
    public function product_updated($cart_item_key, $cart)
    {
        $this->update_cart_data();
    }

    /**
     * Handles the event of an order being placed.
     *
     * @param int $order_id The ID of the order that was placed.
     * @return void
     */
    public function handle_order_placed($order_id)
    {
        if (!$order_id) {
            return;
        }
        $this->update_cart_data();
    }

    /**
     * Handles the removal of an item from the cart.
     *
     * @param string $cart_item_key The unique key identifying the cart item that was removed.
     * @param array $cart The current state of the cart after the item was removed.
     * @return void
     */
    public function item_removed($cart_item_key, $cart)
    {
        $this->update_cart_data();
    }

    /**
     * Marks the cart as updated when an item is removed from the cart during session loading (e.g. when product is moved to trash).
     *
     * @param string $cart_item_key The unique key identifying the cart item.
     * @param array $values Cart item values.
     * @return void
     */
    public function cart_item_removed_from_session($cart_item_key, $values)
    {
        $this->cart_updated_from_session = true;
    }

    /**
     * Calls update_cart_data if the cart was modified during session loading.
     *
     * @param object $cart The cart object.
     * @return void
     */
    public function cart_loaded_from_session($cart)
    {
        if ($this->cart_updated_from_session) {
            $this->update_cart_data();
            $this->cart_updated_from_session = false;
        }
    }

    /**
     * Updates the cart data by collecting details of the cart contents and sending it to an external API.
     * The method retrieves the cart items, prepares the product details, and sends the data if required cookies and tokens are available.
     *
     * @return void
     */
    public function update_cart_data()
    {
        $version = get_option('chatway_wp_plugin_version', '');
        if($version != \Chatway::version()) {
            ExternalApi::sync_wp_plugin_version();
        }
        if(!\Chatway::is_woocomerce_active()) {
            return;
        }
        $token = get_option('chatway_user_identifier', '');
        if(!empty($token) && isset($_COOKIE['ch_cw_token_' . $token])) {
            $contact_id    = isset($_COOKIE['ch_cw_contact_id_' . $token])?sanitize_text_field($_COOKIE['ch_cw_contact_id_' . $token]):"";
            if(empty($contact_id) && isset($_GET['ch_contact_id'])) {
                $contact_id = sanitize_text_field($_GET['ch_contact_id']);
            }
            if(empty($contact_id)) {
                return;
            }
            $contact_token = sanitize_text_field($_COOKIE['ch_cw_token_' . $token]);
            if (!empty($contact_id) && !empty($contact_token)) {
                setcookie('ch_cw_contact_id_' . $token, $contact_id, time() + YEAR_IN_SECONDS, "/");
            }

            $contact_id = sanitize_text_field($_COOKIE['ch_cw_contact_id_' . $token]);
            $contact_token = sanitize_text_field($_COOKIE['ch_cw_token_' . $token]);
            if(empty($contact_id)  || empty($contact_token)) {
                return;
            }
            if (!WC()->cart || !WC()->session) {
                return;
            }

            $cart_contents = WC()->cart->get_cart();
            $products = [];
            $default_image = wc_placeholder_img_src();
            foreach ($cart_contents as $cart_item) {
                $productData = wc_get_product($cart_item['product_id']);
                $product = [];
                $product['product_id'] = intval($cart_item['product_id']);
                $product['product_url'] = get_permalink($cart_item['product_id']);
                $product['variation_id'] = intval($cart_item['variation_id']);
                $product['quantity'] = intval($cart_item['quantity']);
                $product['subtotal'] = isset($cart_item['line_subtotal'])
                    ? (float) $cart_item['line_subtotal']
                    : (float)($cart_item['data']->get_price() * $cart_item['quantity']);
                $product['total'] = isset($cart_item['line_total'])
                    ? (float) $cart_item['line_total']
                    : (float)($cart_item['data']->get_price() * $cart_item['quantity']);
                $product['subtotal_tax'] = isset($cart_item['line_subtotal_tax']) ? (float) $cart_item['line_subtotal_tax'] : 0;
                $product['total_tax'] = isset($cart_item['line_tax']) ? (float) $cart_item['line_tax'] : 0;
                $product['product_name'] = $cart_item['data']->get_name();
                $product['price'] = (float)$cart_item['data']->get_price();
                $product['regular_price'] = $productData->get_regular_price();
                $product['short_description'] = $productData->get_short_description();
                $product['sku'] = $productData->get_sku();
                $product['image'] = $default_image;
                $product['thumb_image'] = $default_image;
                $thumbnail_url = get_the_post_thumbnail_url($cart_item['product_id'], 'full');
                if(!empty($thumbnail_url)) {
                    $product['image'] = $thumbnail_url;
                    $product['thumb_image'] = get_the_post_thumbnail_url($cart_item['product_id']);
                }
                $products[] = $product;
            }

            $cart = [];
            WC()->cart->calculate_totals();
            if (!empty($products)) {
                $cart['products'] = $products;
            } else {
                $cart['products'] = [];
            }
            $cart['cart_info'] = WC()->cart->get_totals();
            $cart['cart_info']['currency'] = get_woocommerce_currency();
            $cart['cart_info']['symbol'] = get_woocommerce_currency_symbol();
            ExternalApi::send_cart_data($contact_id, $cart, $contact_token);
            WC()->session->set('chatway_contact_id', $contact_id);
            WC()->session->set('chatway_contact_token', $contact_token);
            WC()->session->set('chatway_cart_data', $cart);
        }
    }
}
