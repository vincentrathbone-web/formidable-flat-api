/**
 * Formidable Flat API - Frontend JavaScript
 * Version: 2.1.0
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        
        // Handle all frontend buttons and icons
        const buttons = document.querySelectorAll('.ffapi-frontend-btn, .ffapi-frontend-icon');
        
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                const querySlug = btn.getAttribute('data-query');
                const action = btn.getAttribute('data-action');
                const label = btn.getAttribute('data-label');
                
                if (!querySlug || !action) {
                    console.error('Missing query or action attribute');
                    return;
                }
                
                // Add loading state
                btn.classList.add('ffapi-loading');
                
                if (action === 'print') {
                    handlePrint(querySlug, label, btn);
                } else if (action === 'csv') {
                    handleCSV(querySlug, label, btn);
                } else if (action === 'xlsx') {
                    handleXLSX(querySlug, label, btn);
                }
            });
        });
        
    });

    /**
     * Handle print action
     */
    function handlePrint(querySlug, label, btn) {
        const printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Loading…</title></head><body><p style="font-family:sans-serif;padding:20px;">⏳ Loading data for printing…</p></body></html>');
        
        const formData = new FormData();
        formData.append('action', 'ffapi_frontend_print');
        formData.append('query', querySlug);
        formData.append('nonce', ffapiFrontend.nonce);
        
        fetch(ffapiFrontend.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            btn.classList.remove('ffapi-loading');
            
            if (!data.success) {
                printWindow.document.write('<p style="padding:20px;color:red;">Error: ' + (data.data || 'Unknown error') + '</p>');
                return;
            }
            
            const rows = data.data.rows || [];
            const queryLabel = data.data.label || label || querySlug;
            const fontSize = data.data.font_size || 11;
            const keys = rows.length ? [...new Set(rows.flatMap(r => Object.keys(r)))] : [];
            
            const tableHtml = keys.length === 0 ? '<p>No data to print.</p>' :
                '<table>' +
                '<thead><tr>' + keys.map(k => '<th>' + escapeHtml(k) + '</th>').join('') + '</tr></thead>' +
                '<tbody>' + rows.map(row =>
                    '<tr>' + keys.map(k => {
                        const v = row[k] !== undefined ? row[k] : '';
                        const displayValue = typeof v === 'object' ? JSON.stringify(v) : v;
                        return '<td>' + escapeHtml(displayValue) + '</td>';
                    }).join('') + '</tr>'
                ).join('') + '</tbody></table>';
            
            const html = '<!DOCTYPE html><html><head><title>' + escapeHtml(queryLabel) + '</title>' +
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
                '    body { padding: 8px; }' +
                '    th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }' +
                '    tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }' +
                '}' +
                '</style>' +
                '</head><body>' +
                '<h1>' + escapeHtml(queryLabel) + '</h1>' +
                '<p class="meta">Generated: ' + formatDateTime(new Date()) + ' &nbsp;|&nbsp; ' + rows.length + ' row(s)</p>' +
                tableHtml +
                '<script>window.onload = function() { window.print(); };<\/script>' +
                '</body></html>';
            
            printWindow.document.write(html);
            printWindow.document.close();
        })
        .catch(error => {
            btn.classList.remove('ffapi-loading');
            printWindow.document.write('<p style="padding:20px;color:red;">Error loading data: ' + error.message + '</p>');
            console.error('Print error:', error);
        });
    }

    /**
     * Handle CSV export action
     */
    function handleCSV(querySlug, label, btn) {
        // Create a form and submit it to trigger download
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ffapiFrontend.ajaxUrl;
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'ffapi_frontend_csv';
        form.appendChild(actionInput);
        
        const queryInput = document.createElement('input');
        queryInput.type = 'hidden';
        queryInput.name = 'query';
        queryInput.value = querySlug;
        form.appendChild(queryInput);
        
        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'nonce';
        nonceInput.value = ffapiFrontend.nonce;
        form.appendChild(nonceInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        
        // Remove loading state after a delay
        setTimeout(function() {
            btn.classList.remove('ffapi-loading');
        }, 1000);
    }

    /**
     * Handle XLSX export action
     */
    function handleXLSX(querySlug, label, btn) {
        // Create a form and submit it to trigger download
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ffapiFrontend.ajaxUrl;
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'ffapi_frontend_xlsx';
        form.appendChild(actionInput);
        
        const queryInput = document.createElement('input');
        queryInput.type = 'hidden';
        queryInput.name = 'query';
        queryInput.value = querySlug;
        form.appendChild(queryInput);
        
        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'nonce';
        nonceInput.value = ffapiFrontend.nonce;
        form.appendChild(nonceInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        
        // Remove loading state after a delay
        setTimeout(function() {
            btn.classList.remove('ffapi-loading');
        }, 1000);
    }

    /**
     * Format a Date as yyyy-mm-dd HH:mm
     */
    function formatDateTime(d) {
        var p = function(n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
               ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

})();
