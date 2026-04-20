(function($) {
    'use strict';

    $(document).ready(function() {

        // ── Instant search on list page ──
        var searchInput = $('#html-page-search');
        if (searchInput.length) {
            var spinner = $('.html-page-search-spinner');
            var countLabel = $('.html-page-search-count');
            var tbody = $('#html-pages-table tbody');
            var timer = null;
            var xhr = null;
            var originalRows = tbody.html();

            searchInput.on('input', function() {
                var term = $.trim($(this).val());
                clearTimeout(timer);
                if (xhr) xhr.abort();

                if (term === '') {
                    // Restore original rows
                    spinner.removeClass('is-active');
                    countLabel.text('');
                    tbody.html(originalRows);
                    return;
                }

                spinner.addClass('is-active');
                timer = setTimeout(function() {
                    xhr = $.post(htmlPageAdmin.ajaxUrl, {
                        action: 'html_page_search',
                        nonce: htmlPageAdmin.nonce,
                        term: term
                    }, function(res) {
                        spinner.removeClass('is-active');
                        if (!res.success) return;

                        var rows = res.data;
                        countLabel.text(rows.length + ' result' + (rows.length !== 1 ? 's' : ''));

                        if (rows.length === 0) {
                            tbody.html('<tr><td colspan="5">No pages matching &ldquo;' + $('<span>').text(term).html() + '&rdquo;</td></tr>');
                            return;
                        }

                        var html = '';
                        for (var i = 0; i < rows.length; i++) {
                            var r = rows[i];
                            html += '<tr>'
                                + '<td><strong>' + r.title + '</strong></td>'
                                + '<td><code>' + r.slug + '</code></td>'
                                + '<td><a href="' + r.url + '" target="_blank">' + r.url_display + '</a></td>'
                                + '<td>' + r.created + '</td>'
                                + '<td>'
                                    + '<a href="' + r.edit_url + '">Edit</a> | '
                                    + '<a href="' + r.wp_edit_url + '">WP Edit</a> | '
                                    + '<a href="' + r.download_url + '">Download</a> | '
                                    + '<a href="' + r.delete_url + '" onclick="return confirm(\'Are you sure you want to delete this page?\');" style="color:#a00;">Delete</a>'
                                + '</td>'
                                + '</tr>';
                        }
                        tbody.html(html);
                    });
                }, 250);
            });

            // Focus search on page load
            searchInput.focus();
        }

        // ── Quick Import ──
        var importToggle = $('#html-import-toggle');
        var importPanel = $('#html-import-panel');
        if (importToggle.length) {
            var importDrop = $('#html-import-drop');
            var importFile = $('#html-import-file');
            var importResults = $('#html-import-results');

            importToggle.on('click', function() {
                importPanel.slideToggle(150);
            });

            function importFiles(files) {
                for (var i = 0; i < files.length; i++) {
                    (function(file) {
                        var ext = file.name.split('.').pop().toLowerCase();
                        if (ext !== 'html' && ext !== 'htm') return;

                        var title = file.name.replace(/\.[^/.]+$/, '')
                            .replace(/[-_]/g, ' ')
                            .replace(/\b\w/g, function(l) { return l.toUpperCase(); });

                        // Add a pending row
                        var row = $('<div class="html-import-row">' +
                            '<span class="html-import-row-title">' + $('<span>').text(title).html() + '</span>' +
                            '<span class="html-import-row-status"><span class="html-import-spinner"></span> Publishing...</span>' +
                            '</div>');
                        importResults.append(row);

                        var reader = new FileReader();
                        reader.onload = function(e) {
                            $.post(htmlPageAdmin.ajaxUrl, {
                                action: 'html_page_import',
                                nonce: htmlPageAdmin.nonce,
                                title: title,
                                html: e.target.result
                            }, function(res) {
                                if (res.success) {
                                    var d = res.data;
                                    row.find('.html-import-row-status').html(
                                        '<a href="' + d.url + '" target="_blank">' + d.slug + '</a> ' +
                                        '<a href="' + d.edit_url + '" class="html-import-edit">Edit</a>'
                                    );
                                    row.addClass('html-import-row-done');
                                    // Reload page list after short delay
                                    clearTimeout(importReloadTimer);
                                    importReloadTimer = setTimeout(function() { location.reload(); }, 1500);
                                } else {
                                    row.find('.html-import-row-status').text('Failed').addClass('html-import-row-error');
                                }
                            }).fail(function() {
                                row.find('.html-import-row-status').text('Failed').addClass('html-import-row-error');
                            });
                        };
                        reader.readAsText(file);
                    })(files[i]);
                }
            }

            var importReloadTimer = null;

            importFile.on('change', function() {
                if (this.files.length) importFiles(this.files);
                this.value = '';
            });

            importDrop.on('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('html-import-dragover');
            }).on('dragleave drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('html-import-dragover');
            }).on('drop', function(e) {
                var files = e.originalEvent.dataTransfer.files;
                if (files.length) importFiles(files);
            });
        }

        var htmlContent = $('#html_content');
        var fileInput = $('#html-file-input');
        var titleInput = $('#title');
        var slugInput = $('#slug');
        var slugPreview = $('#slug-preview');

        if (!htmlContent.length) {
            return;
        }

        // Slug auto-generation from title
        var slugManuallyEdited = slugInput.val() !== '';

        titleInput.on('input', function() {
            if (!slugManuallyEdited) {
                var slug = $(this).val()
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
                slugInput.val(slug);
                slugPreview.text(slug || 'your-slug');
            }
        });

        slugInput.on('input', function() {
            slugManuallyEdited = true;
            var slug = $(this).val()
                .toLowerCase()
                .replace(/[^a-z0-9-]+/g, '-')
                .replace(/^-+|-+$/g, '');
            $(this).val(slug);
            slugPreview.text(slug || 'your-slug');
        });

        // Handle HTML file upload
        fileInput.on('change', function(e) {
            var file = this.files[0];
            if (!file) return;

            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'html' && ext !== 'htm') {
                alert('Please select an HTML file (.html or .htm)');
                this.value = '';
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                htmlContent.val(e.target.result);

                // Auto-fill title if empty
                if (!titleInput.val()) {
                    var filename = file.name.replace(/\.[^/.]+$/, '');
                    var title = filename
                        .replace(/[-_]/g, ' ')
                        .replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                    titleInput.val(title);
                    titleInput.trigger('input');
                }
            };
            reader.onerror = function() {
                alert('Failed to read file');
            };
            reader.readAsText(file);
        });

        // Drag and drop support for textarea
        htmlContent.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('drag-over');
        });

        htmlContent.on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');
        });

        htmlContent.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');

            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                var file = files[0];
                var ext = file.name.split('.').pop().toLowerCase();

                if (ext !== 'html' && ext !== 'htm') {
                    alert('Please drop an HTML file (.html or .htm)');
                    return;
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    htmlContent.val(e.target.result);

                    if (!titleInput.val()) {
                        var filename = file.name.replace(/\.[^/.]+$/, '');
                        var title = filename
                            .replace(/[-_]/g, ' ')
                            .replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        titleInput.val(title);
                        titleInput.trigger('input');
                    }
                };
                reader.readAsText(file);
            }
        });

        // Tab key support in textarea
        htmlContent.on('keydown', function(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                var start = this.selectionStart;
                var end = this.selectionEnd;
                var value = $(this).val();
                $(this).val(value.substring(0, start) + '  ' + value.substring(end));
                this.selectionStart = this.selectionEnd = start + 2;
            }
        });

    });

})(jQuery);
