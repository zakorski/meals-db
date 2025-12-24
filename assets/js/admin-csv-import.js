/**
 * CSV Import UI - JavaScript
 */

(function($) {
    'use strict';

    let fileId = null;
    let importId = null;
    let progressTimer = null;

    $(document).ready(function() {
        initUploadArea();
        initButtons();
    });

    function initUploadArea() {
        const uploadArea = $('#mealsdb-csv-upload-area');
        const fileInput = $('#mealsdb-csv-file');
        const browseBtn = $('#mealsdb-csv-browse-btn');

        // Browse button
        browseBtn.on('click', function() {
            fileInput.click();
        });

        // File selection
        fileInput.on('change', function() {
            if (this.files.length > 0) {
                handleFileUpload(this.files[0]);
            }
        });

        // Drag and drop
        uploadArea.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });

        uploadArea.on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });

        uploadArea.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');

            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFileUpload(files[0]);
            }
        });
    }

    function initButtons() {
        $('#mealsdb-csv-import-btn').on('click', startImport);
        $('#mealsdb-csv-cancel-btn').on('click', resetImport);
        $('#mealsdb-csv-import-another-btn').on('click', resetImport);
        $('#mealsdb-csv-download-log-btn').on('click', downloadLog);
    }

    function handleFileUpload(file) {
        // Validate file type
        if (!file.name.endsWith('.csv')) {
            showError('Please select a CSV file.');
            return;
        }

        // Validate file size (10MB)
        if (file.size > 10 * 1024 * 1024) {
            showError('File is too large. Maximum size is 10MB.');
            return;
        }

        // Show progress
        $('#mealsdb-csv-upload-area').hide();
        $('#mealsdb-csv-upload-progress').show();
        $('#mealsdb-csv-upload-error').hide();

        // Upload file
        const formData = new FormData();
        formData.append('action', 'mealsdb_csv_validate');
        formData.append('nonce', mealsdbCSVImport.nonce);
        formData.append('csv_file', file);

        $.ajax({
            url: mealsdbCSVImport.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    fileId = response.data.file_id;
                    showPreview(response.data);
                } else {
                    showError(response.data.message || 'Validation failed');
                }
            },
            error: function() {
                showError('Upload failed. Please try again.');
            }
        });
    }

    function showPreview(data) {
        // Hide upload section
        $('#mealsdb-csv-upload-section').hide();

        // Show config section
        $('#mealsdb-csv-config-section').show();

        // Show stats
        const statsHtml = `
            <div class="mealsdb-csv-stat-box">
                <div class="mealsdb-csv-stat-number">${data.total_rows}</div>
                <div class="mealsdb-csv-stat-label">Total Rows</div>
            </div>
        `;
        $('#mealsdb-csv-stats').html(statsHtml);

        // Show preview table
        const tbody = $('#mealsdb-csv-preview-tbody');
        tbody.empty();

        if (data.preview && data.preview.length > 0) {
            data.preview.forEach(function(row) {
                const tr = $('<tr>');
                tr.append($('<td>').text(row.user_id || '-'));
                tr.append($('<td>').text(row.name || '-'));
                tr.append($('<td>').text(row.email || '-'));
                tr.append($('<td>').text(row.type || '-'));
                tbody.append(tr);
            });
        } else {
            tbody.html('<tr><td colspan="4">No preview data available</td></tr>');
        }
    }

    function startImport() {
        if (!fileId) {
            showError('No file uploaded');
            return;
        }

        const dryRun = $('#mealsdb-csv-dry-run').is(':checked');
        const updateUsers = $('#mealsdb-csv-update-users').is(':checked');
        const updateClients = $('#mealsdb-csv-update-clients').is(':checked');

        if (!updateUsers && !updateClients) {
            showError('Please select at least one import option');
            return;
        }

        // Confirm
        if (!dryRun && !confirm(mealsdbCSVImport.strings.confirmImport)) {
            return;
        }

        // Hide config, show progress
        $('#mealsdb-csv-config-section').hide();
        $('#mealsdb-csv-progress-section').show();

        // Start import
        $.ajax({
            url: mealsdbCSVImport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mealsdb_csv_import',
                nonce: mealsdbCSVImport.nonce,
                file_id: fileId,
                dry_run: dryRun ? 'true' : 'false',
                update_users: updateUsers ? 'true' : 'false',
                update_clients: updateClients ? 'true' : 'false'
            },
            success: function(response) {
                if (response.success) {
                    importId = response.data.import_id;

                    // For short imports, show results immediately
                    setTimeout(function() {
                        showResults(response.data);
                    }, 500);
                } else {
                    showError(response.data.message || 'Import failed');
                    resetToUpload();
                }
            },
            error: function() {
                showError('Import failed. Please try again.');
                resetToUpload();
            }
        });

        // Start progress polling
        startProgressPolling();
    }

    function startProgressPolling() {
        progressTimer = setInterval(function() {
            if (!importId) return;

            $.ajax({
                url: mealsdbCSVImport.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mealsdb_csv_progress',
                    nonce: mealsdbCSVImport.nonce,
                    import_id: importId
                },
                success: function(response) {
                    if (response.success) {
                        updateProgress(response.data);
                    }
                }
            });
        }, 1000);
    }

    function updateProgress(data) {
        const percent = data.percent || 0;
        $('#mealsdb-csv-progress-bar').css('width', percent + '%');
        $('#mealsdb-csv-progress-text').text(Math.round(percent) + '%');

        const statusHtml = `
            <p>Processing row ${data.current || 0} of ${data.total || 0}...</p>
        `;
        $('#mealsdb-csv-import-status').html(statusHtml);
    }

    function showResults(data) {
        // Stop progress polling
        if (progressTimer) {
            clearInterval(progressTimer);
            progressTimer = null;
        }

        // Hide progress, show results
        $('#mealsdb-csv-progress-section').hide();
        $('#mealsdb-csv-results-section').show();

        // Build summary
        const stats = data.stats || {};
        const isDryRun = data.dry_run;

        let summaryHtml = '<div class="mealsdb-csv-summary-grid">';

        if (isDryRun) {
            summaryHtml += '<div class="notice notice-info inline"><p><strong>DRY RUN MODE:</strong> No data was modified. This is a preview of what would happen.</p></div>';
        }

        summaryHtml += `
            <div class="mealsdb-csv-stat-item">
                <div class="mealsdb-csv-stat-value">${stats.total || 0}</div>
                <div class="mealsdb-csv-stat-title">Total Rows</div>
            </div>
            <div class="mealsdb-csv-stat-item mealsdb-csv-stat-success">
                <div class="mealsdb-csv-stat-value">${stats.wp_users_updated || 0}</div>
                <div class="mealsdb-csv-stat-title">WordPress Users Updated</div>
            </div>
            <div class="mealsdb-csv-stat-item mealsdb-csv-stat-success">
                <div class="mealsdb-csv-stat-value">${stats.clients_created || 0}</div>
                <div class="mealsdb-csv-stat-title">Clients Created</div>
            </div>
            <div class="mealsdb-csv-stat-item mealsdb-csv-stat-info">
                <div class="mealsdb-csv-stat-value">${stats.clients_updated || 0}</div>
                <div class="mealsdb-csv-stat-title">Clients Updated (NULL-fill)</div>
            </div>
            <div class="mealsdb-csv-stat-item mealsdb-csv-stat-error">
                <div class="mealsdb-csv-stat-value">${stats.errors || 0}</div>
                <div class="mealsdb-csv-stat-title">Errors</div>
            </div>
        `;
        summaryHtml += '</div>';

        $('#mealsdb-csv-results-summary').html(summaryHtml);

        // Show errors if any
        if (data.errors && data.errors.length > 0) {
            const errorsList = $('#mealsdb-csv-errors-list');
            errorsList.empty();
            data.errors.forEach(function(error) {
                errorsList.append($('<li>').text(error));
            });
            $('#mealsdb-csv-results-errors').show();
        }

        // Set download log button
        if (importId) {
            $('#mealsdb-csv-download-log-btn').attr('href',
                mealsdbCSVImport.ajaxUrl +
                '?action=mealsdb_csv_download_log&nonce=' +
                mealsdbCSVImport.nonce +
                '&import_id=' + importId
            );
        }
    }

    function downloadLog(e) {
        e.preventDefault();
        if (importId) {
            window.location.href = mealsdbCSVImport.ajaxUrl +
                '?action=mealsdb_csv_download_log&nonce=' +
                mealsdbCSVImport.nonce +
                '&import_id=' + importId;
        }
    }

    function showError(message) {
        $('#mealsdb-csv-upload-error p').text(message);
        $('#mealsdb-csv-upload-error').show();
        $('#mealsdb-csv-upload-progress').hide();
        $('#mealsdb-csv-upload-area').show();
    }

    function resetImport() {
        fileId = null;
        importId = null;

        if (progressTimer) {
            clearInterval(progressTimer);
            progressTimer = null;
        }

        $('#mealsdb-csv-config-section').hide();
        $('#mealsdb-csv-progress-section').hide();
        $('#mealsdb-csv-results-section').hide();
        $('#mealsdb-csv-upload-section').show();

        $('#mealsdb-csv-upload-area').show();
        $('#mealsdb-csv-upload-progress').hide();
        $('#mealsdb-csv-upload-error').hide();

        $('#mealsdb-csv-file').val('');
        $('#mealsdb-csv-dry-run').prop('checked', true);
        $('#mealsdb-csv-update-users').prop('checked', true);
        $('#mealsdb-csv-update-clients').prop('checked', true);
    }

    function resetToUpload() {
        setTimeout(function() {
            resetImport();
        }, 3000);
    }

})(jQuery);
