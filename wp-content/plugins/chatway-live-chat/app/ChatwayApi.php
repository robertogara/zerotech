<?php
/**
 * Chatway admin assets enqueue
 *
 * @author  : Chatway
 * @license : GPLv3
 * */

namespace Chatway\App;

use \WC_Coupon;

class ChatwayApi
{
    use Singleton;

    var $response;

    /**
     * Constructor method.
     *
     * Initializes the class by adding the 'init' action and setting up the default response structure.
     *
     * @return void
     */
    public function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('activated_plugin', [$this, 'plugin_activated']);
        add_action('deactivated_plugin', [$this, 'plugin_deactivated']);

        add_action('upgrader_process_complete', [$this, 'chatway_plugin_update_handler'], 10, 2);
        $this->response = [
            'status'    => 0,
            'version'    => \Chatway::version(),
            'message'   => '',
            'records'   => []
        ];
    }

    function plugin_activated($plugin)
    {
        if ($plugin == 'woocommerce/woocommerce.php') {
            ExternalApi::sync_wp_plugin_version("activated");
        }
    }

    function plugin_deactivated($plugin)
    {
        if ($plugin == 'woocommerce/woocommerce.php') {
            ExternalApi::sync_wp_plugin_version("deactivated");
        }
    }

    function chatway_plugin_update_handler($upgrader_object, $options) {
        // Check if the current task is updating a plugin
        if ($options['action'] === 'update' && $options['type'] === 'plugin' && isset($options['plugins'])) {
            // Get the list of updated plugins
            $updated_plugins = $options['plugins'];

            // Check if this includes the Chatway plugin
            if (!empty($updated_plugins)) {
                foreach ($updated_plugins as $plugin) {
                    if (strpos($plugin, 'chatway-live-chat/chatway.php') !== false) {
                        ExternalApi::sync_wp_plugin_version();
                    }
                }
            }
        }
    }




    /**
     * Initializes the chatway action handler.
     *
     * Validates the 'chatway_action' and 'token' request parameters. Based on the
     * validated action ('products', 'categories', 'orders', or 'coupons'), the
     * corresponding processing method is invoked. Responds with a JSON success
     * or error message depending on the validity of the input and execution of the
     * action.
     *
     * @return void Outputs a JSON response containing the status and message based on the request handling.
     */
    public function init() {


        if(isset($_GET['token']) && !empty($_GET['token']) && isset($_GET['chatway_action']) && !empty($_GET['chatway_action'])) {
            $chatway_action = sanitize_text_field(filter_input(INPUT_GET, 'chatway_action'));            
            if(!$this->validate_token()) {
                $this->response['message'] = esc_html__('Invalid token', 'chatway');
                wp_send_json($this->response);
                exit;
            }
            if(in_array($chatway_action, ['products', 'categories', 'orders', 'coupons', 'get_order'])) {
                if(!\Chatway::is_woocomerce_active()) {
                    $this->response['message'] = esc_html__('WooCommerce is not active', 'chatway');
                    wp_send_json($this->response);
                    return;
                }
                if($chatway_action == 'products') {
                    $this->fetch_products();
                } else if($chatway_action == 'categories') {
                    $this->fetch_categories();
                } else if($chatway_action == 'orders') {
                    $this->fetch_orders();
                } else if($chatway_action == 'coupons') {
                    $this->fetch_coupons();
                } else if($chatway_action == 'get_order') {
                    $this->fetch_order();
                }
                $this->response['status']  = 0;
                $this->response['message'] = esc_html__('Invalid request', 'chatway');
                wp_send_json($this->response);
                return;
            } else {
                if (in_array($chatway_action, ['post_type_list', 'get_post_type_list', 'get_post_type_data'], true)) {
                    if($chatway_action == 'post_type_list') {
                        $this->get_post_type_list();
                    } else if($chatway_action == 'get_post_type_data') {
                        $this->get_post_type_data();
                    }
                }
            }
            $this->response['message'] = esc_html__('Invalid request', 'chatway');
            wp_send_json($this->response);
        }
    }

    public function get_post_type_list() {
        $args = array(
            'public'   => true,
        );
        $post_types = get_post_types($args, 'objects');

        $records = [];
        foreach ($post_types as $key => $post_type) {
            if($key == 'attachment') {
                continue;
            }
            $record = [];
            $record['label'] = $post_type->labels->name;
            $record['post_type'] = $key;
            $count_posts = wp_count_posts($key);
            $record['count'] = isset($count_posts->publish) ? (int) $count_posts->publish : 0;
            $records[] = $record;
        }

        $this->response['status'] = 1;
        $this->response['records'] = $records;
        wp_send_json($this->response);
        exit;
    }


    private function get_post_type_data()
    {
        $post_type = sanitize_text_field(filter_input(INPUT_GET, 'post_type'));
        $args = array(
            'public'   => true,
        );
        $post_types = get_post_types($args);
        if ($post_type === 'attachment' || !isset($post_types[$post_type])) {
            $this->response['message'] = esc_html__('Invalid post type', 'chatway');
            wp_send_json($this->response);
            exit;
        }
        $per_page = sanitize_text_field(filter_input(INPUT_GET, 'per_page'));
        $current_page = sanitize_text_field(filter_input(INPUT_GET, 'current_page'));

        $per_page = !is_numeric($per_page) || $per_page <= 1 ? 10 : absint($per_page);
        $current_page = !is_numeric($current_page)  || $current_page <= 1 ? 1 : absint($current_page);

        $query_args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
        ];

        $search = sanitize_text_field(filter_input(INPUT_GET, 's'));
        if(!empty($search)) {
            $query_args['s'] = $search;
        }

        $query = new \WP_Query($query_args);
        
        $results = [];
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $record = [
                    'id'           => $post->ID,
                    'title'        => get_the_title($post),
                    'post_content' => $post->post_content,
                    'post_excerpt' => $post->post_excerpt,
                    'url'          => get_permalink($post->ID),
                    'categories'   => [],
                    'tags'         => false,
                ];
                if($post_type == 'post') {
                    $record['categories'] = get_the_category($post->ID);
                    $record['tags'] = get_the_tags($post->ID);
                }
                $results[] = $record;
            }
        }

        $records = [
            'total_pages'   => $query->max_num_pages,
            'current_page'  => $current_page,
            'per_page'      => $per_page,
            'total_records' => $query->found_posts,
            'results'       => $results
        ];

        $this->response['status'] = 1;
        $this->response['records'] = $records;
        $this->response['message'] = esc_html__('Post data fetched successfully', 'chatway');
        wp_send_json($this->response);
        exit;
    }

    /**
     * Validates a token against a stored secure token.
     *
     * This method retrieves a token from the GET request and compares it with a securely stored token.
     * If the tokens match and WooCommerce is active, it returns true; otherwise, returns false.
     *
     * @return bool True if the token matches and WooCommerce is active, false otherwise.
     */
    public function validate_token() {
        $token = sanitize_text_field(filter_input(INPUT_GET, 'token'));
        if(!empty($token)) {
            $secure_token = get_option('chatway_api_secret_license_key');
            if(!empty($secure_token) && $secure_token === $token) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetches a list of WooCommerce products based on provided search parameters.
     *
     * Retrieves products with a published status, and optionally filters them
     * based on a search query. The product data includes details such as ID, name,
     * price, SKU, description, stock status, URL, categories, thumbnail, and image.
     *
     * @return void Outputs a JSON response containing product data, status, and a success message.
     */
    public function fetch_products() {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            include_once dirname( WC_PLUGIN_FILE ) . '/includes/class-woocommerce.php';
        }
        if(class_exists('WC_Product_Query')) {
            $search = sanitize_text_field(filter_input(INPUT_GET, 's'));
            $args = [
                'status'     => 'publish',
                'post_type'  => 'product',
                'numberposts' => 50,
            ];
            if(!empty($search)) {
                $args['s'] = $search;
            }
            $records = get_posts($args);

            $default_image = wc_placeholder_img_src();

            $products = [];
            foreach ($records as $record) {
                $productData = wc_get_product($record->ID);
                $product = [];
                $product['id'] = $productData->get_id();
                $product['name'] = $productData->get_name();
                $product['price'] = $productData->get_price();
                $product['regular_price'] = $productData->get_regular_price();
                $product['currency'] = get_woocommerce_currency();
                $product['symbol'] = get_woocommerce_currency_symbol();
                $product['sku'] = $productData->get_sku();
                $product['short_description'] = $productData->get_short_description();
                $product['stock_status'] = $productData->get_stock_status();
                $product['url'] = get_permalink($productData->get_id());
                $product['type'] = $productData->get_type();
                $categories = $productData->get_category_ids();
                $image_id = $productData->get_image_id();
                $product['thumb'] = $default_image;
                $product['image'] = $default_image;
                $product['categories'] = [];
                if($image_id) {
                    $product['image'] = wp_get_attachment_url($image_id);
                    $product['thumb'] = get_the_post_thumbnail_url($product['id'], 'thumbnail');
                }

                foreach ($categories as $id) {
                    $term = get_term($id); // Get the term object for the category
                    if (!is_wp_error($term) && $term) {
                        $product['categories'][] = [
                            'name'  => $term->name,
                            'id'    => $id,
                            'url'   => get_term_link((int)$id, 'product_cat')
                        ];
                    }
                }

                // Handle variable products
                $product['variations'] = [];
                $product['attributes'] = [];
                if($productData->is_type('variable')) {
                    // Get product attributes
                    $attributes = $productData->get_variation_attributes();
                    foreach($attributes as $attribute_name => $options) {
                        $attribute_label = wc_attribute_label($attribute_name, $productData);
                        $product['attributes'][] = [
                            'name'    => $attribute_name,
                            'label'   => $attribute_label,
                            'options' => array_values($options)
                        ];
                    }

                    // Get variations
                    $variations = $productData->get_available_variations();
                    foreach($variations as $variation) {
                        $variation_obj = wc_get_product($variation['variation_id']);
                        if($variation_obj) {
                            $variation_data = [
                                'id'            => $variation['variation_id'],
                                'sku'           => $variation_obj->get_sku(),
                                'price'         => $variation_obj->get_price(),
                                'regular_price' => $variation_obj->get_regular_price(),
                                'sale_price'    => $variation_obj->get_sale_price(),
                                'stock_status'  => $variation_obj->get_stock_status(),
                                'stock_quantity'=> $variation_obj->get_stock_quantity(),
                                'is_in_stock'   => $variation_obj->is_in_stock(),
                                'attributes'    => $variation['attributes'],
                                'image'         => $product['image'],
                                'thumb'         => $product['thumb']
                            ];

                            // Get variation image
                            $variation_image_id = $variation_obj->get_image_id();
                            if($variation_image_id) {
                                $variation_data['image'] = wp_get_attachment_url($variation_image_id);
                                $variation_data['thumb'] = wp_get_attachment_image_url($variation_image_id, 'thumbnail');
                            }

                            $product['variations'][] = $variation_data;
                        }
                    }
                }

                $products[] = $product;
            }
            $this->response['status'] = 1;
            $this->response['records'] = $products;
            $this->response['message'] = esc_html__('Products fetched successfully', 'chatway');
            wp_send_json($this->response);
            exit;
        }
    }

    /**
     * Fetches WooCommerce product categories from the database and returns them in a structured array.
     * The method includes category details such as name, description, product count, parent category, and image.
     *
     * @return void Outputs JSON response with the fetched categories, their details, and a success message.
     */
    public function fetch_categories() {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            include_once dirname( WC_PLUGIN_FILE ) . '/includes/class-woocommerce.php';
        }
        if(class_exists('WC_Product_Query')) {
            $search = sanitize_text_field(filter_input(INPUT_GET, 's'));
            $args   = [
                'taxonomy'   => 'product_cat', // WooCommerce product categories
                'hide_empty' => true,          // Hide categories that do not have any products
            ];
            if(!empty($search)) {
                $args['fields']     = 'all';
                $args['name__like'] = $search;
            }
            $default_image = wc_placeholder_img_src();
            $records = get_terms($args);
            $categories = [];
            foreach ($records as $record) {
                $category = [];
                $category['id'] = $record->term_id;
                $category['name'] = $record->name;
                $category['slug'] = $record->slug;
                $category['description'] = $record->description;
                $category['products'] = $record->count;
                $category['parent'] = $record->parent;
                $category['url'] = get_term_link($record);
                $category['image'] = $default_image;

                // Get the URL of the category image
                $thumbnail_id = get_term_meta($record->term_id, 'thumbnail_id', true);
                if($thumbnail_id) {
                    $image_url = wp_get_attachment_url($thumbnail_id);
                    if($image_url) {
                        $category['image'] = $image_url;
                    }
                }
                $categories[] = $category;
            }
            $this->response['status'] = 1;
            $this->response['records'] = $categories;
            $this->response['message'] = esc_html__('Categories fetched successfully', 'chatway');
            wp_send_json($this->response);
            exit;
        }
    }

    /**
     * Fetches WooCommerce coupons from the database and returns them in a structured array.
     * The method supports an optional search functionality to filter coupons by title.
     *
     * @return void Outputs JSON response with the fetched coupons, their details, and a success message.
     */
    public function fetch_coupons() {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            include_once dirname( WC_PLUGIN_FILE ) . '/includes/class-woocommerce.php';
        }
        if ( ! class_exists( 'WC_Coupon', false ) ) {
            include_once dirname( WC_PLUGIN_FILE ) . '/includes/class-wc-coupon.php';
        }
        if(class_exists('WC_Product_Query')) {
            $search = sanitize_text_field(filter_input(INPUT_GET, 's'));
            $coupons = [];
            $args = [
                'post_type'      => 'shop_coupon', // WooCommerce coupons post type
                'posts_per_page' => 50,            // Get all coupons
                'post_status'    => 'publish',     // Only published (active) coupons
            ];
            if(!empty($search)) {
                $args['s'] = $search;
            }
            $records = get_posts($args);
            foreach ($records as $record) {
                $couponObj = new \WC_Coupon( $record->ID );
                if($couponObj->get_id()) {
                    $coupon = [];
                    $coupon['coupon_id'] = strval($couponObj->get_id());
                    $coupon['coupon_code'] = $couponObj->get_code();
                    $coupon['description'] = $couponObj->get_description();
                    $coupon['discount_type'] = $couponObj->get_discount_type();
                    $coupon['coupon_amount'] = $couponObj->get_amount();
                    $coupon['usage_limit'] = $couponObj->get_usage_limit();
                    $coupon['usage_count'] = $couponObj->get_usage_count();
                    $coupon['free_shipping'] = $couponObj->get_free_shipping();
                    $coupon['minimum_amount'] = $couponObj->get_minimum_amount();
                    $coupon['maximum_amount'] = $couponObj->get_maximum_amount();
                    $product_ids = $couponObj->get_product_ids();
                    $coupon['products'] = [];
                    $expiry_date = $couponObj->get_date_expires();
                    $coupon['timezone'] = '';
                    $coupon['expiry_date'] = '';
                    if($expiry_date) {
                        $coupon['expiry_date'] = $expiry_date->format('Y-m-d H:i:s');
                        $coupon['timezone'] = $expiry_date->getTimezone()->getName();
                    }
                    if(!empty($product_ids)) {
                        foreach ($product_ids as $id) {
                            $product = get_post($id);
                            $coupon['products'][] = [
                                'id'        => $product->ID,
                                'title'     => $product->post_title,
                                'url'       => get_permalink($product->ID),
                            ];
                        }
                    }
                    $product_ids = $couponObj->get_excluded_product_ids();
                    $coupon['excluded_products'] = [];
                    if(!empty($product_ids)) {
                        foreach ($product_ids as $id) {
                            $product = get_post($id);
                            $coupon['excluded_products'][] = [
                                'id'        => $product->ID,
                                'title'     => $product->post_title,
                                'url'       => get_permalink($product->ID),
                            ];
                        }
                    }
                    $categories = $couponObj->get_product_categories();
                    $coupon['categories'] = [];
                    if(!empty($categories)) {
                        foreach ($categories as $id) {
                            $term = get_term($id);
                            $coupon['categories'][] = [
                                'id'  => $term->term_id,
                                'name'  => $term->name,
                                'url'   => get_term_link((int)$id, 'product_cat')
                            ];
                        }
                    }
                    $categories = $couponObj->get_excluded_product_categories();
                    $coupon['excluded_categories'] = [];
                    if(!empty($categories)) {
                        foreach ($categories as $id) {
                            $term = get_term($id);
                            $coupon['excluded_categories'][] = [
                                'id'  => $term->term_id,
                                'name'  => $term->name,
                                'url'   => get_term_link((int)$id, 'product_cat')
                            ];
                        }
                    }
                    $coupons[] = $coupon;
                }
            }

            $this->response['status'] = 1;
            $this->response['records'] = $coupons;
            $this->response['message'] = esc_html__('Coupons fetched successfully', 'chatway');
            wp_send_json($this->response);
            exit;
        }
    }

    /**
     * Fetch and process orders based on a user ID provided via a GET request.
     *
     * Retrieves orders for a specific user by sanitizing and validating the user ID
     * obtained from the GET parameters. The method formats the retrieved order data
     * including details about the order and its items such as product ID, names,
     * quantities, totals, and associated images.
     *
     * @return void Outputs a JSON response with the status, fetched order records,
     *              and a success or error message. If the user ID is invalid or orders
     *              are unavailable, appropriate feedback is included in the response.
     */
    public function fetch_orders() {
        $user_id = sanitize_text_field(filter_input(INPUT_GET, 'user_id'));
        if(!empty($user_id) && is_numeric($user_id)) {
            $records = $this->fetch_orders_by_user_id($user_id);

            $orders = [];
            $total_subtotal = 0;
            $total_tax = 0;
            $final_total = 0;
            $discount = 0;
            $total_refund = 0;
            if (!empty($records)) {
                $default_image = wc_placeholder_img_src();
                foreach ($records as $record) {
                    if($record->get_status() == 'checkout-draft' || $record->get_status() == 'trash') {
                        continue;
                    }
                    $order = [];
                    $order['id'] = $record->get_id();
                    $order['subtotal'] = $record->get_subtotal();
                    $order['tax'] = (float)$record->get_total_tax();
                    $order['total'] = (float)$record->get_total() - (float)$record->get_total_refunded();
                    $order['discount'] = (float)$record->get_discount_total();
                    $order['status'] = wc_get_order_status_name($record->get_status());
                    $order['shipping_total'] =(float) $record->get_shipping_total();
                    $order['shipping_tax'] = (float)$record->get_shipping_tax();
                    $order['date'] = $record->get_date_created()->date('Y-m-d H:i:s');
                    $order['admin_url'] = admin_url('admin.php?page=wc-orders&action=edit&id='.$record->get_id());
                    $order['url'] = $record->get_view_order_url();
                    $order['billing_address'] = $record->get_address('billing');
                    $order['shipping_address'] = $record->get_address('shipping');
                    $order['payment_status'] = in_array( $record->get_payment_method(), array( 'cod', 'bacs', 'cheque' ) ) ? ( $record->get_status() === 'completed' ? 'Paid' : 'Unpaid' ) : ( $record->is_paid() ? 'Paid' : 'Unpaid' );
                    $order['payment_method'] = $record->get_payment_method_title();
                    $order['refund'] = (float)$record->get_total_refunded();
                    $order['items'] = [];
                    $items = $record->get_items();
                    $count = 0;
                    $total_subtotal += (float)$record->get_subtotal();
                    $total_tax += (float)$record->get_total_tax();
                    $final_total += (float)$record->get_total();
                    $discount += (float)$record->get_discount_total();
                    $total_refund += (float)$record->get_total_refunded();
                    foreach ($items as $item) {
                        $order['items'][$count]['product_id'] = $item->get_product_id();
                        $order['items'][$count]['product_url'] = get_permalink($item->get_product_id());
                        $order['items'][$count]['name'] = $item->get_name();
                        $order['items'][$count]['quantity'] = (float)$item->get_quantity();
                        $order['items'][$count]['subtotal'] = (float)$item->get_subtotal();
                        $order['items'][$count]['subtotal_tax'] = (float)$item->get_subtotal_tax();
                        $order['items'][$count]['total'] = (float)$item->get_total();
                        $order['items'][$count]['discount'] = (float)$item->get_subtotal() - $item->get_total();
                        $order['items'][$count]['image'] = $default_image;
                        $order['items'][$count]['thumb_image'] = $default_image;
                        $thumbnail_url = get_the_post_thumbnail_url($item->get_product_id(), 'full');
                        if(!empty($thumbnail_url)) {
                            $order['items'][$count]['image'] = $thumbnail_url;
                            $order['items'][$count]['thumb_image'] = get_the_post_thumbnail_url($item->get_product_id(), 'thumbnail');
                        }
                        $count++;
                    }
                    $orders[] = $order;
                }
            }

            $data = [
                'orders'    => count($orders),
                'subtotal'  => (float)$total_subtotal,
                'tax'       => (float)$total_tax,
                'total'     => (float)$final_total,
                'refund'     => (float)$total_refund,
                'discount'  => (float)$discount,
                'currency'  => get_woocommerce_currency(),
                'symbol'  => get_woocommerce_currency_symbol(),
            ];

            $this->response['status'] = 1;
            $this->response['records'] = [
                'orders' => $orders,
                'data' => $data,
            ];
            $this->response['message'] = esc_html__('Orders fetched successfully', 'chatway');
            wp_send_json($this->response);
            exit;
        }
    }

    /**
     * Fetch and process orders based on a user ID provided via a GET request.
     *
     * Retrieves orders for a specific user by sanitizing and validating the user ID
     * obtained from the GET parameters. The method formats the retrieved order data
     * including details about the order and its items such as product ID, names,
     * quantities, totals, and associated images.
     *
     * @return void Outputs a JSON response with the status, fetched order records,
     *              and a success or error message. If the user ID is invalid or orders
     *              are unavailable, appropriate feedback is included in the response.
     */
    public function fetch_order() {
        $email_id = sanitize_email(urldecode((string)filter_input(INPUT_GET, 'email_id')));
        $order_id = absint(filter_input(INPUT_GET, 'order_id'));
        if(empty($order_id) || empty($email_id)) {
            $this->response['status'] = 0;
            $this->response['message'] = esc_html__('Order id and email address are required', 'chatway');
            wp_send_json($this->response);
            exit;
        }
        if(!is_email($email_id)) {
            $this->response['status'] = 0;
            $this->response['message'] = esc_html__('Email address is not valid', 'chatway');
            wp_send_json($this->response);
            exit;
        }

        $record = wc_get_order( $order_id );

        if(empty($record)) {
            $this->response['status'] = 0;
            $this->response['message'] = esc_html__('Order not found', 'chatway');
            wp_send_json($this->response);
            exit;
        }

        $order_email_id = $record->get_billing_email();

        if(empty($order_email_id) || $order_email_id != $email_id) {
            $this->response['status'] = 0;
            $this->response['message'] = esc_html__('Order not found', 'chatway');
            wp_send_json($this->response);
            exit;
        }

        if ($record->get_status() == 'checkout-draft' || $record->get_status() == 'trash') {
            $this->response['status'] = 0;
            $this->response['message'] = esc_html__('Order not found', 'chatway');
            wp_send_json($this->response);
            exit;
        }

        $default_image = wc_placeholder_img_src();

        $order = [];
        $order['id'] = $record->get_id();
        $order['subtotal'] = $record->get_subtotal();
        $order['tax'] = (float)$record->get_total_tax();
        $order['total'] = (float)$record->get_total() - (float)$record->get_total_refunded();
        $order['discount'] = (float)$record->get_discount_total();
        $order['refund'] = (float)$record->get_total_refunded();
        $order['status'] = wc_get_order_status_name($record->get_status());
        $order['shipping_total'] =(float) $record->get_shipping_total();
        $order['shipping_tax'] = (float)$record->get_shipping_tax();
        $order['date'] = $record->get_date_created()->date('Y-m-d H:i:s');
        $order['admin_url'] = admin_url('admin.php?page=wc-orders&action=edit&id='.$record->get_id());
        $order['url'] = $record->get_view_order_url();
        $order['billing_address'] = $record->get_address('billing');
        $order['shipping_address'] = $record->get_address('shipping');
        $order['payment_status'] = in_array( $record->get_payment_method(), array( 'cod', 'bacs', 'cheque' ) ) ? ( $record->get_status() === 'completed' ? 'Paid' : 'Unpaid' ) : ( $record->is_paid() ? 'Paid' : 'Unpaid' );
        $order['payment_method'] = $record->get_payment_method_title();
        $order['items'] = [];
        $items = $record->get_items();
        $count = 0;
        $total_subtotal = (float)$record->get_subtotal();
        $total_tax = (float)$record->get_total_tax();
        $final_total = (float)$record->get_total() - (float)$record->get_total_refunded();
        $discount = (float)$record->get_discount_total();
        $refund_amount = (float)$record->get_total_refunded();
        foreach ($items as $item) {
            $order['items'][$count]['product_id'] = $item->get_product_id();
            $order['items'][$count]['product_url'] = get_permalink($item->get_product_id());
            $order['items'][$count]['name'] = $item->get_name();
            $order['items'][$count]['quantity'] = (float)$item->get_quantity();
            $order['items'][$count]['subtotal'] = (float)$item->get_subtotal();
            $order['items'][$count]['subtotal_tax'] = (float)$item->get_subtotal_tax();
            $order['items'][$count]['total'] = (float)$item->get_total();
            $order['items'][$count]['discount'] = (float)$item->get_subtotal() - $item->get_total();
            $order['items'][$count]['image'] = $default_image;
            $order['items'][$count]['thumb_image'] = $default_image;
            $thumbnail_url = get_the_post_thumbnail_url($item->get_product_id(), 'full');
            if(!empty($thumbnail_url)) {
                $order['items'][$count]['image'] = $thumbnail_url;
                $order['items'][$count]['thumb_image'] = get_the_post_thumbnail_url($item->get_product_id(), 'thumbnail');
            }
            $count++;
        }


        $data = [
            'subtotal'  => (float)$total_subtotal,
            'tax'       => (float)$total_tax,
            'total'     => (float)$final_total,
            'discount'  => (float)$discount,
            'refund'    => (float)$refund_amount,
            'currency'  => get_woocommerce_currency(),
            'symbol'    => get_woocommerce_currency_symbol(),
        ];

        $this->response['status'] = 1;
        $this->response['records'] = [
            'order' => $order,
            'data' => $data,
        ];
        $this->response['message'] = esc_html__('Orders fetched successfully', 'chatway');
        wp_send_json($this->response);
        exit;

    }

    /**
     * Fetch orders associated with a specific user ID.
     *
     * @param int $user_id The user ID (customer ID) for which orders should be retrieved.
     *                     Must be a numeric value.
     * @return array|string Returns an array of WC_Order objects if successful,
     *                      or a string with an error message if the user ID is invalid.
     */
    function fetch_orders_by_user_id($user_id) {
        if ( ! class_exists( 'WooCommerce', false ) ) {
            include_once dirname( WC_PLUGIN_FILE ) . '/includes/class-woocommerce.php';
        }

        $search = sanitize_text_field(filter_input(INPUT_GET, 's'));

        $args = [
            'customer_id' => $user_id,
            'status'      => 'any',
            'limit'       => -1,
        ];

        if (!empty($search)) {
            $args['s'] = sanitize_text_field($search);
            $args['search-filter'] = 'all';
            $args['_customer_user'] = '';
            $args['status'] = '';
        }

        // Query to fetch orders for the specific user ID
        $order_query = new \WC_Order_Query($args);


        $orders = $order_query->get_orders();
        return $orders; // Returns an array of WC_Order objects
    }
}