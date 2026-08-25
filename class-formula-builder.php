<?php
/**
 * File:    class-formula-builder.php
 * Version: 2.25.1
 * Description: UI rendering and formula evaluation for calculated columns.
 *
 * Evaluation is done with a small shunting-yard parser + RPN evaluator —
 * NO eval(), NO create_function(), safe for the WP plugin repo.
 *
 * Supported syntax:
 *   [Field Name]            field or earlier-defined calc column reference
 *   + - * /                 arithmetic operators (left-associative)
 *   &                       text concatenation ("ABC" & "DEF" → "ABCDEF")
 *   -x                      unary minus
 *   ( )                     grouping
 *   numeric literals        123, 1.5, .5
 *   text literals           "hello" or 'hello'  ("" inside "" escapes a quote)
 *   FUNC(a, b, ...)         see self::FUNCTIONS
 *
 * Values are either numbers or text. Each operator coerces its operands to
 * what it needs: the arithmetic operators coerce to number (and error on text
 * that isn't numeric), `&` and the text functions coerce to string. That means
 * a blank cell is 0 to `+` but "" to `&`, which is what users expect.
 *
 * Numeric coercion of a cell:
 *   - blank / null        → 0
 *   - numeric string with commas / currency symbols → stripped then parsed
 *     ("R 1,234.56" → 1234.56)
 *   - anything else       → row-level error (reported once per column)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Formidable_Flat_Formula_Builder {

    /**
     * Callable functions, as name => [min_args, max_args (null = unlimited), returns].
     * Kept in one place so the UI reference table and the parser can't drift apart.
     */
    const FUNCTIONS = [
        'ROUND'  => [ 1, 2,    'number', 'ROUND(x)  /  ROUND(x, decimals)', 'Round to the given number of decimals (default 0).' ],
        'ABS'    => [ 1, 1,    'number', 'ABS(x)',                          'Absolute value.' ],
        'MIN'    => [ 1, null, 'number', 'MIN(a, b, ...)',                  'Smallest of its arguments.' ],
        'MAX'    => [ 1, null, 'number', 'MAX(a, b, ...)',                  'Largest of its arguments.' ],
        'SUM'    => [ 1, null, 'number', 'SUM(a, b, ...)',                  'Adds all its arguments.' ],
        'LEN'    => [ 1, 1,    'number', 'LEN(text)',                       'Number of characters.' ],
        'CONCAT' => [ 1, null, 'text',   'CONCAT(a, b, ...)',               'Joins its arguments as text. Same as the & operator.' ],
        'UPPER'  => [ 1, 1,    'text',   'UPPER(text)',                     'Converts to upper case.' ],
        'LOWER'  => [ 1, 1,    'text',   'LOWER(text)',                     'Converts to lower case.' ],
        'TRIM'   => [ 1, 1,    'text',   'TRIM(text)',                      'Removes leading/trailing spaces.' ],
    ];

    /**
     * Render the Calculated Columns section HTML inside the Query Builder form.
     */
    public static function render_section( $query ) {
        $calc_cols = [];
        if ( $query && ! empty( $query['calculated_columns'] ) ) {
            $calc_cols = $query['calculated_columns'];
        }
        ?>
        <div class="ffapi-section" id="ffapi-calc-section">
            <div class="ffapi-section-head">
                <div class="ffapi-section-num">5</div>
                <h3>Calculated Columns <span style="font-weight:400; color:#646970; font-size:12px;">(post-query arithmetic — appears at the far right of output)</span></h3>
            </div>
            <div class="ffapi-section-body">
                <div id="ffapi-calc-container">
                    <?php if ( empty( $calc_cols ) ): ?>
                        <p style="color:#999; font-size:13px; font-style:italic;" id="ffapi-calc-empty">No calculated columns yet. Click "Add Calculated Column" to create derived metrics.</p>
                    <?php endif; ?>
                </div>
                <button type="button" class="ffapi-btn ffapi-btn-secondary ffapi-btn-sm" onclick="addCalcColumn()" style="margin-top:10px;">+ Add Calculated Column</button>

                <!-- Available field chips for formula insertion -->
                <div id="ffapi-calc-fields-chips" style="margin-top:14px; display:none;">
                    <label style="display:block; font-size:11px; font-weight:600; color:#646970; text-transform:uppercase; margin-bottom:6px;">Click a field to insert into formula:</label>
                    <div id="ffapi-calc-chips-wrap" class="ffapi-calc-chips-wrap"></div>
                    <p style="margin:8px 0 0; font-size:12px; color:#646970;">
                        Solid blue = selected for output &nbsp;·&nbsp;
                        <span style="color:#646970;">dashed grey = available for calculations only (won't appear in the report)</span> &nbsp;·&nbsp;
                        <span style="color:#16a34a;">📐 green = a calculated column</span>.
                        A formula can use <strong>any</strong> field — the inputs don't have to be selected, so you can
                        compute e.g. an average without adding the raw values to the report.
                    </p>
                </div>

                <?php self::render_help(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Formula reference. Collapsed by default so it doesn't dominate the builder.
     * The function table is generated from self::FUNCTIONS so it cannot drift out of
     * sync with what the parser actually accepts.
     */
    private static function render_help() {
        $examples = [
            [ '[Qty] * [Unit Price]',                'Multiply two columns.' ],
            [ '([A] + [B]) + ([A] + [C]) * 10',      'Parentheses group; <code>*</code> and <code>/</code> bind tighter than <code>+</code> and <code>-</code>.' ],
            [ '[Mass] / [Volume (m3)] * 100',        'Field names with spaces or symbols are fine inside the brackets.' ],
            [ '[First Name] &amp; " " &amp; [Surname]',      'Join text. <code>&amp;</code> glues values together: <code>ABC</code> &amp; <code>DEF</code> → <code>ABCDEF</code>.' ],
            [ '"Sample " &amp; [Sample ID]',              'Mix a text literal with a field.' ],
            [ '[Company] &amp; "//" &amp; [Mine]',           'Build a compound key.' ],
            [ 'ROUND([Mass] / [Volume (m3)], 3)',    'Round to 3 decimals.' ],
            [ 'UPPER(TRIM([Occupation]))',           'Nest functions freely.' ],
            [ 'ROUND([C] / [OEL] * 100, 1) &amp; "%"',   'Numbers become text when concatenated — here, a formatted percentage.' ],
        ];
        ?>
        <details class="ffapi-calc-help" style="margin-top:18px; border:1px solid #e0e0e0; border-radius:6px; background:#fbfbfc;">
            <summary style="cursor:pointer; padding:10px 14px; font-size:13px; font-weight:600; color:#1d2327;">📖 Formula reference — operators, functions &amp; examples</summary>
            <div style="padding:0 14px 14px;">

                <p style="font-size:13px; color:#50575e; margin:6px 0 14px;">
                    Reference a column by its name in square brackets: <code>[Sample ID]</code>. Use the chips above to
                    insert one. Calculated columns are evaluated <strong>in order</strong>, so a formula can reference a
                    calculated column defined above it. Text must be quoted: <code>"like this"</code>.
                </p>

                <h4 style="font-size:12px; text-transform:uppercase; color:#646970; margin:14px 0 6px;">Operators</h4>
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <tr style="border-bottom:1px solid #f0f0f1;"><td style="padding:5px 0; width:90px;"><code>+ - * /</code></td><td>Arithmetic. <code>*</code> and <code>/</code> are applied before <code>+</code> and <code>-</code>.</td></tr>
                    <tr style="border-bottom:1px solid #f0f0f1;"><td style="padding:5px 0;"><code>&amp;</code></td><td><strong>Text concatenation.</strong> <code>[A] &amp; [B]</code> joins the two values end to end. Applied <em>after</em> arithmetic, so <code>[Name] &amp; [X] + [Y]</code> appends the sum.</td></tr>
                    <tr style="border-bottom:1px solid #f0f0f1;"><td style="padding:5px 0;"><code>( )</code></td><td>Grouping — evaluate the inner expression first.</td></tr>
                    <tr style="border-bottom:1px solid #f0f0f1;"><td style="padding:5px 0;"><code>-x</code></td><td>Negation.</td></tr>
                </table>

                <h4 style="font-size:12px; text-transform:uppercase; color:#646970; margin:16px 0 6px;">Functions</h4>
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <?php foreach ( self::FUNCTIONS as $fn => $meta ):
                        [ , , $returns, $sig, $desc ] = $meta; ?>
                        <tr style="border-bottom:1px solid #f0f0f1;">
                            <td style="padding:5px 0; width:230px;"><code><?php echo esc_html( $sig ); ?></code></td>
                            <td style="width:60px; color:#646970; font-size:12px;"><?php echo esc_html( $returns ); ?></td>
                            <td><?php echo esc_html( $desc ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h4 style="font-size:12px; text-transform:uppercase; color:#646970; margin:16px 0 6px;">Examples</h4>
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <?php foreach ( $examples as [ $formula, $note ] ): ?>
                        <tr style="border-bottom:1px solid #f0f0f1;">
                            <td style="padding:5px 10px 5px 0; white-space:nowrap;"><code><?php echo $formula; // already escaped above ?></code></td>
                            <td style="color:#50575e;"><?php echo $note; // contains intentional <code> markup ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h4 style="font-size:12px; text-transform:uppercase; color:#646970; margin:16px 0 6px;">How values are treated</h4>
                <ul style="font-size:13px; color:#50575e; margin:0; padding-left:18px;">
                    <li>A blank cell is <strong>0</strong> to arithmetic, but an <strong>empty string</strong> to <code>&amp;</code>.</li>
                    <li>Currency and thousands separators are handled: <code>"R 1,234.56"</code> is read as <code>1234.56</code>.</li>
                    <li>Doing arithmetic on text that isn't a number reports an error for that column rather than guessing.</li>
                    <li>Numeric results keep full computed precision — nothing is rounded automatically. Use <code>ROUND()</code> to control decimals, including when one calculated column feeds into another.</li>
                    <li>Comparisons and <code>IF()</code> are not supported.</li>
                </ul>
            </div>
        </details>
        <?php
    }

    /**
     * Evaluate all calculated columns on a set of rows.
     *
     * Each calculated column is evaluated in definition order so later columns
     * can reference earlier ones.
     *
     * @param array $rows      Data rows (list of associative arrays). Passed by ref.
     * @param array $calc_cols Array of { name, formula } from the saved query.
     * @return array           [ 'errors' => string[] ] — rows are mutated in place.
     */
    public static function evaluate_calculated_columns( array &$rows, array $calc_cols ) {
        $errors = [];
        if ( empty( $calc_cols ) || empty( $rows ) ) {
            return [ 'errors' => $errors ];
        }

        // Determine the authoritative set of available keys from the first row.
        // This is post-pruning / post-aliasing, so keys match whatever the
        // engine produced upstream (including any user-defined aliases).
        $available = array_keys( reset( $rows ) );
        $available_set = array_flip( $available );

        foreach ( $calc_cols as $col ) {
            $name    = isset( $col['name'] )    ? trim( (string) $col['name'] )    : '';
            $formula = isset( $col['formula'] ) ? trim( (string) $col['formula'] ) : '';
            if ( $name === '' || $formula === '' ) continue;

            // Parse once to RPN.
            try {
                $rpn = self::compile( $formula );
            } catch ( \Throwable $e ) {
                $errors[] = sprintf( 'Calculated column "%s": %s', $name, $e->getMessage() );
                // Still add the column as 0 so exports don't shift.
                foreach ( $rows as &$r ) { $r[ $name ] = 0; }
                unset( $r );
                continue;
            }

            // Validate referenced labels once against the first row's keys.
            // Calc columns defined earlier in this loop are already in $available_set.
            $missing = [];
            foreach ( $rpn as $tok ) {
                if ( $tok[0] === 'ref' && ! isset( $available_set[ $tok[1] ] ) ) {
                    $missing[ $tok[1] ] = true;
                }
            }
            if ( $missing ) {
                $errors[] = sprintf(
                    'Calculated column "%s": unknown field reference(s): %s',
                    $name,
                    implode( ', ', array_map( function( $k ) { return '[' . $k . ']'; }, array_keys( $missing ) ) )
                );
                foreach ( $rows as &$r ) { $r[ $name ] = 0; }
                unset( $r );
                // Still register the name so later columns can reference it (as 0).
                $available_set[ $name ] = true;
                continue;
            }

            // Evaluate per row.
            $row_errors = [];
            foreach ( $rows as &$r ) {
                try {
                    $r[ $name ] = self::evaluate_rpn( $rpn, $r );
                } catch ( \Throwable $e ) {
                    $row_errors[ $e->getMessage() ] = true;
                    $r[ $name ] = 0;
                }
            }
            unset( $r );

            // Surface each distinct row-level error once per column.
            foreach ( array_keys( $row_errors ) as $msg ) {
                $errors[] = sprintf( 'Calculated column "%s": %s', $name, $msg );
            }

            // Register this column's name so later formulas can reference it.
            $available_set[ $name ] = true;
        }

        return [ 'errors' => array_values( array_unique( $errors ) ) ];
    }

    // ---------------------------------------------------------------------
    // Shunting-yard parser: infix formula → RPN token list.
    // ---------------------------------------------------------------------
    //
    // Token shapes:
    //   [ 'num',  float ]
    //   [ 'str',  string ]
    //   [ 'ref',  string label ]
    //   [ 'op',   '+' | '-' | '*' | '/' | '&' | 'u-' ]   (u- = unary minus)
    //   [ 'func', NAME, int argc ]                       (argc resolved at parse time)
    //
    private static function compile( $formula ) {
        $tokens = self::tokenize( $formula );
        $output = [];
        $ops    = [];
        $argc   = []; // parallel stack: pending argument count per open function call

        // `&` binds loosest, so  [A] & [B] + [C]  concatenates A with (B+C) — matching
        // the convention in Excel/Sheets, where arithmetic wins over concatenation.
        $prec = [ 'u-' => 5, '*' => 4, '/' => 4, '+' => 3, '-' => 3, '&' => 2 ];

        // A value (or a nested call) has appeared inside the innermost open call, so that
        // call has at least one argument. Idempotent, so nested groupings can't inflate it.
        $mark_arg = function() use ( &$argc ) {
            if ( ! empty( $argc ) && $argc[ count( $argc ) - 1 ] === 0 ) {
                $argc[ count( $argc ) - 1 ] = 1;
            }
        };

        foreach ( $tokens as $tok ) {
            $type = $tok[0];
            if ( $type === 'num' || $type === 'ref' || $type === 'str' ) {
                $mark_arg();
                $output[] = $tok;
            } elseif ( $type === 'func' ) {
                $mark_arg();   // this call is itself an argument of any enclosing call
                $ops[]  = $tok;
                $argc[] = 0;   // its own pending argument count
            } elseif ( $type === 'comma' ) {
                $matched = false;
                for ( $k = count( $ops ) - 1; $k >= 0; $k-- ) {
                    if ( $ops[ $k ][0] === 'lparen' ) { $matched = true; break; }
                    $output[] = array_pop( $ops );
                }
                if ( ! $matched || empty( $argc ) ) {
                    throw new \Exception( 'misplaced comma (only valid between function arguments)' );
                }
                $argc[ count( $argc ) - 1 ]++;
            } elseif ( $type === 'op' ) {
                $o1 = $tok[1];
                while ( ! empty( $ops ) ) {
                    $top = end( $ops );
                    if ( $top[0] !== 'op' ) break;
                    $o2 = $top[1];
                    // All supported binary ops are left-associative.
                    // Unary minus is right-associative so we only pop strictly higher.
                    if ( ( $o1 === 'u-' && $prec[ $o2 ] > $prec[ $o1 ] )
                      || ( $o1 !== 'u-' && $prec[ $o2 ] >= $prec[ $o1 ] ) ) {
                        $output[] = array_pop( $ops );
                    } else {
                        break;
                    }
                }
                $ops[] = $tok;
            } elseif ( $type === 'lparen' ) {
                $ops[] = $tok;
            } elseif ( $type === 'rparen' ) {
                $matched = false;
                while ( ! empty( $ops ) ) {
                    $top = array_pop( $ops );
                    if ( $top[0] === 'lparen' ) { $matched = true; break; }
                    $output[] = $top;
                }
                if ( ! $matched ) throw new \Exception( 'mismatched parentheses' );

                // If this ')' closed a function's argument list (rather than a plain grouping),
                // the function token is now on top of the stack — emit it with its arity.
                if ( ! empty( $ops ) && end( $ops )[0] === 'func' ) {
                    $fn = array_pop( $ops );
                    $n  = array_pop( $argc );
                    self::assert_arity( $fn[1], $n );
                    $output[] = [ 'func', $fn[1], $n ];
                }
            }
        }
        while ( ! empty( $ops ) ) {
            $top = array_pop( $ops );
            if ( $top[0] === 'lparen' || $top[0] === 'rparen' ) {
                throw new \Exception( 'mismatched parentheses' );
            }
            if ( $top[0] === 'func' ) {
                throw new \Exception( sprintf( 'missing closing ")" after %s(', $top[1] ) );
            }
            $output[] = $top;
        }
        if ( empty( $output ) ) {
            throw new \Exception( 'empty formula' );
        }
        return $output;
    }

    /** Validate an argument count against the FUNCTIONS registry. */
    private static function assert_arity( $name, $n ) {
        [ $min, $max ] = self::FUNCTIONS[ $name ];
        if ( $n < $min || ( $max !== null && $n > $max ) ) {
            $expected = ( $max === null )
                ? sprintf( 'at least %d', $min )
                : ( $min === $max ? sprintf( '%d', $min ) : sprintf( '%d–%d', $min, $max ) );
            throw new \Exception( sprintf( '%s() takes %s argument(s), got %d', $name, $expected, $n ) );
        }
    }

    // Tokenizer — walks the formula string and yields typed tokens.
    private static function tokenize( $formula ) {
        $tokens = [];
        $i = 0;
        $n = strlen( $formula );
        $prev = null; // track previous token type for unary-minus detection

        while ( $i < $n ) {
            $c = $formula[ $i ];

            if ( ctype_space( $c ) ) { $i++; continue; }

            // [Label]
            if ( $c === '[' ) {
                $end = strpos( $formula, ']', $i + 1 );
                if ( $end === false ) throw new \Exception( 'unterminated [field] reference' );
                $label = substr( $formula, $i + 1, $end - $i - 1 );
                if ( $label === '' ) throw new \Exception( 'empty [] reference' );
                $tokens[] = [ 'ref', $label ];
                $prev = 'val';
                $i = $end + 1;
                continue;
            }

            // Text literal: "..." or '...'. A doubled quote inside escapes one quote.
            if ( $c === '"' || $c === "'" ) {
                $quote = $c;
                $j     = $i + 1;
                $buf   = '';
                $closed = false;
                while ( $j < $n ) {
                    if ( $formula[ $j ] === $quote ) {
                        if ( $j + 1 < $n && $formula[ $j + 1 ] === $quote ) { $buf .= $quote; $j += 2; continue; }
                        $closed = true;
                        $j++;
                        break;
                    }
                    $buf .= $formula[ $j ];
                    $j++;
                }
                if ( ! $closed ) throw new \Exception( 'unterminated text literal' );
                $tokens[] = [ 'str', $buf ];
                $prev = 'val';
                $i = $j;
                continue;
            }

            // Numeric literal
            if ( ctype_digit( $c ) || ( $c === '.' && $i + 1 < $n && ctype_digit( $formula[ $i + 1 ] ) ) ) {
                $j = $i;
                $seen_dot = false;
                while ( $j < $n && ( ctype_digit( $formula[ $j ] ) || ( $formula[ $j ] === '.' && ! $seen_dot ) ) ) {
                    if ( $formula[ $j ] === '.' ) $seen_dot = true;
                    $j++;
                }
                $tokens[] = [ 'num', (float) substr( $formula, $i, $j - $i ) ];
                $prev = 'val';
                $i = $j;
                continue;
            }

            // Function name
            if ( ctype_alpha( $c ) || $c === '_' ) {
                $j = $i;
                while ( $j < $n && ( ctype_alnum( $formula[ $j ] ) || $formula[ $j ] === '_' ) ) $j++;
                $word = strtoupper( substr( $formula, $i, $j - $i ) );
                if ( ! isset( self::FUNCTIONS[ $word ] ) ) {
                    throw new \Exception( sprintf( 'unknown function "%s"', $word ) );
                }
                $tokens[] = [ 'func', $word ];
                $prev = 'func';
                $i = $j;
                continue;
            }

            // Parens + argument separator
            if ( $c === '(' ) { $tokens[] = [ 'lparen' ]; $prev = 'lparen'; $i++; continue; }
            if ( $c === ')' ) { $tokens[] = [ 'rparen' ]; $prev = 'val';    $i++; continue; }
            if ( $c === ',' ) { $tokens[] = [ 'comma' ];  $prev = 'comma';  $i++; continue; }

            // Operators
            if ( $c === '+' || $c === '-' || $c === '*' || $c === '/' || $c === '&' ) {
                // Unary minus/plus: after start, an operator, '(' or ','
                if ( ( $c === '-' || $c === '+' )
                  && ( $prev === null || $prev === 'op' || $prev === 'lparen' || $prev === 'comma' ) ) {
                    if ( $c === '-' ) { $tokens[] = [ 'op', 'u-' ]; $prev = 'op'; }
                    // unary + is a no-op
                    $i++;
                    continue;
                }
                $tokens[] = [ 'op', $c ];
                $prev = 'op';
                $i++;
                continue;
            }

            throw new \Exception( sprintf( 'unexpected character "%s" at position %d', $c, $i ) );
        }

        return $tokens;
    }

    // RPN evaluator — runs once per row with the compiled token list.
    // Stack values are floats or strings; each operator coerces what it needs.
    private static function evaluate_rpn( array $rpn, array $row ) {
        $stack = [];
        foreach ( $rpn as $tok ) {
            $type = $tok[0];
            if ( $type === 'num' ) {
                $stack[] = (float) $tok[1];
            } elseif ( $type === 'str' ) {
                $stack[] = (string) $tok[1];
            } elseif ( $type === 'ref' ) {
                // Pushed raw — a blank cell must be able to become 0 for `+` but "" for `&`,
                // so coercion is deferred to whichever operator consumes it.
                $stack[] = $row[ $tok[1] ] ?? null;
            } elseif ( $type === 'func' ) {
                $name = $tok[1];
                $n    = $tok[2];
                if ( count( $stack ) < $n ) throw new \Exception( 'malformed expression' );
                $args = $n > 0 ? array_splice( $stack, -$n ) : [];
                $stack[] = self::call_function( $name, $args );
            } elseif ( $type === 'op' ) {
                $op = $tok[1];
                if ( $op === 'u-' ) {
                    if ( empty( $stack ) ) throw new \Exception( 'malformed expression' );
                    $stack[] = -self::coerce_numeric( array_pop( $stack ) );
                } else {
                    if ( count( $stack ) < 2 ) throw new \Exception( 'malformed expression' );
                    $b = array_pop( $stack );
                    $a = array_pop( $stack );
                    if ( $op === '&' ) {
                        $stack[] = self::coerce_string( $a ) . self::coerce_string( $b );
                        continue;
                    }
                    $a = self::coerce_numeric( $a );
                    $b = self::coerce_numeric( $b );
                    switch ( $op ) {
                        case '+': $stack[] = $a + $b; break;
                        case '-': $stack[] = $a - $b; break;
                        case '*': $stack[] = $a * $b; break;
                        case '/':
                            if ( $b == 0.0 ) throw new \Exception( 'division by zero' );
                            $stack[] = $a / $b;
                            break;
                    }
                }
            }
        }
        if ( count( $stack ) !== 1 ) throw new \Exception( 'malformed expression' );
        $result = $stack[0];

        if ( is_string( $result ) ) return $result;

        $result = self::coerce_numeric( $result );
        if ( ! is_finite( $result ) ) throw new \Exception( 'non-finite result (overflow or /0)' );
        // Round only to clean up IEEE-754 float noise (e.g. 0.1+0.2), never to truncate real
        // precision — 10dp matches ROUND()'s own max decimals cap below. Previously this forced
        // 3dp unconditionally, which silently overrode an explicit ROUND(x, N>3) and compounded
        // across chained calculated columns (a later column reads an earlier one's ALREADY-
        // rounded stored value) — confirmed causing real, visible discrepancies on live data.
        return round( $result, 10 );
    }

    /** Apply one of self::FUNCTIONS to already-evaluated arguments. */
    private static function call_function( $name, array $args ) {
        $nums = function() use ( $args ) { return array_map( [ __CLASS__, 'coerce_numeric' ], $args ); };
        $strs = function() use ( $args ) { return array_map( [ __CLASS__, 'coerce_string' ],  $args ); };

        switch ( $name ) {
            case 'ROUND':
                $n = $nums();
                $d = isset( $n[1] ) ? (int) $n[1] : 0;
                return round( $n[0], max( 0, min( 10, $d ) ) );
            case 'ABS':    return abs( self::coerce_numeric( $args[0] ) );
            case 'MIN':    return min( $nums() );
            case 'MAX':    return max( $nums() );
            case 'SUM':    return array_sum( $nums() );
            case 'LEN':    return (float) self::str_len( self::coerce_string( $args[0] ) );
            case 'CONCAT': return implode( '', $strs() );
            case 'UPPER':  return self::str_case( self::coerce_string( $args[0] ), 'upper' );
            case 'LOWER':  return self::str_case( self::coerce_string( $args[0] ), 'lower' );
            case 'TRIM':   return trim( self::coerce_string( $args[0] ) );
        }
        throw new \Exception( sprintf( 'unknown function "%s"', $name ) );
    }

    // mbstring is not guaranteed to be installed, so fall back to the byte-wise
    // equivalents rather than fataling on hosts without the extension.
    private static function str_len( $s ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
    }

    private static function str_case( $s, $mode ) {
        if ( $mode === 'upper' ) {
            return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
        }
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
    }

    /**
     * Coerce a value to text.
     *   null / blank → ""
     *   float        → shortest sensible decimal ("2" not "2.000", "1.5" not "1.500")
     *   string       → unchanged
     */
    private static function coerce_string( $val ) {
        if ( $val === null ) return '';
        if ( is_string( $val ) ) return $val;
        if ( is_bool( $val ) ) return $val ? '1' : '';
        if ( is_float( $val ) || is_int( $val ) ) {
            if ( is_float( $val ) && ! is_finite( $val ) ) throw new \Exception( 'non-finite result (overflow or /0)' );
            $s = rtrim( rtrim( sprintf( '%.6F', (float) $val ), '0' ), '.' );
            return $s === '' || $s === '-' ? '0' : $s;
        }
        return (string) $val;
    }

    /**
     * Coerce a cell value to a number.
     *   blank / null      → 0
     *   numeric           → float
     *   "R 1,234.56"      → 1234.56 (strips currency symbols and thousands commas)
     *   otherwise         → throws
     */
    private static function coerce_numeric( $val ) {
        if ( $val === null || $val === '' ) return 0.0;
        if ( is_int( $val ) || is_float( $val ) ) return (float) $val;
        if ( is_numeric( $val ) ) return (float) $val;

        if ( is_string( $val ) ) {
            // Strip anything that isn't a digit, dot, or minus sign.
            // (This handles "R 1,234.56", "$1,000", "45%", etc.)
            $clean = preg_replace( '/[^0-9.\-]/', '', $val );
            if ( $clean !== '' && is_numeric( $clean ) ) {
                return (float) $clean;
            }
        }
        throw new \Exception( sprintf( 'non-numeric value "%s"', is_scalar( $val ) ? (string) $val : gettype( $val ) ) );
    }
}
