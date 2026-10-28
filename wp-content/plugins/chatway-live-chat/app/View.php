<?php
/**
 * Chatway view
 *
 * @author  : Chatway
 * @license : GPLv3
 * */

namespace Chatway\App;

class View {
    use Singleton;
    
    public function __construct() {
        add_action( 'admin_menu', [$this, 'dashboard_screen'] );
        add_action( 'admin_head', [$this, 'admin_head'] );
        add_action( 'admin_init', [$this, 'show_unread_messages_count']);
        add_filter('plugin_action_links_'.\Chatway::plugin_base(), [$this, 'plugin_action_links']);
    }

    /**
     * Adds a custom support link to the plugin action links in the WordPress plugins page.
     *
     * This method appends a 'Need help?' link pointing to the support URL to the default
     * plugin action links displayed on the plugins management page.
     *
     * @param array $links An array of existing action links for the plugin.
     * @return array Modified array of action links with the added support link.
     */
    public function plugin_action_links($links) {
        $links[] = '<a target="_blank" href="'.esc_url(\Chatway::support_url()).'">' . esc_html__( 'Need help?', 'chatway' ) . '</a>';
        return $links;
    }

    /**
     * Updates the admin menu to display the count of unread messages for the "Chatway" menu item.
     * If the current page is the "Chatway" page, it clears the transient cache for unread messages count.
     *
     * @return void
     */
    public function show_unread_messages_count() {
        global $menu;
        if((!isset($_GET['page']) || $_GET['page'] != 'chatway') && is_array($menu)) {
            foreach ($menu as $key => $value) {
                if ($value[0] == 'Chatway') {
                    $count = $this->check_for_unread_messages();
                    if(!empty($count) && $count > 0) {
                        $menu[$key][0] = $menu[$key][0] . ' <span id="chatway-unread-count" class="update-plugins count-' . esc_attr($count) . '"><span class="plugin-count" id="chatway-count">' . esc_attr($count) . '</span></span>';
                    }
                }
            }
        } else if(isset($_GET['page']) && $_GET['page'] == 'chatway') {
            delete_transient( 'chatway_unread_messages_count' );
        }
    }

    /**
     * Checks for the number of unread messages.
     * Utilizes a transient to cache the count and avoid redundant API calls.
     * If the transient is not set or expired, it fetches the count from an external API.
     *
     * @return int The number of unread messages. Returns 0 if no unread messages exist or data is unavailable.
     */
    public function check_for_unread_messages() {
        $count = get_transient( 'chatway_unread_messages_count' );
        if($count !== false) {
            return $count;
        }
        $count = ExternalApi::get_unread_messages_count();
        if(empty($count)) {
            $count = 0;
        }
        set_transient( 'chatway_unread_messages_count', $count, 5*MINUTE_IN_SECONDS );
        return $count;
    }

    /**
     * apply some css to admin head. These stylesheet will be used through out the application frontend
     * @since 1.0.0 
     */ 
    public function admin_head() {
        ?>
            <style>
                #toplevel_page_chatway .dashicons-before img {
                    opacity: 0 !important;
                }
                
                #toplevel_page_chatway .dashicons-before {
                    background-color: #A0A3A8;
                    -webkit-mask: url( <?php echo esc_url( \Chatway::url( 'assets/images/menu-icon.svg' ) ) ?> ) no-repeat center;
                    mask: url( <?php echo esc_url( \Chatway::url( 'assets/images/menu-icon.svg' ) ) ?> ) no-repeat center;
                }
                #toplevel_page_chatway:hover .dashicons-before {
                    background-color: #00b9eb;
                }

                #toplevel_page_chatway:has(.current) .dashicons-before{
                    background-color: currentColor;
                }

                @media (min-width: 961px) {
                    body:not(.folded) {
                        --wp-sidebar-width: 160px;
                    }

                    body.folded {
                        --wp-sidebar-width: 36px;
                    }
                }

                @media (max-width: 960px) and (min-width: 783px) {
                    body {
                        --wp-sidebar-width: 36px;
                    }
                }

                @media (max-width: 782px ) {
                    body {
                        --wp-sidebar-width: 0px;
                    }
                }
            </style>
        <?php
    }

    public function screen() {
        $status = ExternalApi::get_token_status();

        switch ( $status ) {
            case 'valid':
                delete_transient( 'chatway_unread_messages_count' );
                \Chatway::include_once( 'views/dashboard.php' );
                break;
            case 'invalid': 
                \Chatway::include_once( 'views/auth.php' );
                break;
            case 'server-down':
                \Chatway::include_once( 'views/error.php' );
                break;
        }
    }

    /**
     * Adds a Support submenu page to the Chatway plugin in the WordPress admin menu.
     *
     * This method registers a submenu under the Chatway admin menu, providing access to
     * the Support section where users can find assistance or additional resources related
     * to the plugin.
     *
     * @return void
     */
    public function chatway_support_submenu() {
        add_submenu_page(
            'chatway',
            esc_html__( "Support", 'chatway' ),
            esc_html__( "Support", 'chatway' ),
            'manage_options',
            'chatway-need-help',
            [$this, 'screen']
        );
    }

    /**
     * Registers the dashboard and submenu pages for the Chatway plugin in the WordPress admin menu.
     *
     * This method adds the main Chatway dashboard menu and its associated submenus,
     * including Live Chat, Full-Screen View, and Logout options. Conditional logic
     * is applied to show specific submenus based on the user's authentication status.
     *
     * @return void
     */
    public function dashboard_screen() {
        add_menu_page(
            esc_html__( "Chatway Dashboard", 'chatway' ), 
            esc_html__( "Chatway", 'chatway' ), 
            'manage_options', 
            'chatway', 
            [$this, 'screen'], 
            esc_url( \Chatway::url( 'assets/images/menu-icon.svg' ) )
        );

        add_submenu_page(
            'chatway',
            esc_html__( "Live Chat", 'chatway' ),
            esc_html__( "Live Chat", 'chatway' ),
            'manage_options',
            'chatway',
            [$this, 'screen']
        );

        if ( ! empty( get_option( 'chatway_token', '' ) ) && get_option( 'chatway_has_auth_error', '' ) != 'yes') {
            add_submenu_page(
                'chatway',
                esc_html__( "Chatway Full-Screen View", 'chatway' ), 
                esc_html__( "Full-Screen View", 'chatway' ),
                'manage_options',
                'chatway-full-screen',
                [$this, 'screen']
            );

            $this->chatway_support_submenu();
            
            add_submenu_page(
                'chatway',
                esc_html__( "Chatway Logout", 'chatway' ), 
                esc_html__( "Log Out", 'chatway' ),
                'manage_options',
                'chatway-logout',
                [$this, 'screen']
            );
        } else {
            $this->chatway_support_submenu();
        }
    }
}