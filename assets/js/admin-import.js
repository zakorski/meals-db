/**
 * Client Import JavaScript
 *
 * Handles CSV upload, validation, and import process.
 */

(function($) {
    'use strict';

    let fileId = null;
    let totalRows = 0;
    let importId = null;

    $(document).ready(function() {
        initUploadArea();
        initButtons();
    });

    /**
     * Initialize upload area
     */
    function initUploadArea() {
        const uploadArea = $('#mealsdb-upload-area');
        const fileInput = $('#mealsdb-csv-file');
        const browseBtn = $('#mealsdb-browse-btn');

        // Click to browse
        browseBtn.on('click', function() {
            fileInput.click();
        });

        uploadArea.on('click', function(e) {
            if (e.target === uploadArea[0]) {
                fileInput.click();
            }
        });

        // File input change
        fileInput.on('change', function() {
            const file = this.files[0];
            if (file) {
                uploadFile(file);
            }
        });

        // Drag and drop
        uploadArea.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragging');
        });

        uploadArea.on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragging');
        });

        uploadArea.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragging');

            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                uploadFile(files[0]);
            }
        });
    }

    /**
     * Initialize buttons
     */
    function initButtons() {
        $('#mealsdb-import-btn').on('click', handleImport);
        $('#mealsdb-cancel-btn').on('click', resetToUpload);
        $('#mealsdb-import-another-btn').on('click', resetToUpload);
    }

    /**
     * Upload and validate CSV file
     */
    function uploadFile(file) {
        // Validate file type
        if (!file.name.endsWith('.csv')) {
            showError('Please upload a CSV file.');
            return;
        }

        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            showError('File is too large. Maximum size is 5MB.');
            return;
        }

        // Show progress
        $('#mealsdb-upload-area').hide();
        $('#mealsdb-upload-error').hide();
        $('#mealsdb-upload-progress').show();

        // Create form data
        const formData = new FormData();
        formData.append('action', 'mealsdb_validate_csv');
        formData.append('nonce', mealsdbImport.nonce);
        formData.append('csv_file', file);

        // Upload and validate
        $.ajax({
            url: mealsdbImport.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    fileId = response.data.file_id;
                    totalRows = response.data.total_rows;
                    showPreview(response.data);
                } else {
                    showError(response.data.message || 'Validation failed.');
                    resetUploadArea();
                }
            },
            error: function() {
                showError('An error occurred while uploading the file.');
                resetUploadArea();
            }
        });
    }

    /**
     * Show preview section
     */
    function showPreview(data) {
        $('#mealsdb-upload-section').hide();
        $('#mealsdb-preview-section').show();

        // Show stats
        const stats = data.stats;
        const statsHtml = `
            <div class="mealsdb-stats-grid">
                <div class="mealsdb-stat">
                    <div class="mealsdb-stat-value">${data.total_rows}</div>
                    <div class="mealsdb-stat-label">Total Clients</div>
                </div>
                <div class="mealsdb-stat">
                    <div class="mealsdb-stat-value">${stats.with_initials}</div>
                    <div class="mealsdb-stat-label">With Initials</div>
                </div>
                <div class="mealsdb-stat">
                    <div class="mealsdb-stat-value">${stats.need_initials}</div>
                    <div class="mealsdb-stat-label">Need Initials</div>
                </div>
                <div class="mealsdb-stat">
                    <div class="mealsdb-stat-value">${stats.with_emails}</div>
                    <div class="mealsdb-stat-label">With Emails</div>
                </div>
                <div class="mealsdb-stat">
                    <div class="mealsdb-stat-value">${stats.encrypted_individual_ids}</div>
                    <div class="mealsdb-stat-label">Individual IDs</div>
                </div>
                <div class="mealsdb-stat">
                    <div class="mealsdb-stat-value">${stats.encrypted_requisition_ids}</div>
                    <div class="mealsdb-stat-label">Requisition IDs</div>
                </div>
            </div>
        `;
        $('#mealsdb-stats').html(statsHtml);

        // Show preview table
        const tbody = $('#mealsdb-preview-tbody');
        tbody.empty();

        data.preview.forEach(function(client) {
            const row = `
                <tr>
                    <td>${escapeHtml(client.first_name)} ${escapeHtml(client.last_name)}</td>
                    <td>${escapeHtml(client.client_type || 'Private')}</td>
                    <td>${escapeHtml(client.initials_delivery || '(will generate)')}</td>
                    <td>${escapeHtml(client.client_email || '(none)')}</td>
                    <td>${escapeHtml(client.phone_primary || '(none)')}</td>
                </tr>
            `;
            tbody.append(row);
        });
    }

    /**
     * Handle import button click
     */
    function handleImport() {
        const dryRun = $('#mealsdb-dry-run').is(':checked');

        if (!dryRun) {
            if (!confirm(mealsdbImport.strings.confirmImport)) {
                return;
            }
        }

        // Show import section
        $('#mealsdb-preview-section').hide();
        $('#mealsdb-import-section').show();

        // Start import
        $.ajax({
            url: mealsdbImport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mealsdb_import_clients',
                nonce: mealsdbImport.nonce,
                file_id: fileId,
                dry_run: dryRun ? 'true' : 'false'
            },
            success: function(response) {
                if (response.success) {
                    importId = response.data.import_id;
                    updateProgress(100);
                    showResults(response.data);
                } else {
                    // Extract import_id from error response if available
                    if (response.data && response.data.import_id) {
                        importId = response.data.import_id;
                    }
                    showError(response.data.message || 'Import failed.');
                    // Don't reset if we have an import_id - show results with error
                    if (importId) {
                        showResults({
                            stats: { total: 0, success: 0, errors: 0, wp_users_created: 0, wp_users_existing: 0 },
                            errors: [response.data.message || 'Import failed.'],
                            dry_run: false
                        });
                    } else {
                        resetToUpload();
                    }
                }
            },
            error: function() {
                showError('An error occurred during import.');
                // Keep import_id if it exists, otherwise reset
                if (!importId) {
                    resetToUpload();
                }
            }
        });
    }

    /**
     * Update progress bar
     */
    function updateProgress(percent) {
        $('#mealsdb-progress-bar').css('width', percent + '%');
        $('#mealsdb-progress-text').text(Math.round(percent) + '%');
    }

    /**
     * Show import results
     */
    function showResults(data) {
        $('#mealsdb-import-section').hide();
        $('#mealsdb-results-section').show();

        const stats = data.stats;
        const isDryRun = data.dry_run;
        const hasErrors = data.errors && data.errors.length > 0;
        const allFailed = stats.total === 0 || (stats.success === 0 && stats.errors > 0);

        let summaryHtml = '<div class="mealsdb-results-stats">';

        if (allFailed && hasErrors) {
            summaryHtml += '<div class="notice notice-error inline"><p><strong>Import Failed</strong> - The import encountered a critical error.</p></div>';
        } else if (isDryRun) {
            summaryHtml += '<div class="notice notice-info inline"><p><strong>Dry Run Complete</strong> - No changes were made to the database.</p></div>';
        } else if (hasErrors && stats.success > 0) {
            summaryHtml += '<div class="notice notice-warning inline"><p><strong>Import Completed with Errors</strong> - Some rows could not be imported.</p></div>';
        } else {
            summaryHtml += '<div class="notice notice-success inline"><p><strong>Import Complete!</strong></p></div>';
        }

        summaryHtml += `
            <table class="mealsdb-stats-table">
                <tr>
                    <th>Total Processed:</th>
                    <td>${stats.total}</td>
                </tr>
                <tr>
                    <th>Successfully Imported:</th>
                    <td>${stats.success}</td>
                </tr>
                <tr>
                    <th>Errors:</th>
                    <td>${stats.errors}</td>
                </tr>
                <tr>
                    <th>WP Users Created:</th>
                    <td>${stats.wp_users_created}</td>
                </tr>
                <tr>
                    <th>WP Users Existing:</th>
                    <td>${stats.wp_users_existing}</td>
                </tr>
            </table>
        `;
        summaryHtml += '</div>';

        // Add download log button
        if (importId) {
            summaryHtml += '<div class="mealsdb-log-download" style="margin-top: 20px;">';
            summaryHtml += '<a href="' + mealsdbImport.ajaxUrl + '?action=mealsdb_download_import_log&import_id=' +
                           encodeURIComponent(importId) + '&nonce=' + encodeURIComponent(mealsdbImport.nonce) +
                           '" class="button button-secondary" download>';
            summaryHtml += '📄 Download Detailed Import Log';
            summaryHtml += '</a>';
            if (hasErrors) {
                summaryHtml += '<p class="description">Download a detailed log file to troubleshoot import errors. Shows all processed rows, field mappings, and error details.</p>';
            } else {
                summaryHtml += '<p class="description">Download a detailed log file showing all processed rows and field mappings.</p>';
            }
            summaryHtml += '</div>';
        }

        $('#mealsdb-results-summary').html(summaryHtml);

        // Show errors if any
        if (data.errors && data.errors.length > 0) {
            const errorsList = $('#mealsdb-errors-list');
            errorsList.empty();

            data.errors.slice(0, 20).forEach(function(error) {
                errorsList.append('<li>' + escapeHtml(error) + '</li>');
            });

            if (data.errors.length > 20) {
                errorsList.append('<li><em>... and ' + (data.errors.length - 20) + ' more errors</em></li>');
            }

            $('#mealsdb-results-errors').show();
        }
    }

    /**
     * Show error message
     */
    function showError(message) {
        $('#mealsdb-upload-error p').text(message);
        $('#mealsdb-upload-error').show();
    }

    /**
     * Reset to upload area
     */
    function resetToUpload() {
        $('#mealsdb-preview-section').hide();
        $('#mealsdb-import-section').hide();
        $('#mealsdb-results-section').hide();
        $('#mealsdb-upload-section').show();
        resetUploadArea();
        fileId = null;
        totalRows = 0;
        importId = null;
    }

    /**
     * Reset upload area
     */
    function resetUploadArea() {
        $('#mealsdb-upload-progress').hide();
        $('#mealsdb-upload-area').show();
        $('#mealsdb-csv-file').val('');
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);
