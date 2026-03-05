(function($) {
    'use strict';

    $(document).ready(function() {

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
