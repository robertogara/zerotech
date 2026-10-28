<?php 
/**
 * Chatway external/remote APIs
 *
 * @author  : Chatway
 * @license : GPLv3
 * */

namespace Chatway\App;

class ExternalApi {
    use Singleton;

    /**
     * Checks the status of a token by making a request to a remote API.
     *
     * @return string Returns 'valid' if the token is valid, 'server-down' if the server is unreachable, or 'invalid' for other cases.
     */
    static function get_token_status() {
        $has_error = get_option('chatway_has_auth_error', '');
        if($has_error == 'yes') {
            return 'invalid';
        }

        $token    = get_option( 'chatway_token', '' );
        if(empty($token)) {
            return 'invalid';
        }

        $response = wp_remote_get( 
            Url::remote_api( "/market-apps/connected?channel=wordpress" ), 
            [
                'redirect' => 'follow',
                'headers'  => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
            ]
        ); 

        $response_code = wp_remote_retrieve_response_code( $response );

        if( 200 == $response_code ) {
            $version = get_option('chatway_wp_plugin_version', '');
            if($version != \Chatway::version()) {
                self::sync_wp_plugin_version();
            }
        }

        if( 401 == $response_code ) {
            add_option( 'chatway_has_auth_error', 'yes' );
            return 'invalid';
        }

        if ( 200 === $response_code ) return 'valid';
        if ( 521 === $response_code ) return 'server-down';

        return 'invalid';
    }

    /**
     * Send the plugin status to the Chatway server
     * @param string $status install | uninstall
     * @return boolean
     */
    static function update_plugins_status( $status = 'install' ) {

        $token      = get_option( 'chatway_token', '' );
        $user_id    = get_option( 'chatway_user_identifier', '' );
        
        if( empty( $token ) || empty( $user_id ) ) return false;

        $response = wp_remote_post(
            Url::remote_api( "/wordpress/" . $status ), 
            [
                'redirect' => 'follow',
                'headers'  => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
            ]
        );  

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 200 === $response_code ) return true;

