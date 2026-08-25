<?php
/**
 * File:    class-flat-api-admin.php
 * Version: 3.0.0
 * Description: Admin UI. The 5 core tabs (Saved Queries, Query Builder, Endpoint
 *              Builder, Credentials, Shortcodes) are now a Svelte app (admin-src/,
 *              built to dist/admin.js + admin.css) — this file owns the AJAX/admin-post
 *              handlers those tabs call, plus the bootstrap-data assembly in
 *              enqueue_assets(). render_page() is just the mount point.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Formidable_Flat_API_Admin {

    private $option_key;
    private $queries_key;
    private $font_size_key;
    private $theme_key;

    public function __construct( $option_key, $queries_key, $font_size_key, $theme_key ) {
        $this->option_key    = $option_key;
        $this->queries_key   = $queries_key;
        $this->font_size_key = $font_size_key;
        $this->theme_key     = $theme_key;

        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_formidable_flat_regenerate_key',  [ $this, 'regenerate_key' ] );
        add_action( 'admin_post_formidable_flat_save_font_size',  [ $this, 'handle_save_font_size' ] );
        add_action( 'admin_post_formidable_flat_save_theme',      [ $this, 'handle_save_theme' ] );
        add_action( 'admin_post_formidable_flat_export_csv',      [ $this, 'handle_csv_export' ] );
        add_action( 'admin_post_formidable_flat_export_xlsx',     [ $this, 'handle_xlsx_export' ] );
        add_action( 'admin_post_formidable_flat_save_query',      [ $this, 'handle_save_query' ] );
        add_action( 'admin_post_formidable_flat_delete_query',    [ $this, 'handle_delete_query' ] );
        add_action( 'admin_post_formidable_flat_duplicate_query', [ $this, 'handle_duplicate_query' ] );
        // The DMR snapshot/lock/stats-export and canonical-mapping admin_post/wp_ajax
        // registrations moved to the DMR Reports plugin (class-dmr-admin.php) in v2.29.0.

        add_action( 'wp_ajax_ffapi_get_form_fields',   [ $this, 'ajax_get_form_fields' ] );
        add_action( 'wp_ajax_ffapi_preview_query',     [ $this, 'ajax_preview_query' ] );
        add_action( 'wp_ajax_ffapi_load_query',        [ $this, 'ajax_load_query' ] );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Enqueues the Svelte-built admin UI (v2.30.0) and localizes the bootstrap data it
     * mounts with. Built from admin-src/ via `npm run build` — dist/admin.js + admin.css
     * are committed to the repo, there is no build-on-server step (see CLAUDE.md "Admin
     * UI build step"). Runs before render_page() (admin_enqueue_scripts fires before the
     * page callback), so the API-key-generate-if-missing side effect lives here — by the
     * time render_page() runs, get_option() is guaranteed to return a real key.
     */
    public function enqueue_assets( $hook ) {
        if ( 'formidable_page_formidable-flat-api' !== $hook ) return;
        global $wpdb;

        $api_key = get_option( $this->option_key );
        if ( empty( $api_key ) ) {
            $api_key = wp_generate_password( 32, false, false );
            update_option( $this->option_key, $api_key, false );
        }

        $ver = defined( 'FRM_FLAT_VERSION' ) ? FRM_FLAT_VERSION : '2.30.0';
        $url = plugin_dir_url( __FILE__ );
        wp_enqueue_style(  'formidable-flat-admin', $url . 'dist/admin.css', [], $ver );
        wp_enqueue_script( 'formidable-flat-admin', $url . 'dist/admin.js',  [], $ver, true );

        $queries = get_option( $this->queries_key, [] );
        $forms   = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}frm_forms WHERE status = 'published' OR status IS NULL ORDER BY name ASC" );

        wp_localize_script( 'formidable-flat-admin', 'ffapiAdmin', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'adminUrl'      => admin_url( '' ),
            'restBase'      => site_url() . '/wp-json/formidable-flat/v1',
            'version'       => $ver,
            'tab'           => sanitize_key( $_GET['tab'] ?? 'queries' ),
            'queries'       => array_values( $queries ),
            'forms'         => array_map( fn( $f ) => [ 'id' => (int) $f->id, 'name' => $f->name ?: '(No Name)' ], $forms ),
            'apiKey'        => $api_key,
            'fontSize'      => (int) get_option( $this->font_size_key, 11 ),
            'theme'         => get_option( $this->theme_key, 'simple' ),
            'calcFunctions' => array_map(
                fn( $m ) => [ 'min' => $m[0], 'max' => $m[1] ],
                Formidable_Flat_Formula_Builder::FUNCTIONS
            ),
            'nonces'        => [
                'saveQuery'      => wp_create_nonce( 'save_flat_query' ),
                'deleteQuery'    => wp_create_nonce( 'delete_flat_query' ),
                'duplicateQuery' => wp_create_nonce( 'duplicate_flat_query' ),
                'regenerateKey'  => wp_create_nonce( 'regenerate_flat_key' ),
                'saveFontSize'   => wp_create_nonce( 'save_font_size' ),
                'saveTheme'      => wp_create_nonce( 'save_theme' ),
                'exportCsv'      => wp_create_nonce( 'export_flat_csv' ),
                'exportXlsx'     => wp_create_nonce( 'export_flat_xlsx' ),
                'builder'        => wp_create_nonce( 'ffapi_builder' ),
            ],
            'dmrReportsUrl' => admin_url( 'admin.php?page=dmr-reports' ),
        ] );
    }

    public function add_menu() {
        add_submenu_page( 'formidable', 'Flat API', 'Flat API', 'manage_options', 'formidable-flat-api', [ $this, 'render_page' ] );
    }

    // ============================================================
    // AJAX: Get fields for a given form (used by builder)
    // ============================================================
    public function ajax_get_form_fields() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        check_ajax_referer( 'ffapi_builder', 'nonce' );
        global $wpdb;
        $form_id  = (int) $_GET['form_id'];
        $excluded = [ 'divider', 'break', 'submit', 'end_divider', 'captcha', 'button' ];
        $not_in   = "'" . implode( "','", $excluded ) . "'";

        // Get this form's name
        $form_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}frm_forms WHERE id = %d",
            $form_id
        ) );
        $self_name = $form_row ? $form_row->name : '';

        $self_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}frm_fields
             WHERE form_id = %d AND type NOT IN ($not_in)
             ORDER BY field_order ASC",
            $form_id
        ) );
        foreach ( $self_fields as $f ) {
            $f->form_name   = $self_name;
            $f->form_id     = $form_id;
            $f->from_parent = 0;
            $f->is_system   = 0;
            $f->label       = $f->name;
        }
        // Parent fields are collected first below so they render above child fields in the UI.
        $fields = [];
        $append_system_fields = function( int $target_form_id, string $target_form_name, int $from_parent, bool $ids_only ) use ( &$fields ) {
            foreach ( Formidable_Flat_API_Engine::item_system_field_definitions( $target_form_id, $ids_only ) as $definition ) {
                $fields[] = (object) [
                    'id'            => 'item:' . $definition['source_column'],
                    'name'          => $definition['name'],
                    'label'         => $definition['label'],
                    'form_name'     => $target_form_name,
                    'form_id'       => $target_form_id,
                    'from_parent'   => $from_parent,
                    'is_system'     => 1,
                    'source_table'  => 'frm_items',
                    'source_column' => $definition['source_column'],
                    'value_kind'    => $definition['value_kind'],
                ];
            }
        };

        // Find parent form(s) — two methods:
        // 1) Direct parent_form_id column (some setups populate this)
        // 2) Reverse lookup: find repeater dividers in any form whose form_select == this form's ID
        $parent_form_ids = [];

        // Method 1: direct column
        $direct_parent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT parent_form_id FROM {$wpdb->prefix}frm_forms WHERE id = %d",
            $form_id
        ) );
        if ( $direct_parent > 0 ) {
            $parent_form_ids[] = $direct_parent;
        }

        // Method 2: reverse lookup via repeater field_options
        $repeater_parents = $wpdb->get_col( $wpdb->prepare(
            "SELECT f.form_id
             FROM {$wpdb->prefix}frm_fields f
             WHERE f.type = 'divider'
               AND f.field_options LIKE %s",
            '%s:4:"form_select";i:' . $form_id . ';%'
        ) );
        if ( ! empty( $repeater_parents ) ) {
            foreach ( $repeater_parents as $rp ) {
                $parent_form_ids[] = (int) $rp;
            }
        }

        // Deduplicate and fetch parent fields
        $parent_form_ids = array_unique( $parent_form_ids );
        foreach ( $parent_form_ids as $pfid ) {
            $parent_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}frm_forms WHERE id = %d",
                $pfid
            ) );
            if ( ! $parent_row ) continue;

            $parent_fields = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}frm_fields
                 WHERE form_id = %d AND type NOT IN ($not_in)
                 ORDER BY field_order ASC",
                $pfid
            ) );
            foreach ( $parent_fields as $pf ) {
                $pf->form_name   = $parent_row->name;
                $pf->form_id     = (int) $parent_row->id;
                $pf->from_parent = 1;
                $pf->is_system   = 0;
                $pf->label       = $pf->name;
                $fields[] = $pf;
            }

            // A parent form automatically pulled in for a child/repeater source is
            // a real frm_items metadata source, not just a collection of meta fields.
            $append_system_fields( (int) $parent_row->id, (string) $parent_row->name, 1, false );
        }

        // Append child/self fields after parents so parents show first.
        foreach ( $self_fields as $sf ) {
            $fields[] = $sf;
        }

        // A normal source form exposes its full item row. If the selected source
        // is a child/repeater, its group only needs linkage identifiers; the full
        // entry metadata belongs to the automatically included parent group above.
        $append_system_fields( $form_id, $self_name, 0, ! empty( $parent_form_ids ) );

        wp_send_json_success( $fields );
    }

    // ============================================================
    // AJAX: Preview a query definition (first 10 rows, or all for print)
    // ============================================================
    public function ajax_preview_query() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        check_ajax_referer( 'ffapi_builder', 'nonce' );

        $raw = json_decode( stripslashes( $_POST['query_json'] ?? '' ), true );
        if ( ! $raw ) wp_send_json_error( 'Invalid query JSON' );

        // 0 means "no limit" and is used by the print path, so only the positive range is
        // clamped — the builder's row-count picker offers up to 100.
        $limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 10;
        if ( $limit < 0 )   $limit = 10;
        if ( $limit > 500 ) $limit = 500;

        $rows = Formidable_Flat_API_Engine::run_saved_query( $raw, $limit );

        // Also return one full-width (unpruned) row so the builder's live formula tester can
        // resolve references to fields that aren't selected for output (e.g. a calc column
        // built from helper fields). This is admin-preview only — it never touches the
        // REST/export/report paths.
        $sample_rows = Formidable_Flat_API_Engine::run_saved_query( $raw, 1, [ 'no_prune' => true ] );
        $sample      = ! empty( $sample_rows ) ? $sample_rows[0] : null;

        wp_send_json_success( [ 'rows' => $rows, 'sample' => $sample ] );
    }

    // ============================================================
    // AJAX: Load a saved query definition into the builder
    // ============================================================
    public function ajax_load_query() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        check_ajax_referer( 'ffapi_builder', 'nonce' );
        $slug    = sanitize_key( $_GET['slug'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) {
                // Normalize any legacy "Form Name: Field Name" labels before handing the
                // definition to the builder — otherwise its checkbox/key-selector state
                // can't match against the bare field names ajax_get_form_fields() returns.
                wp_send_json_success( Formidable_Flat_API_Engine::normalize_legacy_labels( $q ) );
            }
        }
        wp_send_json_error( 'Query not found' );
    }

    // ============================================================
    // POST: Save / update a query
    // ============================================================
    public function handle_save_query() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'save_flat_query' ) ) {
            wp_die( 'Security error' );
        }

        $label      = sanitize_text_field( $_POST['query_label'] ?? '' );
        $slug       = sanitize_key( $_POST['query_slug'] ?? sanitize_title( $label ) );
        $old_slug   = sanitize_key( $_POST['old_slug'] ?? '' );

        // Prefer the single-var JSON payload written by the builder JS. It is immune to
        // max_input_vars no matter how many columns the query selects.
        $parts = $this->parse_query_payload( wp_unslash( $_POST['ffapi_payload'] ?? '' ) );

        if ( $parts === null ) {
            // No usable payload (JS disabled, or an older cached page). Fall back to the
            // individual inputs — but only if PHP actually delivered all of them. The
            // sentinel is the last var in the form; if it is missing, $_POST was truncated
            // and anything we saved would silently drop columns/filters.
            if ( empty( $_POST['ffapi_form_end'] ) ) {
                $this->die_on_truncated_post();
            }
            $parts = $this->parse_query_inputs();
        }

        $new_query = array_merge(
            [ 'slug' => $slug, 'label' => $label ],
            $parts,
            [ 'saved_at' => current_time( 'mysql' ) ]
        );

        $queries = get_option( $this->queries_key, [] );

        // Replace existing if editing, otherwise append.
        // Guard against slug collisions: when the new slug already belongs to a
        // *different* query, auto-suffix with -2 / -3 / … rather than silently
        // creating two queries that share the same slug (which breaks every
        // REST/shortcode consumer that references either of them by slug).
        $found = false;
        foreach ( $queries as &$q ) {
            if ( ( $q['slug'] ?? '' ) === $old_slug ) {
                // Detect if the new slug collides with a different existing query.
                $slug_owner = $old_slug; // the query being replaced currently owns its slug
                if ( $slug !== $old_slug ) {
                    // Slug is being changed — make sure the new one is free.
                    $taken = array_filter( $queries, fn( $x ) => ( $x['slug'] ?? '' ) === $slug && ( $x['slug'] ?? '' ) !== $old_slug );
                    if ( ! empty( $taken ) ) {
                        $base = $slug; $n = 2;
                        while ( in_array( $base . '-' . $n, array_column( $queries, 'slug' ), true ) ) $n++;
                        $slug = $base . '-' . $n;
                        $new_query['slug'] = $slug;
                    }
                }
                $q     = $new_query;
                $found = true;
                break;
            }
        }
        unset( $q );
        if ( ! $found ) {
            // New query — ensure its slug doesn't collide with any existing one.
            $existing_slugs = array_column( $queries, 'slug' );
            if ( in_array( $slug, $existing_slugs, true ) ) {
                $base = $slug; $n = 2;
                while ( in_array( $base . '-' . $n, $existing_slugs, true ) ) $n++;
                $slug = $base . '-' . $n;
                $new_query['slug'] = $slug;
            }
            $queries[] = $new_query;
        }

        update_option( $this->queries_key, $queries, false );
        $this->assert_query_saved( $slug, $new_query );

        wp_redirect( admin_url( 'admin.php?page=formidable-flat-api&tab=queries&saved=1' ) );
        exit;
    }

    /**
     * Decode + sanitize the builder's JSON payload into the saved-query parts.
     * Returns null when there is no usable payload, so the caller can fall back.
     */
    private function parse_query_payload( $raw ) {
        $raw = (string) $raw;
        if ( trim( $raw ) === '' ) return null;

        $d = json_decode( $raw, true );
        if ( ! is_array( $d ) || empty( $d['tables'] ) ) return null;

        $tables = [];
        foreach ( (array) $d['tables'] as $t ) {
            $fid = intval( $t['form_id'] ?? 0 );
            if ( $fid <= 0 ) continue;
            $k   = $t['key_field_id'] ?? 0;
            if ( is_array( $k ) ) {
                $ids = array_values( array_filter( array_map( 'intval', $k ) ) );
                // Keep backward-compatible scalar when only one key field is used.
                $k   = count( $ids ) > 1 ? $ids : ( $ids[0] ?? 0 );
            } else {
                $k = intval( $k );
            }
            $tables[] = [ 'form_id' => $fid, 'key_field_id' => $k ];
        }

        $selected = [];
        foreach ( (array) ( $d['selected_fields'] ?? [] ) as $s ) {
            $s = sanitize_text_field( (string) $s );
            if ( $s !== '' ) $selected[] = $s;
        }

        $column_order = [];
        foreach ( (array) ( $d['column_order'] ?? [] ) as $c ) {
            $lbl = sanitize_text_field( (string) ( $c['label'] ?? '' ) );
            if ( $lbl === '' ) continue;
            $column_order[] = [
                'label' => $lbl,
                'alias' => sanitize_text_field( (string) ( $c['alias'] ?? '' ) ),
            ];
        }

        $filters = [];
        foreach ( (array) ( $d['filters'] ?? [] ) as $f ) {
            $fld = sanitize_text_field( (string) ( $f['field'] ?? '' ) );
            if ( $fld === '' ) continue;
            $filters[] = [
                'field'    => $fld,
                'operator' => self::sanitize_filter_operator( $f['operator'] ?? '=' ),
                'value'    => sanitize_text_field( (string) ( $f['value'] ?? '' ) ),
            ];
        }

        $calculated_columns = [];
        foreach ( (array) ( $d['calculated_columns'] ?? [] ) as $c ) {
            $n = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
            $f = sanitize_text_field( (string) ( $c['formula'] ?? '' ) );
            if ( $n !== '' && $f !== '' ) {
                $calculated_columns[] = [ 'name' => $n, 'formula' => $f ];
            }
        }

        // Joined saved queries: [ { query_slug, left_key, right_key, match } ]
        $joins = [];
        foreach ( (array) ( $d['joins'] ?? [] ) as $j ) {
            if ( ! is_array( $j ) ) continue;
            $qs = sanitize_key( (string) ( $j['query_slug'] ?? '' ) );
            $lk = sanitize_text_field( (string) ( $j['left_key']  ?? '' ) );
            $rk = sanitize_text_field( (string) ( $j['right_key'] ?? '' ) );
            if ( $qs === '' || $lk === '' || $rk === '' ) continue;
            $joins[] = [
                'query_slug' => $qs,
                'left_key'   => $lk,
                'right_key'  => $rk,
                'match'      => ( ( $j['match'] ?? 'first' ) === 'all' ) ? 'all' : 'first',
            ];
        }

        $sort_dir = strtoupper( (string) ( $d['sort_dir'] ?? 'ASC' ) );

        return [
            'tables'             => $tables,
            'joins'              => $joins,
            'selected_fields'    => $selected,
            'column_order'       => $column_order,
            'filters'            => $filters,
            'sort_field'         => sanitize_text_field( (string) ( $d['sort_field'] ?? '' ) ),
            'sort_dir'           => in_array( $sort_dir, [ 'ASC', 'DESC' ], true ) ? $sort_dir : 'ASC',
            'calculated_columns' => $calculated_columns,
        ];
    }

    /**
     * No-JS fallback: read the saved-query parts from the individual form inputs.
     * Only safe to call once the truncation sentinel has been confirmed present.
     */
    private function parse_query_inputs() {
        // Build tables array. key_field_id may be an array of field IDs (composite key)
        // stored as a CSV string in `table_key_fid_csv[]`, one per table row.
        $tables = [];
        $form_ids  = array_map( 'intval', (array) ( $_POST['table_form_id']    ?? [] ) );
        $key_csvs  = (array) ( $_POST['table_key_fid_csv'] ?? [] );
        foreach ( $form_ids as $i => $fid ) {
            if ( $fid > 0 ) {
                $csv  = (string) ( $key_csvs[$i] ?? '' );
                $ids  = array_values( array_filter( array_map( 'intval', explode( ',', $csv ) ) ) );
                $tables[] = [
                    'form_id'      => $fid,
                    // Keep backward-compatible scalar when only one key field is used.
                    'key_field_id' => count( $ids ) > 1 ? $ids : ( $ids[0] ?? 0 ),
                ];
            }
        }

        // selected_fields: array of column label strings (checked checkboxes)
        $selected = array_map( 'sanitize_text_field', (array) ( $_POST['selected_fields'] ?? [] ) );

        // column_order: ordered array of { label, alias } from the drag-and-drop chip list
        $column_order = [];
        $co_labels    = (array) ( $_POST['col_order_label'] ?? [] );
        $co_aliases   = (array) ( $_POST['col_order_alias'] ?? [] );
        foreach ( $co_labels as $i => $lbl ) {
            $lbl = sanitize_text_field( $lbl );
            if ( $lbl === '' ) continue;
            $column_order[] = [
                'label' => $lbl,
                'alias' => sanitize_text_field( $co_aliases[$i] ?? '' ),
            ];
        }

        // filters
        $filters = [];
        $f_fields = (array) ( $_POST['filter_field']    ?? [] );
        $f_ops    = (array) ( $_POST['filter_operator'] ?? [] );
        $f_vals   = (array) ( $_POST['filter_value']    ?? [] );
        foreach ( $f_fields as $i => $ff ) {
            if ( $ff !== '' ) {
                $filters[] = [
                    'field'    => sanitize_text_field( $ff ),
                    'operator' => self::sanitize_filter_operator( $f_ops[$i] ?? '=' ),
                    'value'    => sanitize_text_field( $f_vals[$i] ?? '' ),
                ];
            }
        }

        // calculated_columns: array of { name, formula }
        $calc_names    = (array) ( $_POST['calc_col_name'] ?? [] );
        $calc_formulas = (array) ( $_POST['calc_col_formula'] ?? [] );
        $calculated_columns = [];
        foreach ( $calc_names as $i => $n ) {
            $n = sanitize_text_field( $n );
            $f = sanitize_text_field( $calc_formulas[$i] ?? '' );
            if ( $n !== '' && $f !== '' ) {
                $calculated_columns[] = [ 'name' => $n, 'formula' => $f ];
            }
        }

        // Joined saved queries (no-JS fallback path).
        $joins   = [];
        $j_slugs = (array) ( $_POST['join_query']  ?? [] );
        $j_lks   = (array) ( $_POST['join_left']   ?? [] );
        $j_rks   = (array) ( $_POST['join_right']  ?? [] );
        $j_mods  = (array) ( $_POST['join_match']  ?? [] );
        foreach ( $j_slugs as $i => $qs ) {
            $qs = sanitize_key( (string) $qs );
            $lk = sanitize_text_field( (string) ( $j_lks[ $i ] ?? '' ) );
            $rk = sanitize_text_field( (string) ( $j_rks[ $i ] ?? '' ) );
            if ( $qs === '' || $lk === '' || $rk === '' ) continue;
            $joins[] = [
                'query_slug' => $qs,
                'left_key'   => $lk,
                'right_key'  => $rk,
                'match'      => ( ( $j_mods[ $i ] ?? 'first' ) === 'all' ) ? 'all' : 'first',
            ];
        }

        return [
            'tables'             => $tables,
            'joins'              => $joins,
            'selected_fields'    => $selected,
            'column_order'       => $column_order,
            'filters'            => $filters,
            'sort_field'         => sanitize_text_field( $_POST['sort_field'] ?? '' ),
            'sort_dir'           => in_array( $_POST['sort_dir'] ?? 'ASC', [ 'ASC', 'DESC' ], true ) ? $_POST['sort_dir'] : 'ASC',
            'calculated_columns' => $calculated_columns,
        ];
    }

    /**
     * Filter operators are symbols (=, !=, >, >=, <, <=) as well as words. sanitize_key()
     * strips everything outside [a-z0-9_-], which silently turned every comparison operator
     * into an empty string. Allowlist against the exact set the builder UI offers instead.
     */
    private static function sanitize_filter_operator( $op ) {
        $allowed = [ '=', '!=', '>', '>=', '<', '<=', 'contains', 'not_empty', 'is_empty' ];
        $op = trim( (string) $op );
        return in_array( $op, $allowed, true ) ? $op : '=';
    }

    /**
     * PHP dropped part of $_POST (max_input_vars). Stop rather than write a mangled query.
     */
    private function die_on_truncated_post() {
        $limit    = (int) ini_get( 'max_input_vars' );
        $received = count( $_POST, COUNT_RECURSIVE );
        wp_die(
            '<h1>Query not saved</h1>' .
            '<p><strong>PHP truncated the form submission, so nothing was written.</strong> ' .
            'Your query was left exactly as it was — no data has been lost.</p>' .
            '<p>This query posts more input variables than PHP will accept. PHP silently ' .
            'discards the excess, which is why earlier saves appeared to succeed while the ' .
            'changes vanished.</p>' .
            '<ul>' .
            '<li><code>max_input_vars</code> limit: <strong>' . esc_html( $limit ?: 'unknown' ) . '</strong></li>' .
            '<li>Variables received: <strong>' . esc_html( $received ) . '</strong></li>' .
            '</ul>' .
            '<p>This normally cannot happen — the builder sends the whole query as a single ' .
            'variable. It means JavaScript did not run on the builder page (check the browser ' .
            'console for an error, and hard-refresh to clear a cached copy of the page).</p>' .
            '<p><a href="' . esc_url( admin_url( 'admin.php?page=formidable-flat-api&tab=queries' ) ) . '">← Back to Saved Queries</a></p>',
            'Query not saved',
            [ 'response' => 200, 'back_link' => true ]
        );
    }

    /**
     * Read the option back after writing and confirm the query actually landed. Catches a
     * rejected/failed DB write or a stale persistent object cache, either of which would
     * otherwise look exactly like a successful save.
     */
    private function assert_query_saved( $slug, array $expected ) {
        global $wpdb;

        wp_cache_delete( $this->queries_key, 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        $stored = null;
        foreach ( (array) get_option( $this->queries_key, [] ) as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) { $stored = $q; break; }
        }

        $ok = $stored
            && count( $stored['selected_fields'] ?? [] ) === count( $expected['selected_fields'] )
            && count( $stored['column_order']    ?? [] ) === count( $expected['column_order'] )
            && count( $stored['filters']         ?? [] ) === count( $expected['filters'] );

        if ( $ok ) return;

        $detail = $stored
            ? sprintf(
                'Wrote %d fields / %d columns / %d filters, but read back %d / %d / %d.',
                count( $expected['selected_fields'] ), count( $expected['column_order'] ), count( $expected['filters'] ),
                count( $stored['selected_fields'] ?? [] ), count( $stored['column_order'] ?? [] ), count( $stored['filters'] ?? [] )
            )
            : sprintf( 'The query "%s" was not found in the option after saving.', $slug );

        wp_die(
            '<h1>Query did not save</h1>' .
            '<p>The database write did not take effect. The query was <strong>not</strong> saved correctly.</p>' .
            '<p>' . esc_html( $detail ) . '</p>' .
            ( $wpdb->last_error ? '<p>Database error: <code>' . esc_html( $wpdb->last_error ) . '</code></p>' : '' ) .
            '<p><a href="' . esc_url( admin_url( 'admin.php?page=formidable-flat-api&tab=queries' ) ) . '">← Back to Saved Queries</a></p>',
            'Query did not save',
            [ 'response' => 200, 'back_link' => true ]
        );
    }

    // ============================================================
    // POST: Delete a query
    // ============================================================
    public function handle_delete_query() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'delete_flat_query' ) ) {
            wp_die( 'Security error' );
        }
        $slug    = sanitize_key( $_POST['slug'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $queries = array_values( array_filter( $queries, fn( $q ) => ( $q['slug'] ?? '' ) !== $slug ) );
        update_option( $this->queries_key, $queries, false );
        wp_redirect( admin_url( 'admin.php?page=formidable-flat-api&tab=queries&deleted=1' ) );
        exit;
    }

    // ============================================================
    // POST: Duplicate a saved query (full config copy, new slug/label)
    // ============================================================
    public function handle_duplicate_query() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'duplicate_flat_query' ) ) {
            wp_die( 'Security error' );
        }
        $slug    = sanitize_key( $_POST['slug'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $source  = null;
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) { $source = $q; break; }
        }
        if ( ! $source ) wp_die( 'Query not found.' );

        $existing_slugs = array_column( $queries, 'slug' );
        $base_slug = $slug . '-copy';
        $new_slug  = $base_slug;
        $n = 2;
        while ( in_array( $new_slug, $existing_slugs, true ) ) {
            $new_slug = $base_slug . '-' . $n;
            $n++;
        }

        $duplicate = $source;
        $duplicate['slug']     = $new_slug;
        $duplicate['label']    = ( $source['label'] ?? $slug ) . ' (Copy)';
        $duplicate['saved_at'] = current_time( 'mysql' );

        $queries[] = $duplicate;
        update_option( $this->queries_key, $queries, false );
        wp_redirect( admin_url( 'admin.php?page=formidable-flat-api&tab=builder&edit=' . $new_slug ) );
        exit;
    }

    // ============================================================
    // POST: Save print font size setting
    // ============================================================
    // The DMR snapshot/lock/stats-export handlers that used to live here moved to
    // the DMR Reports plugin (class-dmr-admin.php) in v2.29.0.
    public function handle_save_font_size() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'save_font_size' ) ) {
            wp_die( 'Security error' );
        }
        $font_size = (int) ( $_POST['print_font_size'] ?? 11 );
        if ( $font_size < 8 ) $font_size = 8;
        if ( $font_size > 24 ) $font_size = 24;
        update_option( $this->font_size_key, $font_size, false );
        wp_redirect( admin_url( 'admin.php?page=formidable-flat-api&tab=credentials&font_saved=1' ) );
        exit;
    }

    // ============================================================
    // POST: Save table theme setting
    // ============================================================
    // The canonical-mapping handlers that used to live here (handle_save_canonical_map,
    // ajax_canonical_check, ajax_canonical_save_table, handle_autodetect_canonical) moved
    // to the DMR Reports plugin (class-dmr-admin.php) in v2.29.0.
    public function handle_save_theme() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'save_theme' ) ) {
            wp_die( 'Security error' );
        }
        $valid_themes = [ 'simple', 'midnight', 'modern', 'site', 'site_dark' ];
        $theme = sanitize_key( $_POST['table_theme'] ?? 'simple' );
        if ( ! in_array( $theme, $valid_themes ) ) $theme = 'simple';
        update_option( $this->theme_key, $theme, false );
        wp_redirect( admin_url( 'admin.php?page=formidable-flat-api&tab=shortcodes&theme_saved=1' ) );
        exit;
    }

    // ============================================================
    // POST: CSV export for a saved query
    // ============================================================
    // handle_save_report_settings moved to the DMR Reports plugin (class-dmr-admin.php)
    // in v2.29.0.
    public function handle_csv_export() {
        if (
            ! current_user_can( 'manage_options' )
            || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'export_flat_csv' )
        ) {
            wp_die( 'Security error' );
        }

        $slug    = sanitize_key( $_POST['query_slug'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $query   = null;
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) { $query = $q; break; }
        }
        if ( ! $query ) wp_die( 'Query not found.' );

        $limits = Formidable_Flat_API_Security::export_limits( 'admin_csv' );
        $data   = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            $limits['max_rows'] + 1
        );
        if ( empty( $data ) ) wp_die( 'No data to export.' );

        $prepared = Formidable_Flat_API_Security::prepare_export_rows(
            $data,
            [],
            'admin_csv'
        );
        if ( is_wp_error( $prepared ) ) {
            $error_data = $prepared->get_error_data();
            wp_die(
                esc_html( $prepared->get_error_message() ),
                '',
                [ 'response' => (int) ( $error_data['status'] ?? 400 ) ]
            );
        }

        $filename = 'ffapi-' . $slug . '-' . date( 'Y-m-d_His' ) . '.csv';
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

    // ============================================================
    // POST: XLSX export for a saved query
    // ============================================================
    public function handle_xlsx_export() {
        if (
            ! current_user_can( 'manage_options' )
            || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'export_flat_xlsx' )
        ) {
            wp_die( 'Security error' );
        }

        $slug    = sanitize_key( $_POST['query_slug'] ?? '' );
        $queries = get_option( $this->queries_key, [] );
        $query   = null;
        foreach ( $queries as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) { $query = $q; break; }
        }
        if ( ! $query ) wp_die( 'Query not found.' );

        $limits = Formidable_Flat_API_Security::export_limits( 'admin_xlsx' );
        $data   = Formidable_Flat_API_Engine::run_saved_query(
            $query,
            $limits['max_rows'] + 1
        );
        if ( empty( $data ) ) wp_die( 'No data to export.' );

        $prepared = Formidable_Flat_API_Security::prepare_export_rows(
            $data,
            [],
            'admin_xlsx'
        );
        if ( is_wp_error( $prepared ) ) {
            $error_data = $prepared->get_error_data();
            wp_die(
                esc_html( $prepared->get_error_message() ),
                '',
                [ 'response' => (int) ( $error_data['status'] ?? 400 ) ]
            );
        }

        $filename = 'ffapi-' . $slug . '-' . date( 'Y-m-d_His' ) . '.xlsx';
        $writer = new Formidable_Flat_XLSX_Writer(
            $prepared['headers'],
            $prepared['rows']
        );
        $writer->output( $filename );
        exit;
    }

    // ============================================================
    // POST: Regenerate the REST API key
    // ============================================================
    public function regenerate_key() {
        if ( ! current_user_can('manage_options') || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'regenerate_flat_key' ) ) {
            wp_die('Security error');
        }
        update_option( $this->option_key, wp_generate_password(32,false,false), false );
        wp_redirect( admin_url('admin.php?page=formidable-flat-api&tab=credentials') );
        exit;
    }

    // ============================================================
    // RENDER PAGE
    // ============================================================
    public function render_page() {
        // All five tabs (Saved Queries, Query Builder, Endpoint Builder, Credentials,
        // Shortcodes) are a single Svelte app now (admin-src/, built to dist/admin.js +
        // admin.css — see enqueue_assets() for the wp_enqueue_script/wp_localize_script
        // call and CLAUDE.md "Admin UI build step" for the tooling). This method is now
        // just the mount point; every option lookup, nonce, and form/query list the app
        // needs was already assembled and localized in enqueue_assets(), which always
        // runs first (admin_enqueue_scripts fires before this page callback).
        echo '<div class="wrap"><div id="ffapi-admin-app"></div></div>';
    }
}
