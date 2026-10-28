<?php
/**
 * Chatway admin assets enqueue
 *
 * @author  : Chatway
 * @license : GPLv3
 * */

namespace Chatway\App;

use Chatway\App\ExternalApi;

class Assets {
    use Singleton;
    
    public function __construct() {
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_admin_assets'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_chatway'] );
    }

    /**
     * Enqueues the Chatway script if the user identifier option is not empty.
     *
     * @return void
     */
    public function enqueue_chatway() {
        $user_identifier = get_option( 'chatway_user_identifier', '' );
        if ( ! empty( $user_identifier ) ) :
            $dependencies = \Chatway::include_once( 'assets/js/app.asset.php' );
            wp_enqueue_script( "chatway-script", esc_url( Url::widget_script( $user_identifier ) ), [], $dependencies['version'] , true );
            $userId = is_user_logged_in() ? get_current_user_id(): '';
            $emailId = is_user_logged_in() ? sanitize_email( wp_get_current_user()->user_email ) : '';
            $siteUrl = get_site_url();
            $userName = '';
            if ( is_user_logged_in() ) {
                $current_user = wp_get_current_user();
                $userName = trim($current_user->user_firstname.' '.$current_user->user_lastname);
            }
            $token = '';
            if(!empty($userId) && !empty($emailId) && !empty($siteUrl)) {
                $secret_key = ExternalApi::get_chatway_secret_key();
                $data = [
                    'id'     => esc_attr($userId),
                    'email'  => esc_attr($emailId),
                ];
                $token = hash_hmac(
                    'sha256',
                    json_encode($data),
                    esc_attr($secret_key)
                );
            }
            $data = [
                'widgetId' => $user_identifier,
                'emailId'  => $emailId,
                'userId'  => $userId,
                'token' => $token,
                'userName' => $userName,
                'themeName' => wp_get_theme()->get('Name'),
            ];
            wp_localize_script( 'chatway-script', 'wpChatwaySettings',  $data );

            // Chatway Script
            $file_path = \Chatway::require( 'assets/js/frontend.asset.php', true );
            if( file_exists( $file_path ) ) {
                $file = require $file_path;
                $version = $file['version'];
                $dependencies = $file['dependencies'];
                $dependencies[] = 'jquery';

                /**
                 * enqueue admin assets
                 */
                wp_enqueue_script(
                    'chatway-frontend', \Chatway::url( 'assets/js/frontend.js' ), $dependencies, $version, [
                        'in_footer' => true,
                        'strategy'  => 'defer'
                    ]
                );

                wp_localize_script(
                    'chatway-frontend', 'chatwaySettings', [
                        'ajaxURL'  => admin_url('admin-ajax.php'),
                        'widgetId' => $user_identifier,
                        'nonce' => wp_create_nonce( 'chatway_nonce' )
                    ]
                );
            }
        endif;
    }

    public function enqueue_admin_assets() {
        /**
         * prepare dynamic dependencies 
         */
        if (!current_user_can('manage_options')) {
            return;
        }

        $file_path = \Chatway::require( 'assets/js/app.asset.php', true );
        if( file_exists( $file_path ) ) {
            $file = require $file_path;
            $version = $file['version'];
            $dependencies = $file['dependencies'];
            $dependencies[] = 'jquery';

            /**
             * enqueue admin assets 
             */ 
            wp_enqueue_style( 'chatway-fonts', \Chatway::url( 'assets/css/fonts.css' ), [], \Chatway::version(), false );
            wp_enqueue_script(
                'chatway-app', \Chatway::url( 'assets/js/app.js' ), $dependencies, $version, [
                    'in_footer' => true,
                    'strategy'  => 'defer'
                ] 
            );
            wp_enqueue_style( 'chatway-app', \Chatway::url( 'assets/css/app.css' ), [], $dependencies, false );

            wp_localize_script(
                'chatway-app', 'chatway', [
                    'images'           => \Chatway::url( 'assets/images/' ),
                    'dashboardUrl'     => Url::admin_url(),
                    'fullScreenUrl'    => Url::full_screen_url(),
                    'supportURL'       => \Chatway::support_url(),
                    'internalEndpoint' => Url::internal_api(),
                    'remoteEndpoint'   => Url::remote_api(),
                    'landingPage'      => Url::landing_page(),
                    "termsOfService"   => Url::terms_of_service(),
                    "privacyPolicy"    => Url::privacy_policy(),
                    'siteUrl'          => get_site_url(),
                ] 
            );
        } 
    }
}