        return false;
    }

    /**
     * Checks for the presence of a secret key in the WordPress options.
     * If the secret key is not found, it generates a new one, sends it to a remote API,
     * and saves it in the WordPress options if the remote API call is successful.
     *
     * @return void
     */
    static function sync_chatway_sercet_key() {
        $secret_key = get_option( 'chatway_api_secret_license_key', '' );
        if(empty($secret_key)) {
            $token      = get_option( 'chatway_token', '' );
            if(empty($token)) {
                return;
            }
            $has_error = get_option('chatway_has_auth_error', '');
            if($has_error == 'yes') {
                return;
            }
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // Set the version to 0100 (binary for v4)
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // Set the variant to 10xx (RFC variant)
            $secret_key = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

            $payload = [
                'site_url'  => site_url(),
                'secret_key'  => $secret_key,
            ];

            $response = wp_remote_post(
                Url::remote_api( "/wordpress-proxy-api-secret" ),
                [
                    'redirect' => 'follow',
                    'headers'  => [
                        'Accept'        => 'application/json',
                        'Authorization' => 'Bearer ' . $token
                    ],
                    'body'     => $payload
                ]
            );

            if (401 === wp_remote_retrieve_response_code( $response )) {
                add_option( 'chatway_has_auth_error', 'yes' );
            }

            if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code( $response )) {
                $response = json_decode( wp_remote_retrieve_body( $response ), true );
                if(isset($response['message']) && $response['message'] == 'Success') {
                    add_option( 'chatway_api_secret_license_key', $secret_key );
                }
            }
        }
    }

    /**
     * Retrieves the secret key for Chatway.
     *
     * This method retrieves the secret key from the WordPress options or fetches it
     * from the remote API if it is not stored in the local options. If the secret key
     * is fetched successfully from the API, it will be stored as a WordPress option.
     *
     * @return string|false The secret key as a string if found or successfully fetched,
     *                      or false if no secret key is available.
     */
    static function get_chatway_secret_key() {
        $secret_key    = get_option( 'chatway_secret_key', '' );
        if(!empty($secret_key)) {
            return $secret_key;
        }

        $has_error = get_option('chatway_has_auth_error', '');
        if($has_error == 'yes') {
            return false;
        }

        $token      = get_option( 'chatway_token', '' );
        $user_id    = get_option( 'chatway_user_identifier', '' );
        if( empty( $token ) || empty( $user_id ) ) {
            return false;
        }

        $response = wp_remote_get(
            Url::remote_api( "/visitor-identity-verification/settings" ),
            [
                'redirect' => 'follow',
                'headers'  => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
            ]
        );

        if (401 === wp_remote_retrieve_response_code( $response )) {
            add_option( 'chatway_has_auth_error', 'yes' );
            return false;
        }

        if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code( $response )) {
            $response_code = json_decode( wp_remote_retrieve_body( $response ), true );
        }

        if (isset($response_code['secret_key'])) {
            delete_option('chatway_secret_key' );
            add_option( 'chatway_secret_key', $response_code['secret_key'] );
            return $response_code['secret_key'];
        }

        return false;
    }

    /**
     * Sends visitor data to mark the visitor as verified.
     *
     * @param string $hmac The HMAC string for verification.
     * @param string $client_id The client identifier.
     * @param array $client_data The client data to send.
     * @param string $token The authorization token.
     *
     * @return array|false Returns the response code array if successful, or false if an error occurs.
     */
    static function send_visitor_data($hmac, $client_id, $client_data, $token) {
        $user_id    = get_option( 'chatway_user_identifier', '' );
        if(empty( $user_id ) ) {
            return false;
        }

        $payload = [
            'visitor'       => [
                'hmac'  => $hmac,
                'data'  => $client_data,
            ]
        ];

        $request = [
            'redirect' => 'follow',
            'headers'  => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ],
            'body'     => $payload
        ];
        $response = wp_remote_post(
            Url::remote_api( "/chat-contacts/{$client_id}/mark-as-verified" ), $request
        );

        $response_code = [];
        if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code( $response )) {
            $response_code = json_decode( wp_remote_retrieve_body( $response ), true );
        }

        return $response_code;
    }

    /**
     * Sends cart data to a remote API endpoint associated with a specific client.
     *
     * @param string $client_id The unique identifier of the client.
     * @param array $client_data The cart data to be sent to the remote API.
     * @param string $token The authentication token used for API authorization.
     *
     * @return array|bool Returns an array containing the API response on success, or false if the user identifier is not set.
     */
    static function send_cart_data($client_id, $client_data, $token) {
        $user_id    = get_option( 'chatway_user_identifier', '' );
        if(empty( $user_id ) ) {
            return false;
        }

        $payload = [
            'cart_data'   => $client_data
        ];
        $request = [
            'redirect' => 'follow',
            'headers'  => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ],
            'body'     => $payload
        ];
        $response = wp_remote_post(Url::remote_api( "/pixel/chat-contacts/{$client_id}/cart-info" ), $request);

        $response_code = [];
        if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code( $response )) {
            $response_code = json_decode( wp_remote_retrieve_body( $response ), true );
        }

        return $response_code;
    }

    /**
     * @return int The total count of unread messages. Returns 0 if the token or user identifier is missing,
     *             or if the API response is invalid or does not contain the unread count.
     */
    static function get_unread_messages_count()
    {
        $has_error = get_option('chatway_has_auth_error', '');
        if($has_error == 'yes') {
            return 0;
        }

        $token      = get_option('chatway_token', '');
        $user_id    = get_option('chatway_user_identifier', '');
        if( empty( $token ) || empty( $user_id ) ) {
            return 0;
        }

        $response = wp_remote_get(
            Url::remote_api( "/unread-notifications" ),
            [
                'redirect' => 'follow',
                'headers'  => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
            ]
        );

        if (401 === wp_remote_retrieve_response_code( $response )) {
            add_option( 'chatway_has_auth_error', 'yes' );
            return 0;
        }

        $response_code = [];
        if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code( $response )) {
            $response_code = json_decode( wp_remote_retrieve_body( $response ), true );
        }

        if(isset($response_code['total_unread_count'])) {
            return $response_code['total_unread_count'];
        }
        return 0;
    }


    /**
     * Synchronizes the WordPress plugin version with the remote API.
     *
     * This method sends the site URL and the current plugin version to a remote API for synchronization.
     * If the synchronization is successful, the plugin version is updated in the WordPress options.
     *
     * @return void The method does not return any value.
     */
    public static function sync_wp_plugin_version($status = '', $chatway_status = 1) {
        $has_error = get_option('chatway_has_auth_error', '');
        if($has_error == 'yes') {
            return;
        }

        $token      = get_option('chatway_token', '');
        $user_id    = get_option('chatway_user_identifier', '');
        if (!empty($token) && !empty($user_id)) {
            if($status == 'activated') {
                $status = 1;
            } else if ($status == 'deactivated') {
                $status = 0;
            } else {
                $status = \Chatway::is_woocomerce_active();
            }
            $request = [
                'redirect' => 'follow',
                'headers'  => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ],
                'body'     => [
                    'site_url'  => site_url(),
                    'version'  => \Chatway::version(),
                    'is_woocommerce_installed' => $status,
                    'is_active_install' => $chatway_status
                ]
            ];
            $response = wp_remote_post(Url::remote_api( "/sync-wp-plugin-version" ), $request);

            if (401 === wp_remote_retrieve_response_code( $response )) {
                add_option( 'chatway_has_auth_error', 'yes' );
                return;
            }

            $response_code = [];
            if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code( $response )) {
                $response_code = json_decode( wp_remote_retrieve_body( $response ), true );
            }

            if(isset($response_code['message']) && $response_code['message'] == 'Success') {
                update_option('chatway_wp_plugin_version', \Chatway::version());
            }

            self::sync_chatway_sercet_key();
        }
    }

    static function chatway_logout()
    {
        $token      = get_option('chatway_token', '');
        if(empty($token)) {
            return;
        }

        $request = [
            'redirect' => 'follow',
            'headers'  => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ]
        ];
        $response = wp_remote_post(Url::remote_api( "/wordpress/logout" ), $request);
        if(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            delete_option('chatway_token');
        }
    }
}