/**
 * Formidable Flat API – Interactive Table View
 * Version: 2.2.0
 *
 * Depends on: Tabulator v5, ffapiFrontend (localized by PHP)
 */

(function () {
    'use strict';

    // ── Helpers ──────────────────────────────────────────────────────────

    function debounce(fn, ms) {
        let timer;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, arguments), ms);
        };
    }

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = String(text ?? '');
        return d.innerHTML;
    }

    function todayStr() {
        return new Date().toISOString().slice(0, 10);
    }

    // CSV files may be opened by spreadsheet programs which interpret leading
    // =, +, -, or @ characters as formulae. Neutralize non-numeric text values
    // before download; XLSX values are already emitted as inline strings by the
    // server-side writer.
    function csvSafeValue(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'object') value = JSON.stringify(value);
        var text = String(value);
        var trimmed = text.replace(/^[\x00-\x20]+/, '');
        var numeric = text.trim() !== '' && isFinite(Number(text));
        if (!numeric && /^[=+\-@]/.test(trimmed)) text = "'" + text;
        return text;
    }

    function downloadSafeCsv(rows, filename) {
        rows = stripHiddenKeys(rows);
        if (!rows.length) {
            alert('No data to export.');
            return;
        }

        var keys = Object.keys(rows[0]);
        var quote = function (value) {
            return '"' + csvSafeValue(value).replace(/"/g, '""') + '"';
        };
        var lines = [
            keys.map(quote).join(',')
        ].concat(rows.map(function (row) {
            return keys.map(function (key) { return quote(row[key]); }).join(',');
        }));
        var blob = new Blob(['\uFEFF' + lines.join('\r\n')], {
            type: 'text/csv;charset=utf-8'
        });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 0);
    }

    // ── Column builder ────────────────────────────────────────────────────
    // Auto-detects numeric columns for correct sort + right-alignment.

    // System columns are rendered at the far right (metadata-style). Includes both the
    // v2.28.3 display names and the legacy raw names, since a query built before that
    // version may still reference the old ones.
    var SYSTEM_COLS = [
        'Created by', 'Modified by', 'Created Date', 'Updated date', 'Timestamp', 'Entry ID',
        'Draft Status', 'Common Key', 'Parent_ID', 'Child_ID', 'Created_At', 'Last Modified By'
    ];

    function buildColumns(rows, editUrl, calcCols) {
        if (!rows.length) return [];

        // Helper: resolve entry ID from row data. Prefers the hidden
        // `_ffapi_parent_id`/`_ffapi_child_id` keys injected server-side so
        // the edit link works even when Parent_ID/Child_ID are not selected
        // as visible columns in the query builder.
        function resolveEntryId(rowData) {
            var parentId = rowData['_ffapi_parent_id'];
            if (parentId === undefined) parentId = rowData['Parent_ID'];
            var childId  = rowData['_ffapi_child_id'];
            if (childId === undefined) childId = rowData['Child_ID'];
            if ( parentId && parentId !== 'N/A' && parseInt(parentId) > 0 ) return parentId;
            return childId;
        }

        // Build columns from row keys, skipping underscore-prefixed hidden keys
        // (e.g. _ffapi_parent_id injected server-side for edit-link resolution).
        var dataCols = Object.keys(rows[0])
            .filter(function (key) { return key.charAt(0) !== '_'; })
            .map(function (key) {
                var nonEmpty = rows.filter(function (r) {
                    return r[key] !== '' && r[key] !== null && r[key] !== undefined;
                });
                var isNumeric = nonEmpty.length > 0 && nonEmpty.every(function (r) {
                    var v = String(r[key]).trim();
                    return v !== '' && !isNaN(parseFloat(v)) && isFinite(v);
                });
                return {
                    title:       key,
                    field:       key,
                    sorter:      isNumeric ? 'number' : 'alphanum',
                    hozAlign:    isNumeric ? 'right'  : 'left',
                    headerSort:  true,
                    minWidth:    40,
                    tooltip:     false,
                    formatter:   'plaintext',
                };
            });

        // Respect the column order the server already applied. The row keys arrive in the
        // query's "Column Order & Headers" arrangement (including any repositioned calculated
        // columns and system columns), so dataCols is already in the correct order.
        // Do NOT re-sort here: a previous version forced user→system→calc, which pushed
        // calculated columns to the far right and undid the arrangement in the table view AND
        // its CSV download (Tabulator's CSV export follows the column definitions). XLSX/Print
        // export the raw row data instead, which is why only those two looked correct.
        var columns = dataCols;

        // Prepend edit icon column when an edit page URL is provided
        if (editUrl) {
            columns.unshift({
                title:      '',
                field:      '_edit_icon',
                width:      40,
                minWidth:   40,
                resizable:  false,
                headerSort: false,
                formatter:  function (cell) {
                    var entryId = resolveEntryId(cell.getRow().getData());
                    if (!entryId || entryId === 'N/A') return '';
                    return '<a href="' + editUrl + '&entry=' + entryId + '" '
                         + 'target="_blank" '
                         + 'title="Edit entry #' + entryId + '" '
                         + 'style="text-decoration:none;font-size:15px;display:block;text-align:center;">✏️</a>';
                }
            });
        }

        return columns;
    }

    // ── Row count display ─────────────────────────────────────────────────

    function updateCount(table, countEl, total) {
        var active = table.getDataCount('active');
        if (active === total) {
            countEl.textContent = total + ' row' + (total !== 1 ? 's' : '');
        } else {
            countEl.textContent = active + ' of ' + total + ' rows';
        }
    }

    // ── Print (filtered rows only) ────────────────────────────────────────

    function formatDateTime(d) {
        var p = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
               ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function doPrint(rows, queryLabel, fontSize) {
        rows = stripHiddenKeys(rows);
        var keys = rows.length ? Object.keys(rows[0]) : [];
        var tableHtml = !keys.length ? '<p>No data.</p>' :
            '<table>' +
            '<thead><tr>' + keys.map(function (k) { return '<th>' + escapeHtml(k) + '</th>'; }).join('') + '</tr></thead>' +
            '<tbody>' + rows.map(function (row) {
                return '<tr>' + keys.map(function (k) {
                    return '<td>' + escapeHtml(row[k] ?? '') + '</td>';
                }).join('') + '</tr>';
            }).join('') + '</tbody></table>';

        var html = '<!DOCTYPE html><html><head><title>' + escapeHtml(queryLabel) + '</title>' +
            '<style>' +
            '* { box-sizing: border-box; margin: 0; padding: 0; }' +
            '@page { margin-bottom: 20mm; @bottom-right { content: "Page " counter(page) " of " counter(pages); font-size: 10px; color: #555; font-family: "Segoe UI", Arial, sans-serif; } }' +
            'body { font-family: "Segoe UI", Arial, sans-serif; font-size: ' + fontSize + 'px; color: #111; padding: 16px; }' +
            'h1 { font-size: 24px; margin-bottom: 4px; }' +
            '.meta { font-size: ' + fontSize + 'px; color: #666; margin-bottom: 14px; }' +
            'table { width: 100%; border-collapse: collapse; }' +
            'th { background: #1d2327; color: #fff; padding: 7px 10px; text-align: left; font-size: ' + fontSize + 'px; border: 1px solid #444; }' +
            'td { padding: 6px 10px; border: 1px solid #ddd; vertical-align: top; font-size: ' + fontSize + 'px; }' +
            'tr:nth-child(even) td { background: #f7f7f7; }' +
            '@media print {' +
            '  body { padding: 8px; }' +
            '  th, tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }' +
            '}' +
            '</style></head><body>' +
            '<h1>' + escapeHtml(queryLabel) + '</h1>' +
            '<p class="meta">Generated: ' + formatDateTime(new Date()) + ' &nbsp;|&nbsp; ' + rows.length + ' row(s)</p>' +
            tableHtml +
            '<script>window.onload = function() { window.print(); };<\/script>' +
            '</body></html>';

        var w = window.open('', '_blank');
        if (!w) { alert('Please allow pop-ups for this site to use Print.'); return; }
        w.document.write(html);
        w.document.close();
    }

    // ── XLSX export (server-side, preserves formatting) ───────────────────

    function doXLSXExport(rows, querySlug, queryLabel) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = ffapiFrontend.ajaxUrl;
        form.style.display = 'none';

        var fields = {
            action: 'ffapi_frontend_xlsx_filtered',
            query:  querySlug,
            nonce:  ffapiFrontend.nonce,
            rows:   JSON.stringify(rows),
            label:  queryLabel,
        };

        Object.keys(fields).forEach(function (name) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = name;
            input.value = fields[name];
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // ── Header wrap fix (overrides Tabulator inline height + nowrap) ─────
    // Tabulator sets height:25px inline on .tabulator-headers and .tabulator-col,
    // and tabulator_simple.css sets text-wrap-mode:nowrap. CSS !important cannot
    // reliably win, so we apply inline styles directly after render.

    function fixHeaderWrap( container ) {
        var wrapper = container.querySelector( '.tabulator-headers' );
        if ( ! wrapper ) return;

        function applyWrap() {
            // Row container
            wrapper.style.setProperty( 'height', 'auto', 'important' );

            // Each column header
            wrapper.querySelectorAll( '.tabulator-col' ).forEach( function ( col ) {
                col.style.setProperty( 'height', 'auto', 'important' );
            } );

            // Resize handles
            wrapper.querySelectorAll( '.tabulator-col-resize-handle' ).forEach( function ( h ) {
                h.style.setProperty( 'height', 'auto', 'important' );
            } );

            // Title elements
            wrapper.querySelectorAll( '.tabulator-col-content, .tabulator-col-title-holder, .tabulator-col-title' ).forEach( function ( el ) {
                el.style.setProperty( 'height',           'auto',    'important' );
                el.style.setProperty( 'white-space',      'normal',  'important' );
                el.style.setProperty( 'text-wrap-mode',   'wrap',    'important' );
                el.style.setProperty( 'overflow',         'visible', 'important' );
                el.style.setProperty( 'text-overflow',    'unset',   'important' );
            } );
        }

        applyWrap();

        // Re-apply if Tabulator re-renders the header (sort, resize, etc.)
        var observer = new MutationObserver( applyWrap );
        observer.observe( wrapper, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style' ] } );
    }

    // ── Table Body and Header Transparency + Font Inheritance Fix ─────────
    // Overrides Tabulator's inline styles and ensures theme font inheritance.
    function fixTableBodyAndHeaderStyles(container) {
        var header = container.querySelector('.tabulator-header');
        var cols = container.querySelectorAll('.tabulator-col');
        var rows = container.querySelectorAll('.tabulator-row');
        var cells = container.querySelectorAll('.tabulator-cell');
        var titles = container.querySelectorAll('.tabulator-col-title');

        function applyStyles() {
            // Header wrapper elements: red background so gaps between columns are filled
            if (header) {
                header.style.setProperty('background-color', 'var(--ffapi-header-bg, #f1f5f9)', 'important');
                header.style.setProperty('font-family', 'inherit', 'important');
            }
            var headerContents = container.querySelector('.tabulator-header-contents');
            if (headerContents) {
                headerContents.style.setProperty('background-color', 'var(--ffapi-header-bg, #f1f5f9)', 'important');
                headerContents.style.setProperty('font-family', 'inherit', 'important');
            }
            var headersRow = container.querySelector('.tabulator-headers');
            if (headersRow) {
                headersRow.style.setProperty('background-color', 'var(--ffapi-header-bg, #f1f5f9)', 'important');
                headersRow.style.setProperty('font-family', 'inherit', 'important');
            }

            // Column cells: red background + borders + inherited font
            cols.forEach(function (col) {
                col.style.setProperty('background-color', 'var(--ffapi-header-bg, #f1f5f9)', 'important');
                col.style.setProperty('border-right-color', 'var(--ffapi-header-border, #e2e8f0)', 'important');
                col.style.setProperty('font-family', 'inherit', 'important');
            });

            // ALL inner header elements → transparent background, white text, inherited font
            cols.forEach(function (col) {
                col.querySelectorAll('.tabulator-col-content, .tabulator-col-title-holder, .tabulator-col-title').forEach(function (el) {
                    el.style.setProperty('background-color', 'transparent', 'important');
                    el.style.setProperty('color', 'var(--ffapi-header-text, #1e293b)', 'important');
                    el.style.setProperty('font-family', 'inherit', 'important');
                });
            });

            // Row cells: transparent backgrounds so card background shows through
            rows.forEach(function (row) {
                var isEven = row.classList.contains('tabulator-row-even');
                var isOdd = row.classList.contains('tabulator-row-odd');
                var isHover = row.classList.contains('tabulator-row-hover');

                row.querySelectorAll('.tabulator-cell').forEach(function (cell) {
                    if (isHover) {
                        cell.style.setProperty('background-color', 'var(--ffapi-row-hover, #eff6ff)', 'important');
                    } else if (isEven) {
                        cell.style.setProperty('background-color', 'var(--ffapi-row-even, #ffffff)', 'important');
                    } else if (isOdd) {
                        cell.style.setProperty('background-color', 'var(--ffapi-row-odd, #f9fafb)', 'important');
                    }
                    cell.style.setProperty('font-family', 'inherit', 'important');
                });
            });

            // Force inherited font on all cells (catches any Tabulator-rendered cells not yet styled)
            cells.forEach(function (cell) {
                cell.style.setProperty('font-family', 'inherit', 'important');
            });
        }

        applyStyles();

        // Re-apply if Tabulator re-renders table elements
        var observer = new MutationObserver(applyStyles);
        if (header) observer.observe(header, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
        rows.forEach(function (row) {
            observer.observe(row, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
        });
    }

    // ── Pagination style fix (overrides theme button styles) ─────────────
    // Applied via inline styles so they win regardless of theme CSS load order.

    function fixPaginationStyles(container) {
        var footer = container.querySelector('.tabulator-footer');
        if (!footer) return;

        function applyStyles() {
            footer.querySelectorAll('.tabulator-page').forEach(function (btn) {
                var isActive   = btn.classList.contains('active');
                var isDisabled = btn.hasAttribute('disabled');
                btn.style.setProperty('color',       isDisabled ? 'var(--ffapi-page-disabled-text, #cbd5e1)' : isActive ? '#ffffff' : 'var(--ffapi-page-text, #1e293b)', 'important');
                btn.style.setProperty('background',  isDisabled ? 'var(--ffapi-page-disabled-bg, #f1f5f9)' : isActive ? 'var(--ffapi-accent, #3b82f6)' : 'var(--ffapi-page-bg, #ffffff)', 'important');
                btn.style.setProperty('border',      '1.5px solid ' + (isDisabled ? 'var(--ffapi-page-disabled-border, #e2e8f0)' : isActive ? 'var(--ffapi-accent, #3b82f6)' : 'var(--ffapi-border, #e2e8f0)'), 'important');
                btn.style.setProperty('box-shadow',  isActive ? '0 2px 6px rgba(59,130,246,.35)' : 'none', 'important');
                btn.style.setProperty('text-shadow', 'none', 'important');
                btn.style.setProperty('border-radius', '999px', 'important');
            });
        }

        applyStyles();

        // Re-apply when Tabulator updates pagination (class changes, new buttons)
        var observer = new MutationObserver(applyStyles);
        observer.observe(footer, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'disabled'] });
    }

    // ── Table transparency fix (overrides Tabulator theme backgrounds) ───
    // Tabulator themes set solid backgrounds on .tabulator and .tabulator-tableholder.
    // We force them transparent via inline styles so the page gradient shows through.

    function fixTableTransparency(container) {
        var tabulator = container.querySelector('.tabulator');
        var tableHolder = container.querySelector('.tabulator-tableholder');

        function applyStyles() {
            if (tabulator) {
                tabulator.style.setProperty('background-color', 'transparent', 'important');
            }
            if (tableHolder) {
                tableHolder.style.setProperty('background-color', 'transparent', 'important');
            }
        }

        applyStyles();

        // Re-apply if Tabulator re-renders
        var observer = new MutationObserver(applyStyles);
        if (tabulator) observer.observe(tabulator, { attributes: true, attributeFilter: ['style'] });
    }

    // Strip underscore-prefixed keys (e.g. _ffapi_parent_id) from row data
    // before handing off to print/XLSX so they don't leak into exports.
    function stripHiddenKeys(rows) {
        return rows.map(function (row) {
            var clean = {};
            Object.keys(row).forEach(function (k) {
                if (k.charAt(0) !== '_') clean[k] = row[k];
            });
            return clean;
        });
    }

    // ── Core: initialise one table container ──────────────────────────────

    function initTable(container) {
        var querySlug  = container.dataset.query;
        var queryLabel = container.dataset.label || querySlug;
        var editUrl    = container.dataset.editUrl || '';
        var calcCols   = [];
        try { calcCols = JSON.parse(container.dataset.calcCols || '[]'); } catch(e) {}
        var innerEl    = container.querySelector('.ffapi-tbl-wrapper');
        var statusEl   = container.querySelector('.ffapi-tbl-status');
        var countEl    = container.querySelector('.ffapi-tbl-count');
        var searchEl   = container.querySelector('.ffapi-tbl-search-input');
        var btnCSV     = container.querySelector('.ffapi-tbl-btn-csv');
        var btnXLSX    = container.querySelector('.ffapi-tbl-btn-xlsx');
        var btnPrint   = container.querySelector('.ffapi-tbl-btn-print');

        if (!innerEl || !querySlug) return;

        // Fetch all rows for this query
        var formData = new FormData();
        formData.append('action', 'ffapi_frontend_json');
        formData.append('query',  querySlug);
        formData.append('nonce',  ffapiFrontend.nonce);

        fetch(ffapiFrontend.ajaxUrl, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    statusEl.textContent = 'Error: ' + (data.data || 'Could not load data.');
                    statusEl.className = 'ffapi-tbl-error';
                    return;
                }

                var rows  = data.data.rows  || [];
                var total = rows.length;

                // Hide loading message
                statusEl.style.display = 'none';

                if (!rows.length) {
                    innerEl.innerHTML = '<p class="ffapi-tbl-empty">No data found for this query.</p>';
                    if (countEl) countEl.textContent = '0 rows';
                    return;
                }

                var columns  = buildColumns(rows, editUrl, calcCols);
                var fontSize = (ffapiFrontend && ffapiFrontend.fontSize) ? ffapiFrontend.fontSize : 11;

                // Initialise Tabulator
                var table = new Tabulator(innerEl, {
                    data:                  rows,
                    columns:               columns,
                    layout:                'fitColumns',
                    pagination:            'local',
                    paginationSize:        30,
                    movableColumns:        false,
                    resizableColumns:      true,
                    headerWordWrap:        true,
                    placeholder:           'No matching rows.',
                    dataFiltered: function () {
                        updateCount(table, countEl, total);
                    },
                    dataSorted: function () {
                        updateCount(table, countEl, total);
                    }
                });

                // Global count update
                table.on('tableBuilt', function () {
                    updateCount(table, countEl, total);
                    fixHeaderWrap(innerEl);
                    fixPaginationStyles(innerEl);
                    fixTableBodyAndHeaderStyles(innerEl);
                    fixTableTransparency(innerEl);

                    // Enable export buttons once table is built
                    [btnCSV, btnXLSX, btnPrint].forEach(function (b) {
                        if (b) b.removeAttribute('disabled');
                    });

                    // Re-fit columns when the CONTAINER (not just the window) changes width.
                    // fitColumns measures the container at build time — but inside a lazy-loaded
                    // Elementor flex/boxed container, the table often builds BEFORE Elementor
                    // applies the container's real (narrower) width. Tabulator then sizes columns
                    // to the too-wide initial measurement, producing a table wider than its section
                    // that overflows and shoves the page's flex layout around. Tabulator auto-redraws
                    // on window resize but NOT on container resize, so watch the container and redraw
                    // when its width settles. (debounced; guarded for older browsers.)
                    if (typeof ResizeObserver !== 'undefined') {
                        var lastW = Math.round(container.getBoundingClientRect().width);
                        var roTimer = null;
                        var ro = new ResizeObserver(function () {
                            var w = Math.round(container.getBoundingClientRect().width);
                            if (w === lastW) return;       // height-only change → ignore
                            lastW = w;
                            if (roTimer) clearTimeout(roTimer);
                            roTimer = setTimeout(function () {
                                try { table.redraw(true); } catch (e) {}
                            }, 120);
                        });
                        ro.observe(container);
                    }
                    // One deferred redraw catches the common case where Elementor constrains the
                    // container in the same frame the table built (no further resize event fires).
                    setTimeout(function () { try { table.redraw(true); } catch (e) {} }, 0);
                });

                // Wire up buttons explicitly
                if (btnCSV) {
                    btnCSV.addEventListener('click', function () {
                        downloadSafeCsv(
                            table.getData('active'),
                            'ffapi-' + querySlug + '-' + todayStr() + '.csv'
                        );
                    });
                }
                if (btnXLSX) {
                    btnXLSX.addEventListener('click', function () {
                        var activeRows = stripHiddenKeys(table.getData('active'));
                        if (!activeRows.length) { alert('No data to export.'); return; }
                        doXLSXExport(activeRows, querySlug, queryLabel);
                    });
                }
                if (btnPrint) {
                    btnPrint.addEventListener('click', function () {
                        var activeRows = stripHiddenKeys(table.getData('active'));
                        if (!activeRows.length) { alert('No data to print.'); return; }
                        doPrint(activeRows, queryLabel, fontSize);
                    });
                }

                // Global search – filters all columns as user types
                if (searchEl) {
                    searchEl.addEventListener('input', debounce(function () {
                        var term = searchEl.value.trim().toLowerCase();
                        if (!term) {
                            table.clearFilter();
                        } else {
                            table.setFilter(function (rowData) {
                                return Object.values(rowData).some(function (v) {
                                    return v !== null && v !== undefined &&
                                           String(v).toLowerCase().indexOf(term) !== -1;
                                });
                            });
                        }
                    }, 250));
                }
            })
            .catch(function (err) {
                statusEl.textContent = 'Failed to load data. Please refresh and try again.';
                statusEl.className = 'ffapi-tbl-error';
                console.error('ffapi table error:', err);
            });
    }

    // ── Boot: initialise all tables on the page ───────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ffapi-table-container').forEach(initTable);
    });

})();
