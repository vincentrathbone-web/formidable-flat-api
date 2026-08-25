<?php
/**
 * Plugin Name:       Formidable Flat API
 * Description:       Flat JSON for Power Query with Deep Repeater Merging, Natural Sorting, and Saved Query Builder.
 * Created by:        Controll IT Systems (Pty) Ltd.
 * Version:           3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FRM_FLAT_PATH',         plugin_dir_path( __FILE__ ) );
define( 'FRM_FLAT_VERSION',      '3.1.1' );
define( 'FRM_FLAT_OPTION_KEY',   'formidable_flat_api_key' );
define( 'FRM_FLAT_QUERIES_KEY',  'formidable_flat_saved_queries' );
define( 'FRM_FLAT_FONT_SIZE_KEY','formidable_flat_print_font_size' );
define( 'FRM_FLAT_THEME_KEY',    'formidable_flat_table_theme' );

// Include required files
// class-flat-api-canonical.php and class-flat-api-report.php moved to the DMR Reports
// plugin in v2.29.0 (domain-specific regulatory reporting, not generic query
// flattening) — see that plugin's PLUGIN.md changelog entry for the split rationale.
require_once FRM_FLAT_PATH . 'class-formula-builder.php';
require_once FRM_FLAT_PATH . 'class-flat-api-security.php';
require_once FRM_FLAT_PATH . 'class-xlsx-writer.php';
require_once FRM_FLAT_PATH . 'class-flat-api-engine.php';
require_once FRM_FLAT_PATH . 'class-flat-api-rest.php';

class Formidable_Flat_API {

    private $option_key    = FRM_FLAT_OPTION_KEY;
    private $queries_key   = FRM_FLAT_QUERIES_KEY;
    private $font_size_key = FRM_FLAT_FONT_SIZE_KEY;
    private $theme_key     = FRM_FLAT_THEME_KEY;

    public function __construct() {
        // Initialize REST API
        new Formidable_Flat_API_REST();

        // Admin interface
        if ( is_admin() ) {
            require_once FRM_FLAT_PATH . 'class-flat-api-admin.php';
            new Formidable_Flat_API_Admin( $this->option_key, $this->queries_key, $this->font_size_key, $this->theme_key );
        }

        // Frontend shortcodes and handlers
        // The DMR/canonical-mapping shortcodes, AJAX handlers, and blank-canvas page
        // template moved to the DMR Reports plugin in v2.29.0 (same shortcode names —
        // no breakage for pages already using them).
        add_shortcode( 'formidable_flat_button', [ $this, 'shortcode_button' ] );
        add_shortcode( 'formidable_flat_table',  [ $this, 'shortcode_table' ] );

        add_action( 'wp_ajax_ffapi_frontend_print',          [ $this, 'ajax_frontend_print' ] );
        add_action( 'wp_ajax_ffapi_frontend_csv',            [ $this, 'ajax_frontend_csv' ] );
        add_action( 'wp_ajax_ffapi_frontend_xlsx',           [ $this, 'ajax_frontend_xlsx' ] );
        add_action( 'wp_ajax_ffapi_frontend_json',           [ $this, 'ajax_frontend_json' ] );
        add_action( 'wp_ajax_ffapi_frontend_xlsx_filtered',  [ $this, 'ajax_frontend_xlsx_filtered' ] );
        
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
    }

    // ====================== ENQUEUE FRONTEND ASSETS ======================
    public function enqueue_frontend_assets() {
        if ( ! is_user_logged_in() ) return;

        $content    = get_post()->post_content ?? '';
        $has_button = has_shortcode( $content, 'formidable_flat_button' );
        $has_table  = has_shortcode( $content, 'formidable_flat_table' );

        if ( ! $has_button && ! $has_table ) return;

        $font_size = (int) get_option( $this->font_size_key, 11 );
        $theme      = get_option( $this->theme_key, 'simple' );
        // Map theme slug to CSS filename
        $theme_files = [
            'simple'    => 'tabulator_simple.min.css',
            'midnight'  => 'tabulator_midnight.min.css',
            'modern'    => 'tabulator_modern.min.css',
            'site'      => 'tabulator_site.min.css',
            'site_dark' => 'tabulator_site_dark.min.css',
        ];
        $theme_css = isset( $theme_files[ $theme ] ) ? $theme_files[ $theme ] : $theme_files['simple'];

        $localize  = [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ffapi_frontend' ),
            'fontSize' => max( 8, min( 24, $font_size ) ),
        ];

        if ( $has_button ) {
            wp_enqueue_style(  'formidable-flat-frontend', plugin_dir_url( __FILE__ ) . 'assets/frontend.css', [], FRM_FLAT_VERSION );
            wp_enqueue_script( 'formidable-flat-frontend', plugin_dir_url( __FILE__ ) . 'assets/frontend.js',  [], FRM_FLAT_VERSION, true );
            wp_localize_script( 'formidable-flat-frontend', 'ffapiFrontend', $localize );
        }

        if ( $has_table ) {
            wp_enqueue_style(  'tabulator-css',          "https://unpkg.com/tabulator-tables@5.5.4/dist/css/{$theme_css}", [], '5.5.4' );
            wp_enqueue_script( 'tabulator-js',           'https://unpkg.com/tabulator-tables@5.5.4/dist/js/tabulator.min.js',          [], '5.5.4', true );
            wp_enqueue_style(  'formidable-flat-table',  plugin_dir_url( __FILE__ ) . 'assets/table.css', [ 'tabulator-css' ], FRM_FLAT_VERSION );
            $table_deps = [ 'tabulator-js' ];
            if ( $has_button ) {
                $table_deps[] = 'formidable-flat-frontend';
            }
            wp_enqueue_script( 'formidable-flat-table',  plugin_dir_url( __FILE__ ) . 'assets/table.js', $table_deps, FRM_FLAT_VERSION, true );
            if ( ! $has_button ) {
                wp_localize_script( 'formidable-flat-table', 'ffapiFrontend', $localize );
            }
        }
    }

    // ====================== SHORTCODE: [formidable_flat_button] ======================
    public function shortcode_button( $atts ) {
        if ( ! is_user_logged_in() ) return '';

        $atts = shortcode_atts( [
            'query'  => '',
            'type'   => 'button', 
            'action' => 'print',  
            'label'  => '',       
        ], $atts );

        $query_slug = sanitize_key( $atts['query'] );
        if ( empty( $query_slug ) ) {
            return '<p style="color:red;">Error: Missing query parameter in [formidable_flat_button]</p>';
        }

        $queries = get_option( $this->queries_key, [] );
        $query_exists = false;
        $query_label = '';
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) {
                $query_exists = true;
                $query_label = $q['label'] ?? $query_slug;
                break;
            }
        }

        if ( ! $query_exists ) {
            return '<p style="color:red;">Error: Query "' . esc_html( $query_slug ) . '" not found.</p>';
        }

        $type   = in_array( $atts['type'], [ 'button', 'icon' ] ) ? $atts['type'] : 'button';
        $action = in_array( $atts['action'], [ 'print', 'csv', 'xlsx' ] ) ? $atts['action'] : 'print';
        
        if ( ! empty( $atts['label'] ) ) {
            $label = esc_html( $atts['label'] );
        } else {
            $label = $action === 'print' ? 'Print' : ( $action === 'xlsx' ? 'Export Excel' : 'Export CSV' );
        }

        $data_attrs = sprintf(
            'data-query="%s" data-action="%s" data-label="%s"',
            esc_attr( $query_slug ),
            esc_attr( $action ),
            esc_attr( $query_label )
        );

        if ( $type === 'button' ) {
            $icon = $action === 'print' ? '🖨️' : ( $action === 'xlsx' ? '📊' : '📄' );
            return sprintf(
                '<button class="ffapi-frontend-btn elementor-button elementor-size-sm" %s>%s %s</button>',
                $data_attrs,
                $icon,
                $label
            );
        } else {
            $icon = $action === 'print' ? '🖨️' : ( $action === 'xlsx' ? '📊' : '📄' );
            $title = $action === 'print' ? 'Print ' . $query_label : 'Export ' . $query_label . ' to ' . strtoupper( $action );
            return sprintf(
                '<span class="ffapi-frontend-icon" %s title="%s">%s</span>',
                $data_attrs,
                esc_attr( $title ),
                $icon
            );
        }
    }

    // ====================== SHORTCODE: [formidable_flat_table] ======================
    public function shortcode_table( $atts ) {
        if ( ! is_user_logged_in() ) return '';

        $atts = shortcode_atts( [
            'query'        => '',
            'edit_page_id' => '',
            'theme'        => 'light', // light | dark — selects the plugin's own colour token set
        ], $atts );

        // Colour scheme for the table's own chrome (header, card, rows). Every colour is
        // driven by a CSS variable, so a site can further restyle any component by
        // redefining the token in custom CSS on .ffapi-table-container.
        $table_theme = ( strtolower( (string) $atts['theme'] ) === 'dark' ) ? 'dark' : 'light';

        $query_slug = sanitize_key( $atts['query'] );
        if ( empty( $query_slug ) ) {
            return '<p style="color:red;">Error: Missing query parameter in [formidable_flat_table]</p>';
        }

        $queries     = get_option( $this->queries_key, [] );
        $query_label = '';
        $query_config = null;
        $found       = false;
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) {
                $found       = true;
                $query_label = $q['label'] ?? $query_slug;
                $query_config = $q;
                break;
            }
        }
        if ( ! $found ) {
            return '<p style="color:red;">Error: Query "' . esc_html( $query_slug ) . '" not found.</p>';
        }

        // Resolve optional edit page URL
        $edit_url     = '';
        $edit_page_id = absint( $atts['edit_page_id'] );
        if ( $edit_page_id ) {
            $permalink = get_permalink( $edit_page_id );
            if ( $permalink ) {
                $edit_url = add_query_arg( 'frm_action', 'edit', $permalink );
            }
        }

        static $table_counter = 0;
        $table_counter++;
        $uid = 'ffapi-tbl-' . $table_counter . '-' . sanitize_html_class( $query_slug );

        // Encode calculated columns config for the frontend
        $calc_cols_json = '';
        if ( ! empty( $query_config['calculated_columns'] ) ) {
            $calc_cols_json = esc_attr( wp_json_encode( $query_config['calculated_columns'] ) );
        }
        ?>
        <div class="ffapi-table-container ffapi-theme-<?php echo esc_attr( $table_theme ); ?>"
             id="<?php echo esc_attr( $uid ); ?>"
             data-query="<?php echo esc_attr( $query_slug ); ?>"
             data-label="<?php echo esc_attr( $query_label ); ?>"
             data-edit-url="<?php echo esc_url( $edit_url ); ?>"
             data-calc-cols="<?php echo $calc_cols_json; ?>">

            <div class="ffapi-tbl-toolbar">
                <div class="ffapi-tbl-search-wrap">
                    <input type="text"
                           class="ffapi-tbl-search-input"
                           placeholder="Search all columns…"
                           aria-label="Search table data">
                </div>
                <div class="ffapi-tbl-info">
                    <span class="ffapi-tbl-count"></span>
                </div>
                <div class="ffapi-tbl-actions">
                    <button class="ffapi-tbl-btn ffapi-tbl-btn-csv"   disabled>📥 CSV</button>
                    <button class="ffapi-tbl-btn ffapi-tbl-btn-xlsx"  disabled>📊 Excel</button>
                    <button class="ffapi-tbl-btn ffapi-tbl-btn-print" disabled>🖨️ Print</button>
                </div>
            </div>

            <div class="ffapi-tbl-wrapper"></div>

            <div class="ffapi-tbl-status">⏳ Loading data…</div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Require a logged-in WordPress session and the shared frontend nonce.
     *
     * This check intentionally lives inside every executable frontend callback,
     * in addition to registering authenticated AJAX actions only. A nonce is a
     * CSRF control, not authentication.
     */
    private function authorize_frontend_request() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Authentication required.', 401 );
        }

        $nonce = isset( $_POST['nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'ffapi_frontend' ) ) {
            wp_send_json_error( 'Invalid or expired security token.', 403 );
        }
    }

    // ====================== AJAX: Frontend JSON (for table view) ======================
    public function ajax_frontend_json() {
        $this->authorize_frontend_request();

        $query_slug = sanitize_key( $_POST['query'] ?? '' );
        $queries    = get_option( $this->queries_key, [] );
        $query      = null;

        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) { $query = $q; break; }
        }

        if ( ! $query ) {
            wp_send_json_error( 'Query not found' );
        }

        $limits = Formidable_Flat_API_Security::export_limits( 'frontend_json' );
        $rows   = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            $limits['max_rows'] + 1,
            [ 'preserve_edit_ids' => true ]
        );
        if ( count( $rows ) > $limits['max_rows'] ) {
            wp_send_json_error(
                sprintf( 'The table exceeds the %d-row frontend limit.', $limits['max_rows'] ),
                413
            );
        }
        wp_send_json_success( [
            'rows'  => $rows,
            'label' => $query['label'] ?? $query_slug,
        ] );
    }

    // ====================== AJAX: Frontend XLSX from filtered rows ======================
    public function ajax_frontend_xlsx_filtered() {
        $this->authorize_frontend_request();

        $query_slug = sanitize_key( $_POST['query'] ?? '' );
        $rows_input = $_POST['rows'] ?? '[]';
        if ( ! is_string( $rows_input ) ) {
            wp_die( 'Invalid export payload.', '', [ 'response' => 400 ] );
        }
        $rows_json = wp_unslash( $rows_input );

        $size_check = Formidable_Flat_API_Security::validate_request_size(
            $rows_json,
            'filtered_xlsx'
        );
        if ( is_wp_error( $size_check ) ) {
            wp_die(
                esc_html( $size_check->get_error_message() ),
                '',
                [ 'response' => 413 ]
            );
        }

        $queries = get_option( $this->queries_key, [] );
        $query   = null;
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) { $query = $q; break; }
        }
        if ( ! $query ) wp_die( 'Query not found.', '', [ 'response' => 404 ] );

        $rows = json_decode( $rows_json, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $rows ) || empty( $rows ) ) {
            wp_die( 'No valid tabular data to export.', '', [ 'response' => 400 ] );
        }

        $allowed_columns = Formidable_Flat_API_Engine::query_output_columns( $query );
        if ( empty( $allowed_columns ) ) {
            $sample = Formidable_Flat_API_Engine::run_saved_query( $query, 1 );
            if ( ! empty( $sample ) ) $allowed_columns = array_keys( $sample[0] );
        }

        $prepared = Formidable_Flat_API_Security::prepare_export_rows(
            $rows,
            $allowed_columns,
            'filtered_xlsx'
        );
        if ( is_wp_error( $prepared ) ) {
            $error_data = $prepared->get_error_data();
            wp_die(
                esc_html( $prepared->get_error_message() ),
                '',
                [ 'response' => (int) ( $error_data['status'] ?? 400 ) ]
            );
        }

        $filename = 'ffapi-' . $query_slug . '-' . date( 'Y-m-d_His' ) . '.xlsx';
        $writer   = new Formidable_Flat_XLSX_Writer(
            $prepared['headers'],
            $prepared['rows']
        );
        $writer->output( $filename );
        exit;
    }

    // ====================== AJAX: Frontend Print ======================
    public function ajax_frontend_print() {
        $this->authorize_frontend_request();
        
        $query_slug = sanitize_key( $_POST['query'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $query = null;
        
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) {
                $query = $q;
                break;
            }
        }
        
        if ( ! $query ) {
            wp_send_json_error( 'Query not found' );
        }
        
        $limits = Formidable_Flat_API_Security::export_limits( 'frontend_print' );
        $rows   = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            $limits['max_rows'] + 1
        );
        if ( count( $rows ) > $limits['max_rows'] ) {
            wp_send_json_error(
                sprintf( 'The report exceeds the %d-row frontend limit.', $limits['max_rows'] ),
                413
            );
        }
        
        $font_size = (int) get_option( $this->font_size_key, 11 );
        if ( $font_size < 8 ) $font_size = 8;
        if ( $font_size > 24 ) $font_size = 24;
        
        wp_send_json_success( [
            'rows'      => $rows,
            'label'     => $query['label'] ?? $query_slug,
            'font_size' => $font_size,
        ] );
    }

    // ====================== AJAX: Frontend CSV ======================
    public function ajax_frontend_csv() {
        $this->authorize_frontend_request();
        
        $query_slug = sanitize_key( $_POST['query'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $query = null;
        
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) {
                $query = $q;
                break;
            }
        }
        
        if ( ! $query ) {
            wp_die( 'Query not found.' );
        }
        
        $limits = Formidable_Flat_API_Security::export_limits( 'frontend_csv' );
        $data   = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            $limits['max_rows'] + 1
        );
        if ( empty( $data ) ) {
            wp_die( 'No data to export.' );
        }

        $prepared = Formidable_Flat_API_Security::prepare_export_rows(
            $data,
            [],
            'frontend_csv'
        );
        if ( is_wp_error( $prepared ) ) {
            $error_data = $prepared->get_error_data();
            wp_die(
                esc_html( $prepared->get_error_message() ),
                '',
                [ 'response' => (int) ( $error_data['status'] ?? 400 ) ]
            );
        }
        
        $filename = 'ffapi-' . $query_slug . '-' . date( 'Y-m-d_His' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        
        $out = fopen( 'php://output', 'w' );
        fputcsv(
            $out,
            array_map( [ 'Formidable_Flat_API_Security', 'csv_safe_value' ], $prepared['headers'] )
        );
        foreach ( $prepared['rows'] as $row ) {
            $line = [];
            foreach ( $prepared['headers'] as $key ) {
                $line[] = Formidable_Flat_API_Security::csv_safe_value( $row[ $key ] ?? '' );
            }
            fputcsv( $out, $line );
        }
        fclose( $out );
        exit;
    }

    // ====================== AJAX: Frontend XLSX ======================
    public function ajax_frontend_xlsx() {
        $this->authorize_frontend_request();
        
        $query_slug = sanitize_key( $_POST['query'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $query = null;
        
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $query_slug ) {
                $query = $q;
                break;
            }
        }
        
        if ( ! $query ) {
            wp_die( 'Query not found.' );
        }
        
        $limits = Formidable_Flat_API_Security::export_limits( 'frontend_xlsx' );
        $data   = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            $limits['max_rows'] + 1
        );
        if ( empty( $data ) ) {
            wp_die( 'No data to export.' );
        }

        $prepared = Formidable_Flat_API_Security::prepare_export_rows(
            $data,
            [],
            'frontend_xlsx'
        );
        if ( is_wp_error( $prepared ) ) {
            $error_data = $prepared->get_error_data();
            wp_die(
                esc_html( $prepared->get_error_message() ),
                '',
                [ 'response' => (int) ( $error_data['status'] ?? 400 ) ]
            );
        }
        
        $filename = 'ffapi-' . $query_slug . '-' . date( 'Y-m-d_His' ) . '.xlsx';
        $writer = new Formidable_Flat_XLSX_Writer(
            $prepared['headers'],
            $prepared['rows']
        );
        $writer->output( $filename );
        exit;
    }
}

new Formidable_Flat_API();
