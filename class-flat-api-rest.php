<?php
/**
 * File:    class-flat-api-rest.php
 * Version: 2.5.0
 * Description: REST API route registration and callbacks. The /report route moved out
 *              to the DMR Reports plugin as part of the v2.29.0 split.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Formidable_Flat_API_REST {

    public function __construct() {
        /*
         * Private-site plugins commonly reject anonymous REST requests during
         * rest_authentication_errors, before route permission callbacks run.
         * A valid Flat API key is a complete credential for this namespace, so
         * allow it through that site-wide gate at the latest practical priority.
         */
        add_filter( 'rest_authentication_errors', [ $this, 'allow_api_key_through_site_gate' ], PHP_INT_MAX );
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Let a valid custom API key authenticate this plugin's REST namespace even
     * when the rest of the private site blocks anonymous REST requests.
     *
     * Missing or invalid keys never override another plugin's authentication
     * result and are still rejected by check_permission().
     *
     * @param null|true|WP_Error $result Existing REST authentication result.
     * @return null|true|WP_Error
     */
    public function allow_api_key_through_site_gate( $result ) {
        if ( ! $this->is_flat_api_request_uri() ) {
            return $result;
        }

        $header_key = $this->server_header( 'X-Api-Key' );
        if ( $this->is_valid_api_key( $header_key ) ) {
            $this->clear_auth_failures();
            return true;
        }

        return $result;
    }

    /**
     * Check permissions for REST API requests.
     *
     * X-Api-Key is the preferred scheme: on sites where WordPress core's
     * Application Passwords feature is available (any HTTPS site, by default
     * since WP 5.6), an `Authorization: Basic` header is intercepted by core's
     * own wp_authenticate_application_password() during current-user
     * determination — before this callback ever runs — and rejected outright
     * because the API key isn't a real WP username. That happens regardless
     * of what this method does with the header, so Basic Auth can't be relied
     * on as the sole scheme. The header check is kept for sites where it
     * still works.
     */
    public function check_permission( WP_REST_Request $request ) {
        $header_key = $request->get_header( 'X-Api-Key' );
        if ( $this->is_valid_api_key( $header_key ) ) {
            $this->clear_auth_failures();
            return true;
        }

        $api_key = get_option( FRM_FLAT_OPTION_KEY );
        $auth = $request->get_header( 'Authorization' );
        if ( $auth && strpos( $auth, 'Basic ' ) === 0 ) {
            $creds = base64_decode( substr( $auth, 6 ), true );
            if ( false !== $creds ) {
                list( $user ) = explode( ':', $creds . ':', 2 );
                if ( $api_key && hash_equals( (string) $api_key, (string) $user ) ) {
                    $this->clear_auth_failures();
                    return true;
                }
            }
        }
        return $this->record_auth_failure();
    }

    /**
     * Constant-time comparison for a candidate API key.
     */
    private function is_valid_api_key( $candidate ) {
        $api_key = (string) get_option( FRM_FLAT_OPTION_KEY, '' );
        return '' !== $api_key
            && is_string( $candidate )
            && '' !== $candidate
            && hash_equals( $api_key, $candidate );
    }

    /**
     * Read an HTTP request header before WP_REST_Request has been constructed.
     */
    private function server_header( $name ) {
        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( isset( $_SERVER[ $server_key ] ) ) {
            return trim( sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) );
        }

        $redirect_key = 'REDIRECT_' . $server_key;
        if ( isset( $_SERVER[ $redirect_key ] ) ) {
            return trim( sanitize_text_field( wp_unslash( $_SERVER[ $redirect_key ] ) ) );
        }

        return '';
    }

    /**
     * Limit the site-gate override to this plugin's REST namespace.
     */
    private function is_flat_api_request_uri() {
        $route = '';
        if ( isset( $_GET['rest_route'] ) ) {
            $route = (string) wp_unslash( $_GET['rest_route'] );
        } elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $route = (string) wp_parse_url(
                wp_unslash( $_SERVER['REQUEST_URI'] ),
                PHP_URL_PATH
            );
        }

        return (bool) preg_match(
            '#/(?:' . preg_quote( rest_get_url_prefix(), '#' ) . '/)?formidable-flat/v1(?:/|$)#',
            $route
        );
    }

    /**
     * Privacy-safe client identifier used only for failed-auth throttling.
     */
    private function auth_client_key() {
        $address = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';
        $digest = hash_hmac( 'sha256', $address, wp_salt( 'auth' ) );
        return 'ffapi_auth_fail_' . substr( $digest, 0, 32 );
    }

    /**
     * Record an invalid key attempt without storing the address or supplied key.
     */
    private function record_auth_failure() {
        $settings = apply_filters(
            'formidable_flat_api_auth_throttle',
            [
                'max_attempts' => 12,
                'window'       => 5 * MINUTE_IN_SECONDS,
            ]
        );
        $maximum = max( 1, (int) ( $settings['max_attempts'] ?? 12 ) );
        $window  = max( 60, (int) ( $settings['window'] ?? 5 * MINUTE_IN_SECONDS ) );
        $key     = $this->auth_client_key();
        $count   = (int) get_transient( $key ) + 1;

        set_transient( $key, $count, $window );
        do_action(
            'formidable_flat_api_auth_failure',
            [
                'client_hash' => substr( $key, strlen( 'ffapi_auth_fail_' ) ),
                'count'       => $count,
                'throttled'   => $count >= $maximum,
            ]
        );

        if ( $count >= $maximum ) {
            return new WP_Error(
                'ffapi_rest_too_many_attempts',
                'Too many invalid API-key attempts. Please try again later.',
                [ 'status' => 429 ]
            );
        }

        return new WP_Error(
            'ffapi_rest_unauthorized',
            'A valid Formidable Flat API key is required.',
            [ 'status' => 401 ]
        );
    }

    /**
     * A valid key clears prior failures for that client.
     */
    private function clear_auth_failures() {
        delete_transient( $this->auth_client_key() );
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route( 'formidable-flat/v1', '/form/(?P<form_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'flatten_form' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
        register_rest_route( 'formidable-flat/v1', '/view/(?P<view_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'flatten_view' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
        register_rest_route( 'formidable-flat/v1', '/merged/(?P<form_ids>[^/]+)/(?P<key_field_ids>[^/]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'flatten_merged' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
        register_rest_route( 'formidable-flat/v1', '/query/(?P<slug>[a-z0-9\-_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'flatten_query' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
        // The DMR report route (/report) moved to the DMR Reports plugin (v2.29.0 split) —
        // see class-dmr-rest.php there. Same namespace/path, so existing Power Query
        // connections and bookmarked URLs keep working unchanged as long as DMR Reports
        // is active.
    }

    /**
     * Callback for /form endpoint
     */
    public function flatten_form( WP_REST_Request $request ) {
        $form_id = (int) $request['form_id'];
        $rows    = Formidable_Flat_API_Engine::fetch_form_rows( $form_id );
        $rows    = Formidable_Flat_API_Security::enforce_rest_row_limit( $rows );
        return is_wp_error( $rows ) ? $rows : rest_ensure_response( $rows );
    }

    /**
     * Callback for /view endpoint
     */
    public function flatten_view( WP_REST_Request $request ) {
        global $wpdb;
        $view_id = (int) $request['view_id'];
        $table   = $wpdb->prefix . 'frm_views';
        
        // Check if views table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return new WP_Error( 'no_views', 'Formidable Views not installed', [ 'status' => 404 ] );
        }
        
        $view_post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $view_id ) );
        if ( ! $view_post ) {
            return new WP_Error( 'view_not_found', 'View not found', [ 'status' => 404 ] );
        }

        $form_id = (int) $view_post->form_id;
        if ( ! $form_id ) {
            return new WP_Error( 'no_form', 'View has no form', [ 'status' => 400 ] );
        }

        $rows = Formidable_Flat_API_Engine::fetch_view_rows( $view_id, $form_id );
        $rows = Formidable_Flat_API_Security::enforce_rest_row_limit( $rows );
        return is_wp_error( $rows ) ? $rows : rest_ensure_response( $rows );
    }

    /**
     * Callback for /merged endpoint
     */
    public function flatten_merged( WP_REST_Request $request ) {
        $form_ids_raw    = explode( ',', $request['form_ids'] );
        $key_field_ids_raw = explode( ',', $request['key_field_ids'] );
        $form_ids = array_map( 'intval', $form_ids_raw );
        $key_fids = array_map( 'intval', $key_field_ids_raw );

        if ( count( $form_ids ) !== count( $key_fids ) ) {
            return new WP_Error( 'param_mismatch', 'form_ids and key_field_ids counts must match', [ 'status' => 400 ] );
        }

        $merged = Formidable_Flat_API_Engine::fetch_merged_rows( $form_ids, $key_fids );
        $merged = Formidable_Flat_API_Security::enforce_rest_row_limit( $merged );
        return is_wp_error( $merged ) ? $merged : rest_ensure_response( $merged );
    }

    /**
     * Callback for /query endpoint
     */
    public function flatten_query( WP_REST_Request $request ) {
        $slug    = sanitize_key( $request['slug'] );
        $queries = get_option( FRM_FLAT_QUERIES_KEY, [] );
        $query   = null;
        
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) {
                $query = $q;
                break;
            }
        }
        
        if ( ! $query ) {
            return new WP_Error( 'not_found', 'Query not found', [ 'status' => 404 ] );
        }

        $rows = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            Formidable_Flat_API_Security::rest_max_rows() + 1
        );
        $rows = Formidable_Flat_API_Security::enforce_rest_row_limit( $rows );
        return is_wp_error( $rows ) ? $rows : rest_ensure_response( $rows );
    }
}
