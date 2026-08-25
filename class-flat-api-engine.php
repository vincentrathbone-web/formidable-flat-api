<?php
/**
 * File:    class-flat-api-engine.php
 * Version: 2.3.3
 * Description: Core data processing engine for flattening and merging Formidable Forms data.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Formidable_Flat_API_Engine {

    /**
     * Form-entry metadata exposed by the query builder.
     *
     * The stored query key is deliberately based on the immutable form ID rather
     * than the editable form name. Every value originates on the source form's
     * frm_items row; the two display-name values and status label are resolved
     * from their corresponding frm_items IDs.
     */
    public static function item_system_field_definitions( int $form_id, bool $ids_only = false ): array {
        $definitions = [
            [ 'label' => 'Created Date',    'source_column' => 'created_at',     'value_kind' => 'direct' ],
            [ 'label' => 'Updated Date',    'source_column' => 'updated_at',     'value_kind' => 'direct' ],
            [ 'label' => 'Created by',      'source_column' => 'user_id',        'value_kind' => 'resolved' ],
            [ 'label' => 'Modified by',     'source_column' => 'updated_by',     'value_kind' => 'resolved' ],
            [ 'label' => 'IP Address',      'source_column' => 'ip',             'value_kind' => 'direct' ],
            [ 'label' => 'Entry ID',        'source_column' => 'id',             'value_kind' => 'direct' ],
            [ 'label' => 'Entry Status',    'source_column' => 'is_draft',       'value_kind' => 'resolved' ],
            [ 'label' => 'Parent Entry ID', 'source_column' => 'parent_item_id', 'value_kind' => 'direct' ],
            [ 'label' => 'Form ID',         'source_column' => 'form_id',        'value_kind' => 'direct' ],
        ];

        if ( $ids_only ) {
            $definitions = array_values( array_filter(
                $definitions,
                fn( $definition ) => in_array( $definition['label'], [ 'Entry ID', 'Parent Entry ID' ], true )
            ) );
        }

        foreach ( $definitions as &$definition ) {
            $definition['name'] = self::item_system_key( $form_id, $definition['label'] );
        }
        unset( $definition );

        return $definitions;
    }

    /**
     * Stable saved-query/output key for a form-qualified frm_items value.
     */
    public static function item_system_key( int $form_id, string $label ): string {
        return sprintf( 'Form %d: %s', $form_id, $label );
    }

    /**
     * Whether a label is one of the new source-qualified frm_items keys.
     */
    private static function is_item_system_key( string $label ): bool {
        if ( ! preg_match( '/^Form (\d+): (.+)$/', $label, $matches ) ) return false;
        $valid_labels = array_column( self::item_system_field_definitions( (int) $matches[1] ), 'label' );
        return in_array( $matches[2], $valid_labels, true );
    }

    /**
     * Build all source-qualified metadata values from one source form entry.
     * No frm_item_metas timestamps or repeater-child item rows are consulted.
     */
    private static function item_system_values( array $entry, int $form_id, array $user_name_map ): array {
        $created_uid = (int) ( $entry['user_id'] ?? 0 );
        $updated_uid = (int) ( $entry['updated_by'] ?? 0 );
        $status      = match ( (int) ( $entry['is_draft'] ?? 0 ) ) {
            1       => 'Draft',
            2       => 'Abandoned',
            default => '',
        };

        $values = [
            'Created Date'        => (string) ( $entry['created_at'] ?? '' ),
            'Updated Date'        => (string) ( $entry['updated_at'] ?? '' ),
            'Created by'          => (string) ( $user_name_map[$created_uid] ?? '' ),
            'Created by User ID'  => $created_uid > 0 ? (string) $created_uid : '',
            'Modified by'         => (string) ( $user_name_map[$updated_uid] ?? '' ),
            'Modified by User ID' => $updated_uid > 0 ? (string) $updated_uid : '',
            'IP Address'          => (string) ( $entry['ip'] ?? '' ),
            'Entry ID'            => (string) ( $entry['id'] ?? '' ),
            'Entry Key'           => (string) ( $entry['item_key'] ?? '' ),
            'Entry Name'          => (string) ( $entry['name'] ?? '' ),
            'Entry Description'   => (string) ( $entry['description'] ?? '' ),
            'Entry Status'        => $status,
            'Parent Entry ID'     => ! empty( $entry['parent_item_id'] ) ? (string) $entry['parent_item_id'] : '',
            'Form ID'             => (string) ( $entry['form_id'] ?? $form_id ),
            'Post ID'             => ! empty( $entry['post_id'] ) ? (string) $entry['post_id'] : '',
        ];

        $qualified = [];
        foreach ( $values as $label => $value ) {
            $qualified[ self::item_system_key( $form_id, $label ) ] = $value;
        }
        return $qualified;
    }

    /**
     * The output column names a saved query produces — computed from the query definition,
     * WITHOUT running it. Mirrors run_saved_query()'s own projection: column_order (aliased)
     * → else selected_fields, then any calculated columns appended if not already positioned.
     *
     * Returns [] when the query selects nothing (it then emits every column and this can't be
     * known statically) — callers should treat that as "unknowable", not "no columns".
     *
     * Moved here from the DMR Reports plugin's canonical-mapping class in v2.29.0 (that split
     * moved canonical.php out of core, but this is a generic query-definition utility the core
     * query builder's join picker also depends on — it can't live somewhere optional).
     * DMR_Reports_Canonical::query_output_columns() delegates here for back-compat.
     */
    public static function query_output_columns( array $query ): array {
        $cols = [];

        if ( ! empty( $query['column_order'] ) && is_array( $query['column_order'] ) ) {
            foreach ( $query['column_order'] as $co ) {
                if ( is_array( $co ) ) {
                    $lbl = (string) ( $co['label'] ?? '' );
                    $al  = (string) ( $co['alias'] ?? '' );
                    $out = ( $al !== '' ) ? $al : $lbl;
                } else {
                    $out = (string) $co;
                }
                if ( $out !== '' && ! in_array( $out, $cols, true ) ) $cols[] = $out;
            }
        } elseif ( ! empty( $query['selected_fields'] ) && is_array( $query['selected_fields'] ) ) {
            foreach ( $query['selected_fields'] as $f ) {
                $f = (string) $f;
                if ( $f !== '' && ! in_array( $f, $cols, true ) ) $cols[] = $f;
            }
        }

        foreach ( (array) ( $query['calculated_columns'] ?? [] ) as $cc ) {
            $n = trim( (string) ( $cc['name'] ?? '' ) );
            if ( $n !== '' && ! in_array( $n, $cols, true ) ) $cols[] = $n;
        }

        return $cols;
    }

    /**
     * Back-compat shim: prior versions stored labels as "Form Name: Field Name". As of
     * v2.10.4 labels are just "Field Name". Strips any "Anything: " prefix from every
     * label reference in a saved query (selected_fields, column_order, sort_field,
     * filters) so old queries keep running — and, since v2.30.1, so the Svelte query
     * builder can load an old query for editing with labels that actually match the
     * bare field names ajax_get_form_fields() returns (mismatched labels silently broke
     * checkbox/key-selector state without erroring).
     */
    public static function normalize_legacy_labels( array $query ): array {
        $strip_prefix = function( $lbl ) {
            if ( ! is_string( $lbl ) ) return $lbl;
            if ( self::is_item_system_key( $lbl ) ) return $lbl;
            $pos = strpos( $lbl, ': ' );
            return $pos !== false ? substr( $lbl, $pos + 2 ) : $lbl;
        };
        if ( ! empty( $query['selected_fields'] ) && is_array( $query['selected_fields'] ) ) {
            $query['selected_fields'] = array_map( $strip_prefix, $query['selected_fields'] );
        }
        if ( ! empty( $query['column_order'] ) && is_array( $query['column_order'] ) ) {
            $query['column_order'] = array_map( function( $co ) use ( $strip_prefix ) {
                if ( is_array( $co ) ) {
                    $co['label'] = $strip_prefix( $co['label'] ?? '' );
                    return $co;
                }
                return $strip_prefix( $co );
            }, $query['column_order'] );
        }
        if ( ! empty( $query['sort_field'] ) ) {
            $query['sort_field'] = $strip_prefix( $query['sort_field'] );
        }
        if ( ! empty( $query['filters'] ) && is_array( $query['filters'] ) ) {
            $query['filters'] = array_map( function( $f ) use ( $strip_prefix ) {
                if ( isset( $f['field'] ) ) $f['field'] = $strip_prefix( $f['field'] );
                return $f;
            }, $query['filters'] );
        }
        return $query;
    }

    /**
     * Run a saved query configuration
     */
    public static function run_saved_query( array $query, int $limit = 0, array $opts = [] ): array {
        $tables = $query['tables'] ?? [];
        if ( empty( $tables ) ) return [];

        $query = self::normalize_legacy_labels( $query );

        $selected = $query['selected_fields'] ?? [];
        $include_drafts = in_array( 'Draft Status', $selected, true );
        if ( ! $include_drafts ) {
            foreach ( $selected as $selected_field ) {
                if ( preg_match( '/^Form \d+: Entry Status$/', (string) $selected_field ) ) {
                    $include_drafts = true;
                    break;
                }
            }
        }

        // Fetch raw flat rows directly via internal DB methods
        if ( count( $tables ) === 1 ) {
            $rows = self::fetch_form_rows( (int) $tables[0]['form_id'], $include_drafts, $selected );
        } else {
            $form_ids = array_map( fn($t) => (int) $t['form_id'],      $tables );
            $key_fids = array_map( fn($t) => (int) $t['key_field_id'], $tables );
            $rows     = self::fetch_merged_rows( $form_ids, $key_fids, $include_drafts );
        }

        if ( empty( $rows ) ) return [];

        // --- Joined saved queries ---
        // Pull columns in from OTHER saved queries, matched on a shared column. Applied right
        // after the fetch and before everything else, so the joined columns behave exactly like
        // native ones: selectable, filterable, sortable, and usable in calculated columns.
        //
        // This exists because a client's Formidable forms can't always be changed, so the fields
        // a report needs are spread across several queries — this is how you gather them into one.
        if ( ! empty( $query['joins'] ) && is_array( $query['joins'] ) ) {
            $rows = self::apply_query_joins( $rows, $query['joins'] );
            if ( empty( $rows ) ) return [];
        }

        // --- Filtering ---
        $filters = $query['filters'] ?? [];
        if ( ! empty( $filters ) ) {
            $rows = array_values( array_filter( $rows, function( $row ) use ( $filters ) {
                foreach ( $filters as $f ) {
                    $col  = $f['field']    ?? '';
                    $op   = $f['operator'] ?? '=';
                    $val  = $f['value']    ?? '';
                    $cell = (string) ( $row[$col] ?? '' );
                    $pass = match ( $op ) {
                        '='         => strcasecmp( $cell, $val ) === 0,
                        '!='        => strcasecmp( $cell, $val ) !== 0,
                        '>'         => is_numeric($cell) && is_numeric($val) ? (float)$cell >  (float)$val : strcmp($cell,$val) >  0,
                        '>='        => is_numeric($cell) && is_numeric($val) ? (float)$cell >= (float)$val : strcmp($cell,$val) >= 0,
                        '<'         => is_numeric($cell) && is_numeric($val) ? (float)$cell <  (float)$val : strcmp($cell,$val) <  0,
                        '<='        => is_numeric($cell) && is_numeric($val) ? (float)$cell <= (float)$val : strcmp($cell,$val) <= 0,
                        'contains'  => stripos( $cell, $val ) !== false,
                        'not_empty' => $cell !== '',
                        'is_empty'  => $cell === '',
                        default     => true,
                    };
                    if ( ! $pass ) return false;
                }
                return true;
            } ) );
        }

        // --- Sorting ---
        $sort_field = $query['sort_field'] ?? '';
        $sort_dir   = strtoupper( $query['sort_dir'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
        if ( $sort_field ) {
            usort( $rows, function( $a, $b ) use ( $sort_field, $sort_dir ) {
                $va  = (string) ( $a[$sort_field] ?? '' );
                $vb  = (string) ( $b[$sort_field] ?? '' );
                $cmp = strnatcasecmp( $va, $vb );
                return $sort_dir === 'DESC' ? -$cmp : $cmp;
            } );
        }

        // column_order: array of { label, alias } — overrides $selected ordering and
        // renames output keys to the alias when set. Backward compatible: if absent,
        // we fall back to $selected order with original labels.
        $column_order = $query['column_order'] ?? [];
        $order_map    = []; // label => alias (or label if blank)
        if ( ! empty( $column_order ) && is_array( $column_order ) ) {
            foreach ( $column_order as $co ) {
                $lbl = is_array( $co ) ? ( $co['label'] ?? '' ) : (string) $co;
                $al  = is_array( $co ) ? ( $co['alias'] ?? '' ) : '';
                if ( $lbl === '' ) continue;
                $order_map[$lbl] = ( $al !== '' ) ? $al : $lbl;
            }
        }

        // --- Calculated Columns ---
        // Evaluated on the FULL row set, BEFORE column pruning, so a formula may reference
        // ANY field produced by the source form(s) — not only the columns kept in the output.
        // This lets helper inputs (e.g. Flowrate 1/2/3) feed a calc column without themselves
        // appearing in the final report. Calc results are always retained in the output
        // (appended at the far right, as before). Historically calc ran AFTER pruning, so this
        // is a strict superset: formulas that referenced selected/aliased columns still resolve.
        $calc_cols  = $query['calculated_columns'] ?? [];
        $calc_names = [];
        if ( ! empty( $calc_cols ) && ! empty( $rows ) ) {
            // Back-compat: because calc used to run after aliasing, a saved formula could
            // reference a column by its alias. Expose both the original label and any alias
            // during evaluation so old and new queries both resolve.
            $alias_pairs = [];
            foreach ( $order_map as $lbl => $al ) {
                if ( $al !== $lbl ) $alias_pairs[ $lbl ] = $al;
            }
            if ( ! empty( $alias_pairs ) ) {
                foreach ( $rows as &$r ) {
                    foreach ( $alias_pairs as $lbl => $al ) {
                        if ( array_key_exists( $lbl, $r ) && ! array_key_exists( $al, $r ) ) {
                            $r[ $al ] = $r[ $lbl ];
                        }
                    }
                }
                unset( $r );
            }

            // Rows are mutated in place; errors come back as a clean list so
            // they never leak into the output stream or corrupt row keys.
            $calc_result = Formidable_Flat_Formula_Builder::evaluate_calculated_columns( $rows, $calc_cols );
            if ( ! empty( $calc_result['errors'] ) && ! empty( $opts['collect_calc_errors'] ) ) {
                $opts['calc_errors_out'] = $calc_result['errors']; // consumed by caller if interested
            }

            foreach ( $calc_cols as $cc ) {
                $nm = isset( $cc['name'] )    ? trim( (string) $cc['name'] )    : '';
                $fm = isset( $cc['formula'] ) ? trim( (string) $cc['formula'] ) : '';
                if ( $nm !== '' && $fm !== '' ) $calc_names[] = $nm;
            }
            $calc_names = array_values( array_unique( $calc_names ) );
        }

        // Preview/tester path: return the full-width rows (all source fields, any alias
        // duplicates, and the calc columns) without pruning, so the builder's live formula
        // tester can resolve references to fields that aren't in the final output.
        if ( ! empty( $opts['no_prune'] ) ) {
            if ( $limit > 0 ) $rows = array_slice( $rows, 0, $limit );
            return $rows;
        }

        // --- Column pruning + ordering + aliasing ---
        // Calc columns are appended to the retained set so they survive pruning and land at
        // the far right of the output — even though they are not in selected_fields /
        // column_order.
        $preserve_edit_ids = ! empty( $opts['preserve_edit_ids'] );
        if ( ! empty( $order_map ) ) {
            foreach ( $calc_names as $nm ) { if ( ! isset( $order_map[ $nm ] ) ) $order_map[ $nm ] = $nm; }
            $rows = array_map( function( $row ) use ( $order_map, $preserve_edit_ids ) {
                $pruned = [];
                foreach ( $order_map as $src_label => $out_label ) {
                    $pruned[$out_label] = $row[$src_label] ?? '';
                }
                if ( $preserve_edit_ids ) {
                    $pruned['_ffapi_parent_id'] = $row['Parent_ID'] ?? '';
                    $pruned['_ffapi_child_id']  = $row['Child_ID']  ?? '';
                }
                return $pruned;
            }, $rows );
        } elseif ( ! empty( $selected ) ) {
            $sel_out = $selected;
            foreach ( $calc_names as $nm ) { if ( ! in_array( $nm, $sel_out, true ) ) $sel_out[] = $nm; }
            $rows = array_map( function( $row ) use ( $sel_out, $preserve_edit_ids ) {
                $pruned = [];
                foreach ( $sel_out as $col ) {
                    $pruned[$col] = $row[$col] ?? '';
                }
                // Inject hidden edit-id keys so the front-end table edit link
                // can resolve the target entry regardless of user column choice.
                if ( $preserve_edit_ids ) {
                    $pruned['_ffapi_parent_id'] = $row['Parent_ID'] ?? '';
                    $pruned['_ffapi_child_id']  = $row['Child_ID']  ?? '';
                }
                return $pruned;
            }, $rows );
        } elseif ( $preserve_edit_ids ) {
            // No pruning in effect — still add the hidden keys for consistency.
            foreach ( $rows as &$row ) {
                $row['_ffapi_parent_id'] = $row['Parent_ID'] ?? '';
                $row['_ffapi_child_id']  = $row['Child_ID']  ?? '';
            }
            unset( $row );
        }

        // --- Limit ---
        if ( $limit > 0 ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Joining other saved queries
    // ---------------------------------------------------------------------

    /** Guards against a query joining itself, directly or in a cycle. */
    private static $join_stack = [];

    /** How deep a chain of query→query joins may nest before we stop. */
    const MAX_JOIN_DEPTH = 3;

    /**
     * Join other saved queries into the current row set.
     *
     * Each join is: [ 'query_slug', 'left_key', 'right_key', 'match' => 'first'|'all' ].
     *
     * Semantics, deliberately kept simple and predictable:
     *  - LEFT JOIN: a base row with no match is KEPT (its joined columns are simply absent),
     *    so a join can never silently delete rows from your query.
     *  - match=first (default): take the first matching row — the 1:1 case (e.g. post-weights
     *    per sample). Row count is unchanged.
     *  - match=all: emit one output row per match — the 1:many case (e.g. pollutant results,
     *    which have several rows per sample). Row count grows.
     *  - Column collisions: the base row wins; the incoming column is prefixed with the joined
     *    query's label ("Post-weights: Sample ID"). Nothing is ever overwritten or lost.
     *  - Keys are matched case-insensitively with whitespace collapsed, because the same sample
     *    id is rarely typed identically across two forms.
     */
    private static function apply_query_joins( array $rows, array $joins ): array {
        foreach ( $joins as $j ) {
            if ( ! is_array( $j ) ) continue;

            $slug  = trim( (string) ( $j['query_slug'] ?? '' ) );
            $lkey  = trim( (string) ( $j['left_key']   ?? '' ) );
            $rkey  = trim( (string) ( $j['right_key']  ?? '' ) );
            $mode  = ( ( $j['match'] ?? 'first' ) === 'all' ) ? 'all' : 'first';
            if ( $slug === '' || $lkey === '' || $rkey === '' ) continue;

            // Cycle / runaway-nesting guards. Skipping (rather than dying) keeps a bad config
            // from taking the whole site down — the column simply won't appear.
            if ( in_array( $slug, self::$join_stack, true ) ) continue;
            if ( count( self::$join_stack ) >= self::MAX_JOIN_DEPTH ) continue;

            $jq = self::find_saved_query( $slug );
            if ( ! $jq ) continue;

            self::$join_stack[] = $slug;
            // Run the joined query normally, so what we get back is exactly its OUTPUT columns
            // (post-selection, post-alias, post-calculated-columns) — i.e. what the builder shows.
            $right = self::run_saved_query( $jq );
            array_pop( self::$join_stack );

            if ( empty( $right ) ) continue;

            $label = trim( (string) ( $jq['label'] ?? $slug ) );

            // Index the joined rows by their key.
            $idx = [];
            foreach ( $right as $rr ) {
                if ( ! is_array( $rr ) ) continue;
                $k = self::join_key( $rr[ $rkey ] ?? '' );
                if ( $k === '' ) continue;
                $idx[ $k ][] = $rr;
            }
            if ( empty( $idx ) ) continue;

            $out = [];
            foreach ( $rows as $row ) {
                $k       = self::join_key( $row[ $lkey ] ?? '' );
                $matches = ( $k !== '' && isset( $idx[ $k ] ) ) ? $idx[ $k ] : [];

                if ( empty( $matches ) ) { $out[] = $row; continue; }   // LEFT JOIN: keep it

                if ( $mode === 'first' ) {
                    $out[] = self::merge_joined( $row, $matches[0], $label );
                } else {
                    foreach ( $matches as $m ) $out[] = self::merge_joined( $row, $m, $label );
                }
            }
            $rows = $out;
        }

        return $rows;
    }

    /** Normalised join key — case-insensitive, whitespace-collapsed. */
    private static function join_key( $v ): string {
        if ( is_array( $v ) ) $v = implode( ',', $v );
        return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $v ) ) );
    }

    /** Merge a joined row's columns into a base row. Base wins; collisions get label-prefixed. */
    private static function merge_joined( array $base, array $join, string $label ): array {
        foreach ( $join as $col => $val ) {
            $col = (string) $col;
            if ( $col === '' || $col[0] === '_' ) continue; // skip internal keys (_ffapi_*)
            $out = array_key_exists( $col, $base ) ? ( $label . ': ' . $col ) : $col;
            $base[ $out ] = $val;
        }
        return $base;
    }

    /** Look up a saved query by slug. */
    public static function find_saved_query( string $slug ) {
        if ( $slug === '' || ! function_exists( 'get_option' ) ) return null;
        $key = defined( 'FRM_FLAT_QUERIES_KEY' ) ? FRM_FLAT_QUERIES_KEY : 'formidable_flat_saved_queries';
        foreach ( (array) get_option( $key, [] ) as $q ) {
            if ( ( $q['slug'] ?? '' ) === $slug ) return $q;
        }
        return null;
    }

    /**
     * Fetch view rows and flatten them
     */
    public static function fetch_view_rows( int $view_id, int $form_id, bool $include_drafts = false ): array {
        global $wpdb;
        $excluded_types = [ 'submit', 'break', 'button', 'captcha', 'end_divider' ];

        $all_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, type, field_options FROM {$wpdb->prefix}frm_fields
             WHERE form_id = %d AND type NOT IN ('" . implode( "','", $excluded_types ) . "')",
            $form_id
        ) );

        $field_map = []; $repeater_map = []; $child_form_ids = [];
        foreach ( $all_fields as $f ) {
            $f_opts = maybe_unserialize( $f->field_options );
            if ( $f->type === 'divider' && isset( $f_opts['repeat'] ) && $f_opts['repeat'] == '1' ) {
                $repeater_map[ (int) $f->id ] = (int) $f_opts['form_select'];
                $child_form_ids[]              = (int) $f_opts['form_select'];
            } else {
                $field_map[ (int) $f->id ] = $f->name;
            }
        }

        $child_field_map = [];
        if ( ! empty( $child_form_ids ) ) {
            $child_form_ids = array_unique( $child_form_ids );
            $placeholders   = implode( ',', array_fill( 0, count( $child_form_ids ), '%d' ) );
            $c_fields       = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}frm_fields
                 WHERE form_id IN ($placeholders) AND type NOT IN ('" . implode( "','", $excluded_types ) . "')",
                $child_form_ids
            ) );
            foreach ( $c_fields as $cf ) { $child_field_map[ (int) $cf->id ] = $cf->name; }
        }

        $query = "SELECT id, item_key, name, description, ip, form_id, post_id, user_id, parent_item_id, is_draft, updated_by, created_at, updated_at FROM {$wpdb->prefix}frm_items WHERE form_id = %d";
        if ( ! $include_drafts ) {
            $query .= " AND is_draft = 0";
        }
        $query .= " ORDER BY id DESC";

        $entries = $wpdb->get_results( $wpdb->prepare( $query, $form_id ), ARRAY_A );
        if ( empty( $entries ) ) return [];

        $id_list   = implode( ',', array_column( $entries, 'id' ) );
        $metas_raw = $wpdb->get_results( "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($id_list)", ARRAY_A );
        $parent_metas = []; $all_child_entry_ids = [];
        $draft_map    = [];
        $parent_item_map = [];
        $updated_by_map  = [];
        $creator_map     = [];
        $updated_at_map  = [];
        foreach ( $entries as $e ) {
            $draft_map[$e['id']]       = (int) $e['is_draft'];
            $parent_item_map[$e['id']] = (int) $e['parent_item_id'];
            $updated_by_map[$e['id']]  = (int) $e['updated_by'];
            $creator_map[$e['id']]     = (int) $e['user_id'];
            $updated_at_map[$e['id']]  = $e['updated_at'];
        }
        $user_name_map = self::resolve_user_names( $updated_by_map ) + self::resolve_user_names( $creator_map );

        foreach ( $metas_raw as $m ) {
            $val = maybe_unserialize( $m['meta_value'] );
            $fid = (int) $m['field_id'];
            $parent_metas[ $m['item_id'] ][ $fid ] = $val;
            if ( isset( $repeater_map[ $fid ] ) && is_array( $val ) ) {
                $all_child_entry_ids = array_merge( $all_child_entry_ids, $val );
            }
        }

        $child_data_map = [];
        $child_draft_map = [];
        $child_updated_by_map = [];
        $child_creator_map    = [];
        $child_updated_at_map = [];
        if ( ! empty( $all_child_entry_ids ) ) {
            $all_child_entry_ids = array_filter( array_unique( $all_child_entry_ids ) );
            if ( ! empty( $all_child_entry_ids ) ) {
                $c_id_list   = implode( ',', array_map( 'intval', $all_child_entry_ids ) );
                $c_metas_raw = $wpdb->get_results( "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($c_id_list)", ARRAY_A );
                foreach ( $c_metas_raw as $cm ) {
                    $child_data_map[ $cm['item_id'] ][ (int) $cm['field_id'] ] = maybe_unserialize( $cm['meta_value'] );
                }

                $c_entries = $wpdb->get_results( "SELECT id, is_draft, updated_by, user_id, updated_at FROM {$wpdb->prefix}frm_items WHERE id IN ($c_id_list)", ARRAY_A );
                foreach ( $c_entries as $ce ) {
                    $child_draft_map[$ce['id']]      = (int) $ce['is_draft'];
                    $child_updated_by_map[$ce['id']] = (int) $ce['updated_by'];
                    $child_creator_map[$ce['id']]    = (int) $ce['user_id'];
                    $child_updated_at_map[$ce['id']] = $ce['updated_at'];
                }
                $user_name_map = $user_name_map + self::resolve_user_names( $child_updated_by_map ) + self::resolve_user_names( $child_creator_map );
            }
        }

        // Parent form context (when the view's form is a child/repeater sub-form)
        $parent_ctx           = self::get_parent_form_context( $form_id );
        $parent_form_metas    = [];
        $parent_form_items    = [];
        $parent_user_name_map = [];
        if ( $parent_ctx ) {
            $uniq_parent_ids   = array_unique( array_filter( array_values( $parent_item_map ) ) );
            $parent_form_metas = self::load_parent_metas( $uniq_parent_ids, $parent_ctx['field_map'] );
            $parent_form_items = self::load_item_rows( $uniq_parent_ids );
            $parent_user_name_map = self::resolve_item_user_names( $parent_form_items );
        }

        $expanded_rows = [];
        foreach ( $entries as $entry ) {
            $p_id            = $entry['id'];
            $db_parent_id    = $parent_item_map[$p_id];
            $entry_child_ids = [];
            foreach ( $repeater_map as $r_id => $c_form_id ) {
                if ( isset( $parent_metas[ $p_id ][ $r_id ] ) && is_array( $parent_metas[ $p_id ][ $r_id ] ) ) {
                    $entry_child_ids = array_unique( array_merge( $entry_child_ids, $parent_metas[ $p_id ][ $r_id ] ) );
                }
            }
            if ( empty( $entry_child_ids ) ) $entry_child_ids = [ 0 ];
            foreach ( $entry_child_ids as $c_id ) {
                $p_status = $draft_map[$p_id];
                $c_status = ( $c_id !== 0 && isset( $child_draft_map[$c_id] ) ) ? $child_draft_map[$c_id] : 0;

                // If we don't include drafts, and either parent or child is a draft, skip the row
                if ( ! $include_drafts && ( $p_status != 0 || $c_status != 0 ) ) {
                    continue;
                }

                $status_val = max( $p_status, $c_status );
                $status_label = match( $status_val ) {
                    1 => 'Draft',
                    2 => 'Abandoned',
                    default => '',
                };

                // Last Modified By / Created by / Updated date: prefer the child entry's own
                // value when this is a repeater row, fall back to the parent entry's.
                $uid = ( $c_id !== 0 && ! empty( $child_updated_by_map[$c_id] ) )
                       ? $child_updated_by_map[$c_id]
                       : $updated_by_map[$p_id];
                $last_modified_by = $user_name_map[$uid] ?? '';

                $creator_uid = ( $c_id !== 0 && ! empty( $child_creator_map[$c_id] ) )
                       ? $child_creator_map[$c_id]
                       : $creator_map[$p_id];
                $created_by = $user_name_map[$creator_uid] ?? '';

                $updated_date = ( $c_id !== 0 && ! empty( $child_updated_at_map[$c_id] ) )
                       ? $child_updated_at_map[$c_id]
                       : $updated_at_map[$p_id];

                $entry_id_val = ( $c_id == 0 ) ? $p_id : $c_id;

                $row = [
                    'Draft Status'     => $status_label,
                    'Parent_ID'        => ( $db_parent_id > 0 ) ? $db_parent_id : $p_id,
                    'Child_ID'         => $entry_id_val,
                    'Created_At'       => $entry['created_at'],
                    'Last Modified By' => $last_modified_by,
                    // New user-facing system column names (v2.28.3) — aliases of the above
                    // plus two genuinely new ones (Created by / Updated date) backed by
                    // frm_items.user_id / updated_at, which weren't queried before.
                    'Created by'       => $created_by,
                    'Modified by'      => $last_modified_by,
                    'Created Date'     => $entry['created_at'],
                    'Updated date'     => $updated_date,
                    // Formidable's DB has no separate "timestamp" concept — updated_at is
                    // already the most-recent-activity marker (set on insert, bumped on edit).
                    'Timestamp'        => $updated_date,
                    'Entry ID'         => $entry_id_val,
                ];
                $row = array_merge( $row, self::item_system_values( $entry, $form_id, $user_name_map ) );
                if ( $parent_ctx && $db_parent_id > 0 && isset( $parent_form_items[$db_parent_id] ) ) {
                    $row = array_merge(
                        $row,
                        self::item_system_values( $parent_form_items[$db_parent_id], $parent_ctx['form_id'], $parent_user_name_map )
                    );
                }
                foreach ( $field_map as $fid => $label ) {
                    $val         = $parent_metas[ $p_id ][ $fid ] ?? '';
                    $row[$label] = is_array( $val ) ? implode( ', ', $val ) : $val;
                }
                foreach ( $child_field_map as $cfid => $clabel ) {
                    $val          = ( $c_id !== 0 && isset( $child_data_map[ $c_id ][ $cfid ] ) ) ? $child_data_map[ $c_id ][ $cfid ] : '';
                    $row[$clabel] = is_array( $val ) ? implode( ', ', $val ) : $val;
                }
                if ( $parent_ctx ) {
                    foreach ( $parent_ctx['field_map'] as $pfid => $plabel ) {
                        $val = ( $db_parent_id > 0 && isset( $parent_form_metas[$db_parent_id][$pfid] ) )
                               ? $parent_form_metas[$db_parent_id][$pfid]
                               : '';
                        $row[$plabel] = is_array( $val ) ? implode( ', ', $val ) : $val;
                    }
                }
                $expanded_rows[] = $row;
            }
        }
        return $expanded_rows;
    }

    /**
     * If the given form is a child/repeater sub-form, returns the parent form's
     * id, name, and field_map (labelled "ParentFormName: FieldName").
     * Checks both frm_forms.parent_form_id AND reverse-lookup via repeater
     * field_options (form_select) in case the child form has no parent_form_id set.
     * Otherwise returns null.
     */
    private static function get_parent_form_context( int $child_form_id ): ?array {
        global $wpdb;
        $parent_form_id = 0;

        // Method 1: direct parent_form_id column
        $direct = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT parent_form_id FROM {$wpdb->prefix}frm_forms WHERE id = %d",
            $child_form_id
        ) );
        if ( $direct > 0 ) {
            $parent_form_id = $direct;
        }

        // Method 2: reverse lookup via repeater field_options
        if ( $parent_form_id <= 0 ) {
            $rp = $wpdb->get_var( $wpdb->prepare(
                "SELECT f.form_id
                 FROM {$wpdb->prefix}frm_fields f
                 WHERE f.type = 'divider'
                   AND f.field_options LIKE %s
                 LIMIT 1",
                '%s:4:"form_select";i:' . $child_form_id . ';%'
            ) );
            if ( $rp ) $parent_form_id = (int) $rp;
        }

        if ( $parent_form_id <= 0 ) return null;

        $parent_form_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}frm_forms WHERE id = %d",
            $parent_form_id
        ) );

        $excluded_types = [ 'submit', 'break', 'button', 'captcha', 'end_divider' ];
        $p_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, type, field_options FROM {$wpdb->prefix}frm_fields
             WHERE form_id = %d AND type NOT IN ('" . implode( "','", $excluded_types ) . "')
             ORDER BY field_order ASC",
            $parent_form_id
        ) );

        $field_map = [];
        foreach ( $p_fields as $f ) {
            $f_opts = maybe_unserialize( $f->field_options );
            // Skip repeater dividers — they don't hold selectable values themselves.
            if ( $f->type === 'divider' && isset( $f_opts['repeat'] ) && $f_opts['repeat'] == '1' ) {
                continue;
            }
            $field_map[ (int) $f->id ] = $f->name;
        }

        return [
            'form_id'   => $parent_form_id,
            'form_name' => $parent_form_name,
            'field_map' => $field_map,
        ];
    }

    /**
     * Bulk-fetch metas for a list of parent entry ids, restricted to the fields
     * in $field_map. Returns [item_id => [field_id => value]].
     */
    private static function load_parent_metas( array $parent_ids, array $field_map ): array {
        if ( empty( $parent_ids ) || empty( $field_map ) ) return [];
        global $wpdb;
        $parent_ids = array_filter( array_map( 'intval', $parent_ids ) );
        if ( empty( $parent_ids ) ) return [];
        $id_list  = implode( ',', $parent_ids );
        $fid_list = implode( ',', array_map( 'intval', array_keys( $field_map ) ) );
        $metas = $wpdb->get_results(
            "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id IN ($id_list) AND field_id IN ($fid_list)",
            ARRAY_A
        );
        $out = [];
        foreach ( $metas as $m ) {
            $out[ (int) $m['item_id'] ][ (int) $m['field_id'] ] = maybe_unserialize( $m['meta_value'] );
        }
        return $out;
    }

    /**
     * Bulk-fetch complete frm_items rows for automatically discovered parent
     * entries. Returns [entry_id => item row].
     */
    private static function load_item_rows( array $entry_ids ): array {
        global $wpdb;
        $entry_ids = array_values( array_unique( array_filter( array_map( 'intval', $entry_ids ) ) ) );
        if ( empty( $entry_ids ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, item_key, name, description, ip, form_id, post_id, user_id,
                    parent_item_id, is_draft, updated_by, created_at, updated_at
             FROM {$wpdb->prefix}frm_items
             WHERE id IN ($placeholders)",
            ...$entry_ids
        ), ARRAY_A );

        $out = [];
        foreach ( $items as $item ) {
            $out[ (int) $item['id'] ] = $item;
        }
        return $out;
    }

    /**
     * Resolve creator/updater display names for a set of frm_items rows.
     */
    private static function resolve_item_user_names( array $items ): array {
        $created_by = [];
        $updated_by = [];
        foreach ( $items as $item ) {
            $entry_id = (int) ( $item['id'] ?? 0 );
            if ( $entry_id <= 0 ) continue;
            $created_by[$entry_id] = (int) ( $item['user_id'] ?? 0 );
            $updated_by[$entry_id] = (int) ( $item['updated_by'] ?? 0 );
        }
        return self::resolve_user_names( $created_by ) + self::resolve_user_names( $updated_by );
    }

    /**
     * Resolve a map of [entry_id => user_id] into [user_id => display_name]
     * for all unique non-zero user IDs. Returns [] if no valid users.
     */
    private static function resolve_user_names( array $id_to_uid ): array {
        global $wpdb;
        $uids = array_filter( array_unique( array_values( $id_to_uid ) ), fn($u) => $u > 0 );
        if ( empty( $uids ) ) return [];
        $ph      = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($ph)",
            ...array_values( $uids )
        ), ARRAY_A );
        $map = [];
        foreach ( $results as $r ) { $map[ (int) $r['ID'] ] = $r['display_name']; }
        return $map;
    }

    /**
     * Fetch single form rows and flatten them
     */
    public static function fetch_form_rows( int $form_id, bool $include_drafts = false, array $selected_fields_hint = [] ): array {
        global $wpdb;
        $excluded_types = [ 'submit', 'break', 'button', 'captcha', 'end_divider' ];

        $all_fields = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, type, field_options FROM {$wpdb->prefix}frm_fields
             WHERE form_id = %d AND type NOT IN ('" . implode( "','", $excluded_types ) . "')",
            $form_id
        ) );

        $form_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}frm_forms WHERE id = %d", $form_id ) );

        $field_map = []; $repeater_map = []; $child_form_ids = [];
        foreach ( $all_fields as $f ) {
            $f_opts = maybe_unserialize( $f->field_options );
            if ( $f->type === 'divider' && isset( $f_opts['repeat'] ) && $f_opts['repeat'] == '1' ) {
                $repeater_map[ (int) $f->id ] = (int) $f_opts['form_select'];
                $child_form_ids[]              = (int) $f_opts['form_select'];
            } else {
                $field_map[ (int) $f->id ] = $f->name;
            }
        }

        $child_field_map = [];
        if ( ! empty( $child_form_ids ) ) {
            $child_form_ids = array_unique( $child_form_ids );
            $placeholders   = implode( ',', array_fill( 0, count( $child_form_ids ), '%d' ) );
            $c_fields       = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}frm_fields
                 WHERE form_id IN ($placeholders) AND type NOT IN ('" . implode( "','", $excluded_types ) . "')",
                $child_form_ids
            ) );
            foreach ( $c_fields as $cf ) { $child_field_map[ (int) $cf->id ] = $cf->name; }
        }

        // When the caller specified which fields it needs and none of them belong to
        // the child/repeater form(s), skip all repeater expansion. Without this, a
        // query that selects only parent-form fields would still fan out — producing
        // N identical rows (one per child entry) instead of one row per parent entry.
        if ( ! empty( $selected_fields_hint ) && ! empty( $child_field_map ) ) {
            $child_labels = array_values( $child_field_map );
            if ( empty( array_intersect( $selected_fields_hint, $child_labels ) ) ) {
                $repeater_map    = [];
                $child_field_map = [];
            }
        }

        $query = "SELECT id, item_key, name, description, ip, form_id, post_id, user_id, parent_item_id, is_draft, updated_by, created_at, updated_at FROM {$wpdb->prefix}frm_items WHERE form_id = %d";
        if ( ! $include_drafts ) {
            $query .= " AND is_draft = 0";
        }
        $query .= " ORDER BY id DESC";

        $entries = $wpdb->get_results( $wpdb->prepare( $query, $form_id ), ARRAY_A );
        if ( empty( $entries ) ) return [];

        $id_list   = implode( ',', array_column( $entries, 'id' ) );
        $metas_raw = $wpdb->get_results( "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($id_list)", ARRAY_A );
        $parent_metas = []; $all_child_entry_ids = [];
        $draft_map    = [];
        $parent_item_map = [];
        $updated_by_map  = [];
        $creator_map     = [];
        $updated_at_map  = [];
        foreach ( $entries as $e ) {
            $draft_map[$e['id']]       = (int) $e['is_draft'];
            $parent_item_map[$e['id']] = (int) $e['parent_item_id'];
            $updated_by_map[$e['id']]  = (int) $e['updated_by'];
            $creator_map[$e['id']]     = (int) $e['user_id'];
            $updated_at_map[$e['id']]  = $e['updated_at'];
        }
        $user_name_map = self::resolve_user_names( $updated_by_map ) + self::resolve_user_names( $creator_map );

        foreach ( $metas_raw as $m ) {
            $val = maybe_unserialize( $m['meta_value'] );
            $fid = (int) $m['field_id'];
            $parent_metas[ $m['item_id'] ][ $fid ] = $val;
            if ( isset( $repeater_map[ $fid ] ) && is_array( $val ) ) {
                $all_child_entry_ids = array_merge( $all_child_entry_ids, $val );
            }
        }

        $child_data_map = [];
        $child_draft_map = [];
        $child_updated_by_map = [];
        $child_creator_map    = [];
        $child_updated_at_map = [];
        if ( ! empty( $all_child_entry_ids ) ) {
            $all_child_entry_ids = array_filter( array_unique( $all_child_entry_ids ) );
            if ( ! empty( $all_child_entry_ids ) ) {
                $c_id_list   = implode( ',', array_map( 'intval', $all_child_entry_ids ) );
                $c_metas_raw = $wpdb->get_results( "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($c_id_list)", ARRAY_A );
                foreach ( $c_metas_raw as $cm ) {
                    $child_data_map[ $cm['item_id'] ][ (int) $cm['field_id'] ] = maybe_unserialize( $cm['meta_value'] );
                }

                $c_entries = $wpdb->get_results( "SELECT id, is_draft, updated_by, user_id, updated_at FROM {$wpdb->prefix}frm_items WHERE id IN ($c_id_list)", ARRAY_A );
                foreach ( $c_entries as $ce ) {
                    $child_draft_map[$ce['id']]      = (int) $ce['is_draft'];
                    $child_updated_by_map[$ce['id']] = (int) $ce['updated_by'];
                    $child_creator_map[$ce['id']]    = (int) $ce['user_id'];
                    $child_updated_at_map[$ce['id']] = $ce['updated_at'];
                }
                $user_name_map = $user_name_map + self::resolve_user_names( $child_updated_by_map ) + self::resolve_user_names( $child_creator_map );
            }
        }

        // If the queried form is itself a child/repeater sub-form, load its parent
        // form's fields so they appear as selectable columns on every output row.
        $parent_ctx           = self::get_parent_form_context( $form_id );
        $parent_form_metas    = [];
        $parent_form_items    = [];
        $parent_user_name_map = [];
        if ( $parent_ctx ) {
            $uniq_parent_ids   = array_unique( array_filter( array_values( $parent_item_map ) ) );
            $parent_form_metas = self::load_parent_metas( $uniq_parent_ids, $parent_ctx['field_map'] );
            $parent_form_items = self::load_item_rows( $uniq_parent_ids );
            $parent_user_name_map = self::resolve_item_user_names( $parent_form_items );
        }

        $expanded_rows = [];
        foreach ( $entries as $entry ) {
            $p_id            = $entry['id'];
            $db_parent_id    = $parent_item_map[$p_id];
            $entry_child_ids = [];
            foreach ( $repeater_map as $r_id => $c_form_id ) {
                if ( isset( $parent_metas[ $p_id ][ $r_id ] ) && is_array( $parent_metas[ $p_id ][ $r_id ] ) ) {
                    $entry_child_ids = array_unique( array_merge( $entry_child_ids, $parent_metas[ $p_id ][ $r_id ] ) );
                }
            }
            if ( empty( $entry_child_ids ) ) $entry_child_ids = [ 0 ];
            foreach ( $entry_child_ids as $c_id ) {
                $p_status = $draft_map[$p_id];
                $c_status = ( $c_id !== 0 && isset( $child_draft_map[$c_id] ) ) ? $child_draft_map[$c_id] : 0;

                if ( ! $include_drafts && ( $p_status != 0 || $c_status != 0 ) ) {
                    continue;
                }

                $status_val = max( $p_status, $c_status );
                $status_label = match( $status_val ) {
                    1 => 'Draft',
                    2 => 'Abandoned',
                    default => '',
                };

                $uid = ( $c_id !== 0 && ! empty( $child_updated_by_map[$c_id] ) )
                       ? $child_updated_by_map[$c_id]
                       : $updated_by_map[$p_id];
                $last_modified_by = $user_name_map[$uid] ?? '';

                $creator_uid = ( $c_id !== 0 && ! empty( $child_creator_map[$c_id] ) )
                       ? $child_creator_map[$c_id]
                       : $creator_map[$p_id];
                $created_by = $user_name_map[$creator_uid] ?? '';

                $updated_date = ( $c_id !== 0 && ! empty( $child_updated_at_map[$c_id] ) )
                       ? $child_updated_at_map[$c_id]
                       : $updated_at_map[$p_id];

                $entry_id_val = ( $c_id == 0 ) ? $p_id : $c_id;

                // Parent_ID: use parent_item_id from DB if non-zero (child entry
                // belonging to a parent), otherwise use the entry's own id.
                $row = [
                    'Draft Status'     => $status_label,
                    'Parent_ID'        => ( $db_parent_id > 0 ) ? $db_parent_id : $p_id,
                    'Child_ID'         => $entry_id_val,
                    'Created_At'       => $entry['created_at'],
                    'Last Modified By' => $last_modified_by,
                    // New user-facing system column names (v2.28.3) — aliases of the above
                    // plus two genuinely new ones (Created by / Updated date) backed by
                    // frm_items.user_id / updated_at, which weren't queried before.
                    'Created by'       => $created_by,
                    'Modified by'      => $last_modified_by,
                    'Created Date'     => $entry['created_at'],
                    'Updated date'     => $updated_date,
                    // Formidable's DB has no separate "timestamp" concept — updated_at is
                    // already the most-recent-activity marker (set on insert, bumped on edit).
                    'Timestamp'        => $updated_date,
                    'Entry ID'         => $entry_id_val,
                ];
                $row = array_merge( $row, self::item_system_values( $entry, $form_id, $user_name_map ) );
                if ( $parent_ctx && $db_parent_id > 0 && isset( $parent_form_items[$db_parent_id] ) ) {
                    $row = array_merge(
                        $row,
                        self::item_system_values( $parent_form_items[$db_parent_id], $parent_ctx['form_id'], $parent_user_name_map )
                    );
                }
                foreach ( $field_map as $fid => $label ) {
                    $val         = $parent_metas[ $p_id ][ $fid ] ?? '';
                    $row[$label] = is_array( $val ) ? implode( ', ', $val ) : $val;
                }
                foreach ( $child_field_map as $cfid => $clabel ) {
                    $val          = ( $c_id !== 0 && isset( $child_data_map[ $c_id ][ $cfid ] ) ) ? $child_data_map[ $c_id ][ $cfid ] : '';
                    $row[$clabel] = is_array( $val ) ? implode( ', ', $val ) : $val;
                }
                // Parent-form fields (when queried form is a child/repeater)
                if ( $parent_ctx ) {
                    foreach ( $parent_ctx['field_map'] as $pfid => $plabel ) {
                        $val = ( $db_parent_id > 0 && isset( $parent_form_metas[$db_parent_id][$pfid] ) )
                               ? $parent_form_metas[$db_parent_id][$pfid]
                               : '';
                        $row[$plabel] = is_array( $val ) ? implode( ', ', $val ) : $val;
                    }
                }
                $expanded_rows[] = $row;
            }
        }
        return $expanded_rows;
    }

    /**
     * Fetch merged rows across multiple forms
     */
    public static function fetch_merged_rows( array $form_ids, array $key_fids, bool $include_drafts = false ): array {
        global $wpdb;

        $master_data     = [];
        $column_template = [];

        foreach ( $form_ids as $index => $form_id ) {
            // key_field_id can be a single int (legacy) or an array (multi-field composite key).
            $raw_kf = $key_fids[$index] ?? 0;
            if ( is_array( $raw_kf ) ) {
                $key_fid_list = array_values( array_filter( array_map( 'intval', $raw_kf ) ) );
            } else {
                $key_fid_list = ( (int) $raw_kf > 0 ) ? [ (int) $raw_kf ] : [];
            }
            $form_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}frm_forms WHERE id = %d", $form_id ) );

            $all_fields  = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, type, field_options FROM {$wpdb->prefix}frm_fields WHERE form_id = %d ORDER BY field_order ASC",
                $form_id
            ) );
            $field_map   = []; $repeater_map = [];
            foreach ( $all_fields as $f ) {
                $f_opts = maybe_unserialize( $f->field_options );
                if ( $f->type === 'divider' && isset( $f_opts['repeat'] ) && $f_opts['repeat'] == '1' ) {
                    $repeater_map[ (int) $f->id ] = (int) $f_opts['form_select'];
                } else {
                    $label = $f->name;
                    $field_map[ (int) $f->id ] = $label;
                    if ( ! in_array( $label, $column_template ) ) $column_template[] = $label;
                }
            }

            $child_field_map = [];
            if ( ! empty( $repeater_map ) ) {
                $c_ids    = array_values( $repeater_map );
                $ph       = implode( ',', array_fill( 0, count( $c_ids ), '%d' ) );
                $c_fields = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name FROM {$wpdb->prefix}frm_fields WHERE form_id IN ($ph) ORDER BY field_order ASC",
                    $c_ids
                ) );
                foreach ( $c_fields as $cf ) {
                    $c_label = $cf->name;
                    $child_field_map[ (int) $cf->id ] = $c_label;
                    if ( ! in_array( $c_label, $column_template ) ) $column_template[] = $c_label;
                }
            }

            // If this form in the merge is itself a child/repeater sub-form, expose
            // its parent form's fields so they're available as columns and as key fields.
            $parent_ctx = self::get_parent_form_context( $form_id );
            if ( $parent_ctx ) {
                foreach ( $parent_ctx['field_map'] as $plabel ) {
                    if ( ! in_array( $plabel, $column_template ) ) $column_template[] = $plabel;
                }
            }

            $query = "SELECT id, item_key, name, description, ip, form_id, post_id, user_id, parent_item_id, is_draft, updated_by, created_at, updated_at FROM {$wpdb->prefix}frm_items WHERE form_id = %d";
            if ( ! $include_drafts ) {
                $query .= " AND is_draft = 0";
            }
            $query .= " ORDER BY id ASC";
            $entries = $wpdb->get_results( $wpdb->prepare( $query, $form_id ), ARRAY_A );
            if ( empty( $entries ) ) continue;

            $id_list   = implode( ',', array_column( $entries, 'id' ) );
            $metas_raw = $wpdb->get_results( "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($id_list)", ARRAY_A );
            $parent_metas = []; $all_c_ids = [];
            $draft_map    = [];
            $parent_item_map = [];
            $created_at_map  = [];
            $updated_by_map  = [];
            $creator_map     = [];
            $updated_at_map  = [];
            foreach ( $entries as $e ) {
                $draft_map[$e['id']]       = (int) $e['is_draft'];
                $parent_item_map[$e['id']] = (int) $e['parent_item_id'];
                $created_at_map[$e['id']]  = $e['created_at'];
                $updated_by_map[$e['id']]  = (int) $e['updated_by'];
                $creator_map[$e['id']]     = (int) $e['user_id'];
                $updated_at_map[$e['id']]  = $e['updated_at'];
            }
            $user_name_map = self::resolve_user_names( $updated_by_map ) + self::resolve_user_names( $creator_map );

            $parent_form_metas    = [];
            $parent_form_items    = [];
            $parent_user_name_map = [];
            if ( $parent_ctx ) {
                $uniq_pids         = array_unique( array_filter( array_values( $parent_item_map ) ) );
                $parent_form_metas = self::load_parent_metas( $uniq_pids, $parent_ctx['field_map'] );
                $parent_form_items = self::load_item_rows( $uniq_pids );
                $parent_user_name_map = self::resolve_item_user_names( $parent_form_items );
            }

            foreach ( $metas_raw as $m ) {
                $val = maybe_unserialize( $m['meta_value'] );
                $parent_metas[ $m['item_id'] ][ (int) $m['field_id'] ] = $val;
                if ( isset( $repeater_map[ (int) $m['field_id'] ] ) && is_array( $val ) ) {
                    $all_c_ids = array_merge( $all_c_ids, $val );
                }
            }

            $child_metas = [];
            $child_draft_map = [];
            if ( ! empty( $all_c_ids ) ) {
                $c_id_list = implode( ',', array_map( 'intval', array_filter( $all_c_ids ) ) );
                $cm_raw    = $wpdb->get_results( "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($c_id_list)", ARRAY_A );
                foreach ( $cm_raw as $cm ) {
                    $child_metas[ $cm['item_id'] ][ (int) $cm['field_id'] ] = maybe_unserialize( $cm['meta_value'] );
                }

                $c_entries = $wpdb->get_results( "SELECT id, is_draft, created_at, updated_at, updated_by, user_id FROM {$wpdb->prefix}frm_items WHERE id IN ($c_id_list)", ARRAY_A );
                foreach ( $c_entries as $ce ) {
                    $child_draft_map[$ce['id']] = (int) $ce['is_draft'];
                    $created_at_map[$ce['id']]  = $ce['created_at'];
                    $updated_by_map[$ce['id']]  = (int) $ce['updated_by'];
                    $creator_map[$ce['id']]     = (int) $ce['user_id'];
                    $updated_at_map[$ce['id']]  = $ce['updated_at'];
                }
                $user_name_map = $user_name_map + self::resolve_user_names( $updated_by_map ) + self::resolve_user_names( $creator_map );
            }

            foreach ( $entries as $e ) {
                $p_id  = $e['id']; $rows = []; $has_r = false;
                foreach ( $repeater_map as $rfid => $cfid ) {
                    if ( isset( $parent_metas[$p_id][$rfid] ) && is_array( $parent_metas[$p_id][$rfid] ) ) {
                        foreach ( $parent_metas[$p_id][$rfid] as $cid ) { $rows[] = [ 'type' => 'child', 'id' => $cid ]; $has_r = true; }
                    }
                }
                if ( ! $has_r ) $rows[] = [ 'type' => 'parent', 'id' => $p_id ];

                foreach ( $rows as $proc ) {
                    $isc   = ( $proc['type'] === 'child' );
                    $cur_m = $isc ? ( $child_metas[$proc['id']] ?? [] ) : ( $parent_metas[$p_id] ?? [] );
                    
                    $p_status = $draft_map[$p_id];
                    $c_status = ( $isc && isset( $child_draft_map[$proc['id']] ) ) ? $child_draft_map[$proc['id']] : 0;

                    if ( ! $include_drafts && ( $p_status != 0 || $c_status != 0 ) ) {
                        continue;
                    }

                    // Resolve key field values — for each configured key field,
                    // check the current row's metas first, then the child-form parent
                    // entry's own metas, then (if this form is itself a child/repeater
                    // sub-form) the grand-parent form's metas. Concatenate all parts
                    // with '||' to form the composite join key.
                    $parent_pid = $parent_item_map[$p_id] ?? 0;
                    if ( empty( $key_fid_list ) ) continue;
                    $sk_parts      = []; // original casing/spacing — used only for the visible 'Common Key' column
                    $sk_parts_norm = []; // case-insensitive, whitespace-collapsed — the actual match key
                    $sk_has_empty  = false;
                    foreach ( $key_fid_list as $kfid ) {
                        if ( isset( $cur_m[$kfid] ) ) {
                            $raw = $cur_m[$kfid];
                        } elseif ( isset( $parent_metas[$p_id][$kfid] ) ) {
                            $raw = $parent_metas[$p_id][$kfid];
                        } elseif ( $parent_ctx && $parent_pid > 0 && isset( $parent_form_metas[$parent_pid][$kfid] ) ) {
                            $raw = $parent_form_metas[$parent_pid][$kfid];
                        } else {
                            $raw = '';
                        }
                        if ( is_array( $raw ) ) $raw = implode( ', ', $raw );
                        $part = trim( (string) $raw );
                        if ( $part === '' ) { $sk_has_empty = true; break; }
                        $sk_parts[]      = $part;
                        $sk_parts_norm[] = self::join_key( $part );
                    }
                    if ( $sk_has_empty || empty( $sk_parts ) ) continue;
                    // Matched case-insensitively / whitespace-collapsed, same as apply_query_joins()'s
                    // join_key() and the DMR report's norm_sample_id() — previously this merge alone
                    // matched case-sensitively with only outer-trim, so the same two Sample IDs could
                    // join via one plugin feature and silently fail to join via this one.
                    $sk = implode( '||', $sk_parts_norm );
                    $sk_display = implode( '||', $sk_parts );

                    $status_val = max( $p_status, $c_status );
                    $status_label = match( $status_val ) {
                        1 => 'Draft',
                        2 => 'Abandoned',
                        default => '',
                    };

                    if ( ! isset( $master_data[$sk] ) ) {
                        $master_data[$sk] = [
                            'Draft Status'     => $status_label,
                            'Common Key'       => $sk_display,
                            'Parent_ID'        => '',
                            'Child_ID'         => '',
                            'Created_At'       => '',
                            'Last Modified By' => '',
                            // New user-facing system column names (v2.28.3) — see the
                            // single-form fetch paths for the naming rationale.
                            'Created by'       => '',
                            'Modified by'      => '',
                            'Created Date'     => '',
                            'Updated date'     => '',
                            'Timestamp'        => '',
                            'Entry ID'         => '',
                        ];
                        foreach ( $column_template as $col ) { $master_data[$sk][$col] = ''; }
                    } elseif ( $status_val > 0 ) {
                        $master_data[$sk]['Draft Status'] = $status_label;
                    }

                    // Source-qualified metadata always comes from this source
                    // form's own frm_items row ($e). It therefore remains stable
                    // across repeater expansion and cannot inherit a child/meta
                    // modification timestamp.
                    foreach ( self::item_system_values( $e, $form_id, $user_name_map ) as $metadata_key => $metadata_value ) {
                        $master_data[$sk][$metadata_key] = $metadata_value;
                    }
                    if ( $parent_ctx && $parent_pid > 0 && isset( $parent_form_items[$parent_pid] ) ) {
                        foreach ( self::item_system_values(
                            $parent_form_items[$parent_pid],
                            $parent_ctx['form_id'],
                            $parent_user_name_map
                        ) as $metadata_key => $metadata_value ) {
                            $master_data[$sk][$metadata_key] = $metadata_value;
                        }
                    }

                    // System columns (Created_At/Last Modified By/Parent_ID/Child_ID) are
                    // absent from $column_template — it's built only from frm_fields, never
                    // populated here before — so every merged-query row had them blank.
                    // Created_At: earliest contributing entry (closest to "when this record
                    // was first created"). The rest: last contributing entry wins, same as
                    // the field-merge convention below.
                    $row_id         = $isc ? $proc['id'] : $p_id;
                    $row_created_at = $created_at_map[$row_id] ?? '';
                    if ( $row_created_at !== '' && ( $master_data[$sk]['Created_At'] === '' || $row_created_at < $master_data[$sk]['Created_At'] ) ) {
                        $master_data[$sk]['Created_At']   = $row_created_at;
                        $master_data[$sk]['Created Date'] = $row_created_at;
                    }
                    $row_uid = $updated_by_map[$row_id] ?? 0;
                    if ( ! empty( $user_name_map[$row_uid] ) ) {
                        $master_data[$sk]['Last Modified By'] = $user_name_map[$row_uid];
                        $master_data[$sk]['Modified by']      = $user_name_map[$row_uid];
                    }
                    $row_creator_uid = $creator_map[$row_id] ?? 0;
                    if ( ! empty( $user_name_map[$row_creator_uid] ) ) {
                        $master_data[$sk]['Created by'] = $user_name_map[$row_creator_uid];
                    }
                    $row_updated_at = $updated_at_map[$row_id] ?? '';
                    if ( $row_updated_at !== '' ) {
                        // Formidable's DB has no separate "timestamp" concept — updated_at is
                        // already the most-recent-activity marker (set on insert, bumped on edit).
                        $master_data[$sk]['Updated date'] = $row_updated_at;
                        $master_data[$sk]['Timestamp']    = $row_updated_at;
                    }
                    $master_data[$sk]['Parent_ID'] = ( $parent_pid > 0 ) ? $parent_pid : $p_id;
                    $master_data[$sk]['Child_ID']  = $isc ? $proc['id'] : $p_id;
                    $master_data[$sk]['Entry ID']  = $master_data[$sk]['Child_ID'];

                    // NOTE: when two merged forms share a same-named field (e.g. both have
                    // their own "Type of sampling"), a later form in the merge must not
                    // blank out a value an earlier form already supplied for this key —
                    // only overwrite when this form actually has a non-empty value.
                    foreach ( $field_map as $fid => $l ) {
                        $v = $parent_metas[$p_id][$fid] ?? '';
                        $v = is_array( $v ) ? implode( ', ', $v ) : $v;
                        if ( $v !== '' ) $master_data[$sk][$l] = $v;
                    }
                    if ( $isc ) {
                        foreach ( $child_field_map as $cfid => $cl ) {
                            if ( isset( $cur_m[$cfid] ) ) {
                                $v = $cur_m[$cfid];
                                $v = is_array( $v ) ? implode( ', ', $v ) : $v;
                                if ( $v !== '' ) $master_data[$sk][$cl] = $v;
                            }
                        }
                    }
                    // Parent-form (grand-parent) fields when this merge form is a child/repeater
                    if ( $parent_ctx && $parent_pid > 0 && isset( $parent_form_metas[$parent_pid] ) ) {
                        foreach ( $parent_ctx['field_map'] as $pfid => $pl ) {
                            $v = $parent_form_metas[$parent_pid][$pfid] ?? '';
                            $v = is_array( $v ) ? implode( ', ', $v ) : $v;
                            if ( $v !== '' ) $master_data[$sk][$pl] = $v;
                        }
                    }
                }
            }
        }

        $merged = array_values( $master_data );
        usort( $merged, fn($a,$b) => strnatcasecmp( (string)($a['Common Key']??''), (string)($b['Common Key']??'') ) );
        return $merged;
    }
}
