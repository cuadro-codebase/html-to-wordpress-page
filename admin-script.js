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

        // ── Import Panel ──
        var importToggle = $('#html-import-toggle');
        var importPanel = $('#html-import-panel');
        if (importToggle.length) {
            var importDrop = $('#html-import-drop');
            var importFile = $('#html-import-file');
            var importQueue = $('#html-import-queue');
            var importActions = $('#html-import-actions');
            var listSection = $('#html-page-list-section');
            var pendingFiles = []; // [{title, slug, html, el}]

            function slugify(str) {
                return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            }

            importToggle.on('click', function() {
                var showing = importPanel.is(':visible');
                if (showing) {
                    // Close import, show list
                    importPanel.slideUp(200);
                    listSection.slideDown(200);
                } else {
                    // Open import, hide list
                    listSection.slideUp(200);
                    importPanel.slideDown(200);
                }
            });

            function addFilesToQueue(files) {
                for (var i = 0; i < files.length; i++) {
                    (function(file) {
                        var ext = file.name.split('.').pop().toLowerCase();
                        if (ext !== 'html' && ext !== 'htm') return;

                        var baseName = file.name.replace(/\.[^/.]+$/, '');
                        var title = baseName.replace(/[-_]/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        var slug = slugify(baseName);

                        var idx = pendingFiles.length;
                        var row = $('<div class="html-import-item" data-idx="' + idx + '">' +
                            '<div class="html-import-item-fields">' +
                                '<div class="html-import-field">' +
                                    '<label>Title</label>' +
                                    '<input type="text" class="html-import-title" value="' + $('<span>').text(title).html() + '">' +
                                '</div>' +
                                '<div class="html-import-field">' +
                                    '<label>Slug</label>' +
                                    '<input type="text" class="html-import-slug" value="' + $('<span>').text(slug).html() + '">' +
                                '</div>' +
                                '<button type="button" class="html-import-remove" data-tooltip="Remove from queue">&times;</button>' +
                            '</div>' +
                            '<div class="html-import-item-status"></div>' +
                        '</div>');

                        importQueue.append(row);

                        var entry = { title: title, slug: slug, html: null, el: row };
                        pendingFiles.push(entry);

                        // Auto-sync title -> slug
                        row.find('.html-import-title').on('input', function() {
                            var t = $(this).val();
                            entry.title = t;
                            var s = slugify(t);
                            row.find('.html-import-slug').val(s);
                            entry.slug = s;
                        });
                        row.find('.html-import-slug').on('input', function() {
                            var v = $(this).val().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
                            $(this).val(v);
                            entry.slug = v;
                        });
                        row.find('.html-import-remove').on('click', function() {
                            entry.removed = true;
                            row.slideUp(150, function() { row.remove(); });
                            // Hide actions if queue empty
                            var remaining = pendingFiles.filter(function(p) { return !p.removed && !p.published; });
                            if (remaining.length === 0) importActions.hide();
                        });

                        // Read file content
                        var reader = new FileReader();
                        reader.onload = function(e) { entry.html = e.target.result; };
                        reader.readAsText(file);
                    })(files[i]);
                }

                // Hide drop zone, show queue & actions
                importDrop.hide();
                importActions.show();
            }

            // Publish All
            $('#html-import-publish').on('click', function() {
                var btn = $(this);
                var toPublish = pendingFiles.filter(function(p) { return !p.removed && !p.published; });
                if (toPublish.length === 0) return;

                btn.prop('disabled', true).text('Publishing...');
                var done = 0;

                toPublish.forEach(function(entry) {
                    // Read latest values from inputs
                    entry.title = entry.el.find('.html-import-title').val();
                    entry.slug = entry.el.find('.html-import-slug').val();

                    // Disable inputs, show spinner
                    entry.el.find('input').prop('disabled', true);
                    entry.el.find('.html-import-remove').hide();
                    entry.el.find('.html-import-item-status').html('<span class="html-import-spinner"></span> Publishing...');

                    $.post(htmlPageAdmin.ajaxUrl, {
                        action: 'html_page_import',
                        nonce: htmlPageAdmin.nonce,
                        title: entry.title,
                        slug: entry.slug,
                        html: entry.html
                    }, function(res) {
                        if (res.success) {
                            var d = res.data;
                            entry.published = true;
                            entry.el.addClass('html-import-item-done');
                            entry.el.find('.html-import-item-status').html(
                                '<span class="html-import-item-success">Published</span> &mdash; ' +
                                '<a href="' + d.url + '" target="_blank">' + d.url + '</a> ' +
                                '<a href="' + d.edit_url + '">Edit</a>'
                            );
                        } else {
                            entry.el.find('.html-import-item-status').html('<span class="html-import-item-error">Failed</span>');
                            entry.el.find('input').prop('disabled', false);
                        }
                    }).fail(function() {
                        entry.el.find('.html-import-item-status').html('<span class="html-import-item-error">Failed</span>');
                        entry.el.find('input').prop('disabled', false);
                    }).always(function() {
                        done++;
                        if (done === toPublish.length) {
                            btn.text('Done!');
                            setTimeout(function() { location.reload(); }, 1200);
                        }
                    });
                });
            });

            // Cancel
            $('#html-import-cancel').on('click', function() {
                pendingFiles = [];
                importQueue.empty();
                importActions.hide();
                importDrop.show();
                importPanel.slideUp(200);
                listSection.slideDown(200);
            });

            importFile.on('change', function() {
                if (this.files.length) addFilesToQueue(this.files);
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
                if (files.length) addFilesToQueue(files);
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
