(function($) {
    'use strict';

    $(document).ready(function() {

        // ── Instant search on list page ──
        var searchInput = $('#html-page-search');
        var table = $('#html-pages-table');
        var tbody = table.find('tbody');

        function applyCurrentSort() {
            var th = table.find('th.html-sortable.sorted-asc, th.html-sortable.sorted-desc').first();
            if (!th.length) return;
            sortTableBy(th.data('sort-key'), th.hasClass('sorted-asc') ? 'asc' : 'desc');
        }

        function sortTableBy(key, dir) {
            var rows = tbody.find('tr').toArray();
            var pinned = [];
            var rest = [];
            rows.forEach(function(tr) {
                if ($(tr).attr('data-pinned') === '1') pinned.push(tr);
                else rest.push(tr);
            });

            var compare = function(a, b) {
                var av, bv;
                if (key === 'title') {
                    av = ($(a).attr('data-title') || '').toString();
                    bv = ($(b).attr('data-title') || '').toString();
                    return av.localeCompare(bv);
                }
                if (key === 'slug') {
                    av = ($(a).attr('data-slug') || '').toString();
                    bv = ($(b).attr('data-slug') || '').toString();
                    return av.localeCompare(bv);
                }
                if (key === 'url') {
                    av = ($(a).attr('data-url') || '').toString();
                    bv = ($(b).attr('data-url') || '').toString();
                    return av.localeCompare(bv);
                }
                if (key === 'folder') {
                    av = ($(a).attr('data-folder-sort') || '').toString();
                    bv = ($(b).attr('data-folder-sort') || '').toString();
                    return av.localeCompare(bv);
                }
                if (key === 'created') {
                    av = parseInt($(a).attr('data-created') || '0', 10);
                    bv = parseInt($(b).attr('data-created') || '0', 10);
                    return av - bv;
                }
                return 0;
            };

            pinned.sort(compare);
            rest.sort(compare);
            if (dir === 'desc') {
                pinned.reverse();
                rest.reverse();
            }

            tbody.empty().append(pinned).append(rest);
        }

        function resetSortIndicators() {
            table.find('th.html-sortable').removeClass('sorted-asc sorted-desc');
        }

        // Click sortable header — cycle: none → asc → desc → none
        table.on('click', 'th.html-sortable', function() {
            var $th = $(this);
            var key = $th.data('sort-key');
            var nextDir;
            if ($th.hasClass('sorted-asc')) {
                nextDir = 'desc';
            } else if ($th.hasClass('sorted-desc')) {
                nextDir = null;
            } else {
                nextDir = 'asc';
            }
            resetSortIndicators();
            if (nextDir === 'asc') {
                $th.addClass('sorted-asc');
                sortTableBy(key, 'asc');
            } else if (nextDir === 'desc') {
                $th.addClass('sorted-desc');
                sortTableBy(key, 'desc');
            } else {
                // Restore default order: re-fetch from server using current search term
                triggerSearch($.trim(searchInput.val() || ''));
            }
        });

        // Pin toggle
        table.on('click', '.html-pin-toggle', function(e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.hasClass('is-loading')) return;
            var id = $btn.data('id');
            var nonce = $btn.data('nonce');

            $btn.addClass('is-loading');

            $.post(htmlPageAdmin.ajaxUrl, {
                action: 'html_page_toggle_pin',
                id: id,
                nonce: nonce
            }, function(res) {
                if (res && res.success) {
                    location.reload();
                } else {
                    $btn.removeClass('is-loading');
                    alert((res && res.data) ? res.data : 'Failed to toggle pin');
                }
            }).fail(function() {
                $btn.removeClass('is-loading');
                alert('Network error. Please try again.');
            });
        });

        function currentFolder() {
            return $('#html-folder-chips').attr('data-active') || '__all';
        }

        // Persistent filter state (applied only when user clicks Apply).
        var activeFilters = { date_from: '', date_to: '', author: 0, pinned: '' };

        function activeFilterCount() {
            var n = 0;
            if (activeFilters.date_from) n++;
            if (activeFilters.date_to) n++;
            if (parseInt(activeFilters.author, 10) > 0) n++;
            if (activeFilters.pinned) n++;
            return n;
        }

        function triggerSearch(term) {
            var $spinner = $('.html-page-search-spinner');
            var $countLabel = $('.html-page-search-count');
            $spinner.addClass('is-active');
            return $.post(htmlPageAdmin.ajaxUrl, {
                action: 'html_page_search',
                nonce: htmlPageAdmin.nonce,
                term: term,
                folder: currentFolder(),
                date_from: activeFilters.date_from,
                date_to: activeFilters.date_to,
                author: activeFilters.author,
                pinned: activeFilters.pinned
            }, function(res) {
                $spinner.removeClass('is-active');
                if (!res.success) return;
                var data = res.data || {};
                var count = data.count || 0;

                if (term === '') {
                    $countLabel.text('');
                } else {
                    $countLabel.text(count + ' result' + (count !== 1 ? 's' : ''));
                }

                if (count === 0) {
                    tbody.html('<tr><td colspan="7">' + (term === '' ? 'No HTML pages in this view.' : 'No pages matching &ldquo;' + $('<span>').text(term).html() + '&rdquo;') + '</td></tr>');
                } else {
                    tbody.html(data.rows_html);
                    applyCurrentSort();
                }

                if (data.stats && data.folders) {
                    refreshFolderChips(data.stats, data.folders);
                }

                restoreCheckedState();
                refreshBulkBar();
            });
        }

        if (searchInput.length) {
            var timer = null;
            var xhr = null;

            searchInput.on('input', function() {
                var term = $.trim($(this).val());
                clearTimeout(timer);
                if (xhr) xhr.abort();

                timer = setTimeout(function() {
                    xhr = triggerSearch(term);
                }, 250);
            });

            // Focus search on page load
            searchInput.focus();
        }

        // ── Folder chips: filter, rename, delete ──
        var chipsWrap = $('#html-folder-chips');

        function refreshFolderChips(stats, folders) {
            var active = currentFolder();
            var html = '';
            html += chipHtml('__all', 'All', stats.__all || 0, null, active);
            html += chipHtml('__none', 'Unfiled', stats.__none || 0, null, active);
            (folders || []).forEach(function(name) {
                var count = (stats.folders && stats.folders[name]) ? stats.folders[name] : 0;
                html += chipHtml(name, name, count, 'folder', active);
            });
            html += '<button type="button" class="html-folder-chip-new" id="html-folder-new" data-tooltip="Create a new folder"><span aria-hidden="true">+</span> New Folder</button>';
            chipsWrap.html(html);
        }

        function esc(s) { return $('<span>').text(String(s == null ? '' : s)).html(); }

        function chipHtml(key, label, count, type, active) {
            var isActive = (String(key) === String(active)) ? ' is-active' : '';
            var tooltip = key === '__all' ? 'Show all pages'
                        : key === '__none' ? 'Pages not in any folder'
                        : 'Show pages in this folder';
            if (type === 'folder') {
                var dragHandle = '<span class="html-folder-chip-drag" aria-hidden="true" data-tooltip="Drag to reorder"><svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2" cy="2" r="1.2"/><circle cx="8" cy="2" r="1.2"/><circle cx="2" cy="7" r="1.2"/><circle cx="8" cy="7" r="1.2"/><circle cx="2" cy="12" r="1.2"/><circle cx="8" cy="12" r="1.2"/></svg></span>';
                var iconSvg = '<span class="html-folder-chip-icon" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></span>';
                var kebab = '<button type="button" class="html-folder-chip-kebab" data-folder="' + esc(key) + '" aria-label="Folder options" tabindex="-1"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>';
                return '<div class="html-folder-chip' + isActive + '" data-folder-key="' + esc(key) + '" data-tooltip="' + esc(tooltip) + '" draggable="true" role="button" tabindex="0">'
                    + dragHandle
                    + iconSvg
                    + '<span class="html-folder-chip-label">' + esc(label) + '</span>'
                    + '<span class="html-folder-chip-count">' + esc(count) + '</span>'
                    + kebab
                    + '</div>';
            }
            // Non-folder chips (All, Unfiled) stay as buttons — no drag, no kebab.
            return '<button type="button" class="html-folder-chip' + isActive + '" data-folder-key="' + esc(key) + '" data-tooltip="' + esc(tooltip) + '">'
                + '<span class="html-folder-chip-label">' + esc(label) + '</span>'
                + '<span class="html-folder-chip-count">' + esc(count) + '</span>'
                + '</button>';
        }

        function setActiveFolder(key) {
            chipsWrap.attr('data-active', key);
            chipsWrap.find('.html-folder-chip').each(function() {
                $(this).toggleClass('is-active', $(this).attr('data-folder-key') === key);
            });
            var term = $.trim(searchInput.val() || '');
            triggerSearch(term);
        }

        chipsWrap.on('click', '.html-folder-chip', function(e) {
            // Ignore clicks on the kebab button.
            if ($(e.target).closest('.html-folder-chip-kebab, .html-folder-chip-menu').length) return;
            var key = $(this).attr('data-folder-key');
            if (key == null) return;
            setActiveFolder(key);
        });

        // Keyboard activation for div-role="button" chips.
        chipsWrap.on('keydown', '.html-folder-chip', function(e) {
            if ($(e.target).closest('.html-folder-chip-kebab').length) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var key = $(this).attr('data-folder-key');
                if (key != null) setActiveFolder(key);
            }
        });

        // ── Kebab menu (Rename / Delete) ──
        function closeKebabMenu() {
            $('.html-folder-chip-menu').remove();
            chipsWrap.find('.html-folder-chip.has-menu-open').removeClass('has-menu-open');
            $(document).off('click.htmlKebab keydown.htmlKebab');
        }

        function openKebabMenu($chip, folderName) {
            closeKebabMenu();
            $chip.addClass('has-menu-open');

            var $menu = $('<div class="html-folder-chip-menu" role="menu"></div>');
            $menu.html(
                '<button type="button" class="html-folder-chip-menu-item" data-action="rename" role="menuitem">' +
                    '<span class="html-folder-chip-menu-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg></span>' +
                    '<span>Rename</span>' +
                '</button>' +
                '<button type="button" class="html-folder-chip-menu-item is-danger" data-action="delete" role="menuitem">' +
                    '<span class="html-folder-chip-menu-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg></span>' +
                    '<span>Delete</span>' +
                '</button>'
            );
            $('body').append($menu);

            var rect = $chip[0].getBoundingClientRect();
            var top = rect.bottom + window.scrollY + 4;
            var left = rect.left + window.scrollX;
            $menu.css({ top: top + 'px', left: left + 'px' });

            $menu.on('click', '.html-folder-chip-menu-item', function() {
                var action = $(this).data('action');
                closeKebabMenu();
                if (action === 'rename') openRenameFolderModal(folderName);
                else if (action === 'delete') openDeleteFolderModal(folderName);
            });

            // Dismiss handlers
            setTimeout(function() {
                $(document).on('click.htmlKebab', function(evt) {
                    if (!$(evt.target).closest('.html-folder-chip-menu, .html-folder-chip.has-menu-open').length) {
                        closeKebabMenu();
                    }
                });
                $(document).on('keydown.htmlKebab', function(evt) {
                    if (evt.key === 'Escape') closeKebabMenu();
                });
            }, 0);
        }

        var kebabOpenTimer = null;
        var kebabCloseTimer = null;

        function cancelKebabTimers() {
            if (kebabOpenTimer) { clearTimeout(kebabOpenTimer); kebabOpenTimer = null; }
            if (kebabCloseTimer) { clearTimeout(kebabCloseTimer); kebabCloseTimer = null; }
        }

        chipsWrap.on('mouseenter', '.html-folder-chip[draggable="true"]', function() {
            cancelKebabTimers();
            var $chip = $(this);
            var name = $chip.find('.html-folder-chip-kebab').data('folder');
            if (!name) return;
            if ($chip.hasClass('has-menu-open')) return;
            kebabOpenTimer = setTimeout(function() {
                if ($chip.hasClass('is-dragging')) return;
                // Close any other open menu first.
                closeKebabMenu();
                openKebabMenu($chip, name);
            }, 180);
        });

        chipsWrap.on('mouseleave', '.html-folder-chip[draggable="true"]', function() {
            if (kebabOpenTimer) { clearTimeout(kebabOpenTimer); kebabOpenTimer = null; }
            kebabCloseTimer = setTimeout(closeKebabMenu, 220);
        });

        // Keep menu open while the user is over it.
        $(document).on('mouseenter', '.html-folder-chip-menu', function() {
            cancelKebabTimers();
        });
        $(document).on('mouseleave', '.html-folder-chip-menu', function() {
            kebabCloseTimer = setTimeout(closeKebabMenu, 180);
        });

        // Also allow keyboard-triggered open by clicking the kebab (accessibility fallback).
        chipsWrap.on('click', '.html-folder-chip-kebab', function(e) {
            e.stopPropagation();
            var $chip = $(this).closest('.html-folder-chip');
            var name = $(this).data('folder');
            if ($chip.hasClass('has-menu-open')) {
                closeKebabMenu();
            } else {
                cancelKebabTimers();
                openKebabMenu($chip, name);
            }
        });

        function openRenameFolderModal(oldName) {
            openModal({
                title: 'Rename folder',
                bodyHtml: '<label class="html-modal-label">Folder name</label><input type="text" class="html-modal-input" id="html-modal-rename-input" value="' + esc(oldName) + '" maxlength="50">',
                confirmLabel: 'Rename',
                onConfirm: function(done) {
                    var newName = $.trim($('#html-modal-rename-input').val() || '');
                    if (!newName) { done(false, 'Name is required'); return; }
                    $.post(htmlPageAdmin.ajaxUrl, {
                        action: 'html_page_rename_folder',
                        nonce: htmlPageAdmin.nonce,
                        old: oldName,
                        new: newName
                    }, function(res) {
                        if (!res || !res.success) {
                            done(false, (res && res.data) ? res.data : 'Rename failed');
                            return;
                        }
                        var active = currentFolder();
                        if (active === oldName) chipsWrap.attr('data-active', newName);
                        refreshFolderChips(res.data.stats, res.data.folders);
                        triggerSearch($.trim(searchInput.val() || ''));
                        done(true);
                    }).fail(function() { done(false, 'Network error'); });
                }
            });
            setTimeout(function() { $('#html-modal-rename-input').trigger('focus').trigger('select'); }, 50);
        }

        function openDeleteFolderModal(name) {
            openModal({
                title: 'Delete folder',
                bodyHtml: '<p class="html-modal-message">Delete folder <strong>' + esc(name) + '</strong>? Pages in this folder will move to <em>Unfiled</em>. Pages themselves are not deleted.</p>',
                confirmLabel: 'Delete',
                confirmDanger: true,
                onConfirm: function(done) {
                    $.post(htmlPageAdmin.ajaxUrl, {
                        action: 'html_page_delete_folder',
                        nonce: htmlPageAdmin.nonce,
                        name: name
                    }, function(res) {
                        if (!res || !res.success) {
                            done(false, (res && res.data) ? res.data : 'Delete failed');
                            return;
                        }
                        var active = currentFolder();
                        if (active === name) chipsWrap.attr('data-active', '__all');
                        refreshFolderChips(res.data.stats, res.data.folders);
                        triggerSearch($.trim(searchInput.val() || ''));
                        done(true);
                    }).fail(function() { done(false, 'Network error'); });
                }
            });
        }

        // ── Drag-to-reorder folders ──
        var dragSrcKey = null;

        chipsWrap.on('dragstart', '.html-folder-chip[draggable="true"]', function(e) {
            dragSrcKey = $(this).attr('data-folder-key');
            $(this).addClass('is-dragging');
            try {
                e.originalEvent.dataTransfer.effectAllowed = 'move';
                e.originalEvent.dataTransfer.setData('text/plain', dragSrcKey);
            } catch (err) {}
            closeKebabMenu();
        });

        chipsWrap.on('dragend', '.html-folder-chip[draggable="true"]', function() {
            $(this).removeClass('is-dragging');
            chipsWrap.find('.html-folder-chip.drop-before, .html-folder-chip.drop-after').removeClass('drop-before drop-after');
        });

        chipsWrap.on('dragover', '.html-folder-chip[draggable="true"]', function(e) {
            if (dragSrcKey === null) return;
            var overKey = $(this).attr('data-folder-key');
            if (overKey === dragSrcKey) return;
            e.preventDefault();
            try { e.originalEvent.dataTransfer.dropEffect = 'move'; } catch (err) {}
            var rect = this.getBoundingClientRect();
            var midX = rect.left + rect.width / 2;
            var isBefore = e.originalEvent.clientX < midX;
            chipsWrap.find('.html-folder-chip.drop-before, .html-folder-chip.drop-after').removeClass('drop-before drop-after');
            $(this).addClass(isBefore ? 'drop-before' : 'drop-after');
        });

        chipsWrap.on('dragleave', '.html-folder-chip[draggable="true"]', function() {
            $(this).removeClass('drop-before drop-after');
        });

        chipsWrap.on('drop', '.html-folder-chip[draggable="true"]', function(e) {
            e.preventDefault();
            if (!dragSrcKey) return;
            var $target = $(this);
            var targetKey = $target.attr('data-folder-key');
            var isBefore = $target.hasClass('drop-before');
            chipsWrap.find('.html-folder-chip.drop-before, .html-folder-chip.drop-after').removeClass('drop-before drop-after');
            if (targetKey === dragSrcKey) return;

            // Compute new order from DOM, moving dragSrcKey next to target.
            var order = [];
            chipsWrap.find('.html-folder-chip[draggable="true"]').each(function() {
                var k = $(this).attr('data-folder-key');
                if (k !== dragSrcKey) order.push(k);
            });
            var idx = order.indexOf(targetKey);
            if (idx < 0) return;
            if (!isBefore) idx += 1;
            order.splice(idx, 0, dragSrcKey);

            // Optimistic reorder in DOM.
            var byKey = {};
            chipsWrap.find('.html-folder-chip[draggable="true"]').each(function() {
                byKey[$(this).attr('data-folder-key')] = this;
            });
            var $newFolderBtn = $('#html-folder-new');
            order.forEach(function(k) {
                var el = byKey[k];
                if (el) chipsWrap[0].insertBefore(el, $newFolderBtn[0]);
            });

            // Persist.
            $.post(htmlPageAdmin.ajaxUrl, {
                action: 'html_page_reorder_folders',
                nonce: htmlPageAdmin.nonce,
                order: order
            }, function(res) {
                if (!res || !res.success) {
                    // On failure, refresh to true server order.
                    triggerSearch($.trim(searchInput.val() || ''));
                }
            }).fail(function() {
                triggerSearch($.trim(searchInput.val() || ''));
            });
            dragSrcKey = null;
        });

        chipsWrap.on('click', '#html-folder-new', function() {
            openModal({
                title: 'New folder',
                bodyHtml: '<label class="html-modal-label">Folder name</label><input type="text" class="html-modal-input" id="html-modal-new-input" placeholder="e.g. Landing Pages" maxlength="50">',
                confirmLabel: 'Create',
                onConfirm: function(done) {
                    var name = $.trim($('#html-modal-new-input').val() || '');
                    if (!name) { done(false, 'Name is required'); return; }
                    $.post(htmlPageAdmin.ajaxUrl, {
                        action: 'html_page_create_folder',
                        nonce: htmlPageAdmin.nonce,
                        name: name
                    }, function(res) {
                        if (!res || !res.success) {
                            done(false, (res && res.data) ? res.data : 'Create failed');
                            return;
                        }
                        refreshFolderChips(res.data.stats, res.data.folders);
                        done(true);
                    }).fail(function() { done(false, 'Network error'); });
                }
            });
            setTimeout(function() { $('#html-modal-new-input').trigger('focus'); }, 50);
        });

        // ── Folder pill picker (per-row) ──
        function closeFolderPicker() {
            $('.html-folder-picker').remove();
            $(document).off('click.htmlFolderPicker keydown.htmlFolderPicker');
        }

        table.on('click', '.html-folder-pill', function(e) {
            e.stopPropagation();
            var $btn = $(this);
            if ($btn.hasClass('is-loading')) return;
            var already = $btn.data('picker-open');
            closeFolderPicker();
            if (already) { $btn.data('picker-open', false); return; }

            var id = $btn.data('id');
            var nonce = $btn.data('nonce');
            var current = ($btn.data('current') || '').toString();

            // Build picker
            var folders = [];
            $('#html-folder-chips .html-folder-chip[data-folder-key]').each(function() {
                var k = $(this).attr('data-folder-key');
                if (k === '__all' || k === '__none') return;
                folders.push(k);
            });

            var itemsHtml = '';
            itemsHtml += '<button type="button" class="html-folder-picker-item' + (current === '' ? ' is-selected' : '') + '" data-folder-value=""><span class="html-folder-picker-item-label"><em>Unfiled</em></span>' + (current === '' ? '<span class="html-folder-picker-check">&#10003;</span>' : '') + '</button>';
            folders.forEach(function(name) {
                var isSel = (name === current);
                itemsHtml += '<button type="button" class="html-folder-picker-item' + (isSel ? ' is-selected' : '') + '" data-folder-value="' + esc(name) + '"><span class="html-folder-picker-item-label">' + esc(name) + '</span>' + (isSel ? '<span class="html-folder-picker-check">&#10003;</span>' : '') + '</button>';
            });
            itemsHtml += '<div class="html-folder-picker-divider"></div>';
            itemsHtml += '<button type="button" class="html-folder-picker-item html-folder-picker-new"><span class="html-folder-picker-item-label">+ New folder&hellip;</span></button>';

            var $picker = $('<div class="html-folder-picker"></div>').html(itemsHtml);
            $('body').append($picker);

            // Position under button
            var rect = $btn[0].getBoundingClientRect();
            var top = rect.bottom + window.scrollY + 4;
            var left = rect.left + window.scrollX;
            $picker.css({ top: top + 'px', left: left + 'px' });

            $btn.data('picker-open', true);

            $picker.on('click', '.html-folder-picker-item', function(e) {
                e.stopPropagation();
                if ($(this).hasClass('html-folder-picker-new')) {
                    closeFolderPicker();
                    openModal({
                        title: 'New folder',
                        bodyHtml: '<label class="html-modal-label">Folder name</label><input type="text" class="html-modal-input" id="html-modal-newfolder-input" placeholder="e.g. Landing Pages" maxlength="50">',
                        confirmLabel: 'Create & move',
                        onConfirm: function(done) {
                            var name = $.trim($('#html-modal-newfolder-input').val() || '');
                            if (!name) { done(false, 'Name is required'); return; }
                            // Create then set
                            $.post(htmlPageAdmin.ajaxUrl, {
                                action: 'html_page_create_folder',
                                nonce: htmlPageAdmin.nonce,
                                name: name
                            }, function(res) {
                                if (!res || !res.success) {
                                    // If it already exists, still try to set
                                    if (!res || (res.data && String(res.data).indexOf('already') === -1)) {
                                        done(false, (res && res.data) ? res.data : 'Create failed');
                                        return;
                                    }
                                }
                                setPageFolder(id, nonce, res && res.data ? res.data.name : name, function(ok, err) {
                                    done(ok, err);
                                });
                            }).fail(function() { done(false, 'Network error'); });
                        }
                    });
                    setTimeout(function() { $('#html-modal-newfolder-input').trigger('focus'); }, 50);
                    return;
                }
                var value = $(this).attr('data-folder-value');
                closeFolderPicker();
                setPageFolder(id, nonce, value);
            });

            // Dismiss handlers
            setTimeout(function() {
                $(document).on('click.htmlFolderPicker', function(evt) {
                    if (!$(evt.target).closest('.html-folder-picker, .html-folder-pill').length) {
                        closeFolderPicker();
                    }
                });
                $(document).on('keydown.htmlFolderPicker', function(evt) {
                    if (evt.key === 'Escape') closeFolderPicker();
                });
            }, 0);
        });

        function setPageFolder(id, nonce, folderValue, cb) {
            var $btn = table.find('.html-folder-pill[data-id="' + id + '"]');
            $btn.addClass('is-loading');
            $.post(htmlPageAdmin.ajaxUrl, {
                action: 'html_page_set_folder',
                id: id,
                nonce: nonce,
                folder: folderValue
            }, function(res) {
                $btn.removeClass('is-loading');
                if (!res || !res.success) {
                    if (cb) cb(false, (res && res.data) ? res.data : 'Failed');
                    else alert((res && res.data) ? res.data : 'Failed to change folder');
                    return;
                }
                // Update pill in-place
                var f = res.data.folder;
                $btn.attr('data-current', f);
                $btn.toggleClass('is-unfiled', f === '');
                var iconHtml = f === ''
                    ? ''
                    : '<span class="html-folder-pill-icon" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></span>';
                $btn.html(iconHtml
                    + '<span class="html-folder-pill-label">' + esc(f === '' ? 'Unfiled' : f) + '</span>'
                    + '<span class="html-folder-pill-caret" aria-hidden="true">&#9662;</span>'
                    + '<span class="html-folder-pill-spinner" aria-hidden="true"></span>'
                );
                // Update row data attrs for sorting/filtering
                var $tr = $btn.closest('tr');
                $tr.attr('data-folder', f);
                $tr.attr('data-folder-sort', f === '' ? '~~~unfiled' : f.toLowerCase());
                // If viewing a specific folder and this page no longer belongs, remove row
                var active = currentFolder();
                if (active !== '__all' && active !== f && !(active === '__none' && f === '')) {
                    $tr.fadeOut(150, function() { $tr.remove(); });
                }
                refreshFolderChips(res.data.stats, res.data.folders);
                if (cb) cb(true);
            }).fail(function() {
                $btn.removeClass('is-loading');
                if (cb) cb(false, 'Network error');
                else alert('Network error.');
            });
        }

        // ── Custom modal ──
        var $modalBackdrop = $('#html-modal-backdrop');
        var $modal = $modalBackdrop.find('.html-modal');
        var $modalTitle = $('#html-modal-title');
        var $modalBody = $('#html-modal-body');
        var $modalConfirm = $modal.find('.html-modal-confirm');
        var $modalConfirmLabel = $modal.find('.html-modal-confirm-label');
        var $modalCancel = $modal.find('.html-modal-cancel');
        var $modalClose = $modal.find('.html-modal-close');
        var modalActiveConfig = null;

        function openModal(config) {
            modalActiveConfig = config || {};
            $modalTitle.text(config.title || '');
            $modalBody.off(); // clear any per-open delegated handlers
            $modalBody.html(config.bodyHtml || '');
            $modalConfirmLabel.text(config.confirmLabel || 'Confirm');
            $modalConfirm.toggleClass('button-danger', !!config.confirmDanger);
            $modalConfirm.prop('disabled', false).removeClass('is-loading');
            $modalConfirm.toggle(config.showConfirm !== false);
            $modalBackdrop.css('display', 'flex');
        }

        function closeModal() {
            $modalBackdrop.hide();
            $modalBody.off();
            $modalBody.empty();
            $modal.find('.html-modal-error').remove();
            $modalConfirm.prop('disabled', false).removeClass('is-loading button-danger').show();
            modalActiveConfig = null;
        }

        $modalCancel.add($modalClose).on('click', function() { closeModal(); });
        $modalBackdrop.on('click', function(e) { if (e.target === this) closeModal(); });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $modalBackdrop.is(':visible')) closeModal();
            if (e.key === 'Enter' && $modalBackdrop.is(':visible') && !$(e.target).is('textarea')) {
                if ($modalConfirm.is(':visible') && !$modalConfirm.prop('disabled')) $modalConfirm.trigger('click');
            }
        });

        $modalConfirm.on('click', function() {
            if (!modalActiveConfig || typeof modalActiveConfig.onConfirm !== 'function') { closeModal(); return; }
            $modal.find('.html-modal-error').remove();
            $modalConfirm.prop('disabled', true).addClass('is-loading');
            modalActiveConfig.onConfirm(function(ok, errMsg) {
                if (ok) {
                    closeModal();
                } else {
                    $modalConfirm.prop('disabled', false).removeClass('is-loading');
                    if (errMsg) {
                        $modalBody.append('<p class="html-modal-error">' + esc(errMsg) + '</p>');
                    }
                }
            });
        });

        // ── Bulk selection & actions ──
        var selectedIds = new Set();
        var $bulkBar = $('#html-bulk-bar');
        var $checkAll = $('#html-check-all');

        function restoreCheckedState() {
            table.find('tbody .html-row-check').each(function() {
                var id = parseInt($(this).val(), 10);
                var checked = selectedIds.has(id);
                this.checked = checked;
                $(this).closest('tr').toggleClass('html-row-selected', checked);
            });
            syncCheckAllState();
        }

        function syncCheckAllState() {
            var $rowChecks = table.find('tbody .html-row-check');
            if ($rowChecks.length === 0) {
                $checkAll.prop('checked', false).prop('indeterminate', false);
                return;
            }
            var checked = $rowChecks.filter(':checked').length;
            if (checked === 0) {
                $checkAll.prop('checked', false).prop('indeterminate', false);
            } else if (checked === $rowChecks.length) {
                $checkAll.prop('checked', true).prop('indeterminate', false);
            } else {
                $checkAll.prop('checked', false).prop('indeterminate', true);
            }
        }

        function refreshBulkBar() {
            var n = selectedIds.size;
            if (n > 0) {
                $bulkBar.find('.html-bulk-count-n').text(n);
                if (!$bulkBar.is(':visible')) $bulkBar.slideDown(120);
            } else {
                if ($bulkBar.is(':visible')) $bulkBar.slideUp(120);
            }
        }

        table.on('change', 'tbody .html-row-check', function() {
            var id = parseInt($(this).val(), 10);
            if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
            $(this).closest('tr').toggleClass('html-row-selected', this.checked);
            syncCheckAllState();
            refreshBulkBar();
        });

        $checkAll.on('change', function() {
            var check = this.checked;
            table.find('tbody .html-row-check').each(function() {
                var id = parseInt($(this).val(), 10);
                this.checked = check;
                $(this).closest('tr').toggleClass('html-row-selected', check);
                if (check) selectedIds.add(id); else selectedIds.delete(id);
            });
            refreshBulkBar();
        });

        // Shift-click range select
        var lastCheckIdx = null;
        table.on('click', 'tbody .html-row-check', function(e) {
            var $rowChecks = table.find('tbody .html-row-check');
            var idx = $rowChecks.index(this);
            if (e.shiftKey && lastCheckIdx !== null && idx !== lastCheckIdx) {
                var start = Math.min(lastCheckIdx, idx);
                var end = Math.max(lastCheckIdx, idx);
                var target = this.checked;
                $rowChecks.slice(start, end + 1).each(function() {
                    if (this.checked !== target) {
                        this.checked = target;
                        var id = parseInt($(this).val(), 10);
                        if (target) selectedIds.add(id); else selectedIds.delete(id);
                        $(this).closest('tr').toggleClass('html-row-selected', target);
                    }
                });
                syncCheckAllState();
                refreshBulkBar();
            }
            lastCheckIdx = idx;
        });

        $('#html-bulk-clear').on('click', function() {
            selectedIds.clear();
            table.find('tbody .html-row-check').prop('checked', false);
            table.find('tbody tr.html-row-selected').removeClass('html-row-selected');
            syncCheckAllState();
            refreshBulkBar();
        });

        function selectedIdArray() {
            return Array.from(selectedIds);
        }

        function setBulkBtnLoading($btn, on) {
            $btn.toggleClass('is-loading', on).prop('disabled', on);
            // Disable siblings too so one action doesn't collide with another mid-flight.
            $bulkBar.find('.html-bulk-btn').not($btn).prop('disabled', on);
        }

        // Move to folder (bulk)
        $('#html-bulk-move').on('click', function() {
            var $btn = $(this);
            if (selectedIds.size === 0) return;

            var folders = [];
            $('#html-folder-chips .html-folder-chip[data-folder-key]').each(function() {
                var k = $(this).attr('data-folder-key');
                if (k === '__all' || k === '__none') return;
                folders.push(k);
            });

            var itemsHtml = '';
            itemsHtml += '<div class="html-folder-picker html-folder-picker-inline">';
            itemsHtml += '<button type="button" class="html-folder-picker-item" data-folder-value=""><span class="html-folder-picker-item-label"><em>Unfiled</em></span></button>';
            folders.forEach(function(name) {
                itemsHtml += '<button type="button" class="html-folder-picker-item" data-folder-value="' + esc(name) + '"><span class="html-folder-picker-item-label">' + esc(name) + '</span></button>';
            });
            itemsHtml += '<div class="html-folder-picker-divider"></div>';
            itemsHtml += '<button type="button" class="html-folder-picker-item html-folder-picker-new"><span class="html-folder-picker-item-label">+ New folder&hellip;</span></button>';
            itemsHtml += '</div>';

            openModal({
                title: 'Move ' + selectedIds.size + ' page' + (selectedIds.size === 1 ? '' : 's') + ' to folder',
                bodyHtml: itemsHtml,
                confirmLabel: 'Move',
                showConfirm: false
            });

            $modalBody.on('click', '.html-folder-picker-item', function() {
                var $it = $(this);
                if ($it.hasClass('html-folder-picker-new')) {
                    $modalBody.off();
                    $modalTitle.text('Create folder & move');
                    $modalBody.html(
                        '<label class="html-modal-label">New folder name</label>' +
                        '<input type="text" class="html-modal-input" id="html-modal-bulkfolder-input" placeholder="e.g. Landing Pages" maxlength="50">'
                    );
                    $modalConfirmLabel.text('Create & move');
                    $modalConfirm.show();
                    modalActiveConfig = {
                        onConfirm: function(done) {
                            var name = $.trim($('#html-modal-bulkfolder-input').val() || '');
                            if (!name) { done(false, 'Name is required'); return; }
                            $.post(htmlPageAdmin.ajaxUrl, {
                                action: 'html_page_create_folder',
                                nonce: htmlPageAdmin.nonce,
                                name: name
                            }, function(res) {
                                if (!res || !res.success) {
                                    if (!res || (res.data && String(res.data).indexOf('already') === -1)) {
                                        done(false, (res && res.data) ? res.data : 'Create failed');
                                        return;
                                    }
                                }
                                var finalName = (res && res.data && res.data.name) ? res.data.name : name;
                                bulkSetFolder(finalName, function(ok, err) { done(ok, err); });
                            }).fail(function() { done(false, 'Network error'); });
                        }
                    };
                    setTimeout(function() { $('#html-modal-bulkfolder-input').trigger('focus'); }, 50);
                    return;
                }
                var value = $it.attr('data-folder-value');
                $modalBody.off();
                $modalBody.html('<p class="html-modal-message"><span class="html-import-spinner"></span> Moving ' + selectedIds.size + ' page' + (selectedIds.size === 1 ? '' : 's') + '&hellip;</p>');
                bulkSetFolder(value, function(ok, err) {
                    if (ok) {
                        closeModal();
                    } else {
                        $modalBody.append('<p class="html-modal-error">' + esc(err || 'Failed') + '</p>');
                    }
                });
            });
        });

        function bulkSetFolder(folderValue, cb) {
            setBulkBtnLoading($('#html-bulk-move'), true);
            $.post(htmlPageAdmin.ajaxUrl, {
                action: 'html_page_bulk_set_folder',
                nonce: htmlPageAdmin.nonce,
                ids: selectedIdArray(),
                folder: folderValue
            }, function(res) {
                setBulkBtnLoading($('#html-bulk-move'), false);
                if (!res || !res.success) {
                    if (cb) cb(false, (res && res.data) ? res.data : 'Failed');
                    return;
                }
                if (res.data.stats && res.data.folders) refreshFolderChips(res.data.stats, res.data.folders);
                selectedIds.clear();
                refreshBulkBar();
                triggerSearch($.trim(searchInput.val() || ''));
                if (cb) cb(true);
            }).fail(function() {
                setBulkBtnLoading($('#html-bulk-move'), false);
                if (cb) cb(false, 'Network error');
            });
        }

        // Pin / Unpin (bulk)
        $('#html-bulk-pin').on('click', function() { bulkPin(true); });
        $('#html-bulk-unpin').on('click', function() { bulkPin(false); });

        function bulkPin(pin) {
            if (selectedIds.size === 0) return;
            var $btn = $(pin ? '#html-bulk-pin' : '#html-bulk-unpin');
            setBulkBtnLoading($btn, true);
            $.post(htmlPageAdmin.ajaxUrl, {
                action: 'html_page_bulk_pin',
                nonce: htmlPageAdmin.nonce,
                ids: selectedIdArray(),
                pin: pin ? '1' : '0'
            }, function(res) {
                setBulkBtnLoading($btn, false);
                if (!res || !res.success) {
                    alert((res && res.data) ? res.data : 'Failed');
                    return;
                }
                triggerSearch($.trim(searchInput.val() || ''));
            }).fail(function() {
                setBulkBtnLoading($btn, false);
                alert('Network error.');
            });
        }

        // Delete (bulk)
        $('#html-bulk-delete').on('click', function() {
            if (selectedIds.size === 0) return;
            var n = selectedIds.size;
            openModal({
                title: 'Delete ' + n + ' page' + (n === 1 ? '' : 's'),
                bodyHtml: '<p class="html-modal-message">Move <strong>' + n + '</strong> selected page' + (n === 1 ? '' : 's') + ' to the trash? This is reversible from the WordPress trash.</p>',
                confirmLabel: 'Delete',
                confirmDanger: true,
                onConfirm: function(done) {
                    $.post(htmlPageAdmin.ajaxUrl, {
                        action: 'html_page_bulk_delete',
                        nonce: htmlPageAdmin.nonce,
                        ids: selectedIdArray()
                    }, function(res) {
                        if (!res || !res.success) {
                            done(false, (res && res.data) ? res.data : 'Delete failed');
                            return;
                        }
                        selectedIds.clear();
                        refreshBulkBar();
                        if (res.data.stats && res.data.folders) refreshFolderChips(res.data.stats, res.data.folders);
                        triggerSearch($.trim(searchInput.val() || ''));
                        done(true);
                    }).fail(function() { done(false, 'Network error'); });
                }
            });
        });

        // Download (bulk) — POST for ID list, get zip blob, trigger download.
        $('#html-bulk-download').on('click', function() {
            if (selectedIds.size === 0) return;
            var $btn = $(this);
            setBulkBtnLoading($btn, true);

            var form = new FormData();
            form.append('action', 'html_page_bulk_download');
            form.append('nonce', htmlPageAdmin.nonce);
            selectedIdArray().forEach(function(id) { form.append('ids[]', id); });

            fetch(htmlPageAdmin.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: form
            }).then(function(resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                var ct = resp.headers.get('Content-Type') || '';
                if (ct.indexOf('application/zip') === -1) {
                    return resp.text().then(function(txt) {
                        var msg = 'Download failed';
                        try {
                            var j = JSON.parse(txt);
                            if (j && j.data) msg = j.data;
                        } catch (e) {}
                        throw new Error(msg);
                    });
                }
                var dispo = resp.headers.get('Content-Disposition') || '';
                var m = dispo.match(/filename="?([^"]+)"?/i);
                var name = m ? m[1] : ('html-pages-' + Date.now() + '.zip');
                return resp.blob().then(function(blob) {
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = name;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 500);
                });
            }).catch(function(err) {
                alert(err && err.message ? err.message : 'Download failed');
            }).finally(function() {
                setBulkBtnLoading($btn, false);
            });
        });

        // ── Filter panel ──
        var $filterToggle = $('#html-filter-toggle');
        var $filterPanel = $('#html-filter-panel');
        var $filterBadge = $('#html-filter-badge');
        var $fDateFrom = $('#html-filter-date-from');
        var $fDateTo = $('#html-filter-date-to');
        var $fAuthor = $('#html-filter-author');
        var $fPinned = $('#html-filter-pinned');
        var $fApply = $('#html-filter-apply');
        var $fClear = $('#html-filter-clear');

        function refreshFilterBadge() {
            var n = activeFilterCount();
            if (n > 0) {
                $filterBadge.text(n).show();
                $filterToggle.addClass('has-active-filters');
            } else {
                $filterBadge.hide();
                $filterToggle.removeClass('has-active-filters');
            }
        }

        function setPanelInputsFromActive() {
            $fDateFrom.val(activeFilters.date_from || '');
            $fDateTo.val(activeFilters.date_to || '');
            $fAuthor.val(String(activeFilters.author || 0));
            $fPinned.val(activeFilters.pinned || '');
        }

        $filterToggle.on('click', function(e) {
            e.preventDefault();
            var open = $filterPanel.is(':visible');
            if (open) {
                $filterPanel.slideUp(140);
                $filterToggle.attr('aria-expanded', 'false');
            } else {
                setPanelInputsFromActive();
                $filterPanel.slideDown(140);
                $filterToggle.attr('aria-expanded', 'true');
                setTimeout(function() { $fDateFrom.trigger('focus'); }, 160);
            }
        });

        $fApply.on('click', function() {
            var df = $.trim($fDateFrom.val() || '');
            var dt = $.trim($fDateTo.val() || '');
            // Basic validation: if both set, from must be <= to.
            if (df && dt && df > dt) {
                alert('“Created after” must be on or before “Created before”.');
                return;
            }
            activeFilters.date_from = df;
            activeFilters.date_to = dt;
            activeFilters.author = parseInt($fAuthor.val() || 0, 10);
            activeFilters.pinned = $fPinned.val() || '';
            refreshFilterBadge();
            $fApply.addClass('is-loading').prop('disabled', true);
            var xhr = triggerSearch($.trim(searchInput.val() || ''));
            if (xhr && xhr.always) {
                xhr.always(function() {
                    $fApply.removeClass('is-loading').prop('disabled', false);
                });
            } else {
                $fApply.removeClass('is-loading').prop('disabled', false);
            }
            $filterPanel.slideUp(140);
            $filterToggle.attr('aria-expanded', 'false');
        });

        $fClear.on('click', function() {
            activeFilters = { date_from: '', date_to: '', author: 0, pinned: '' };
            setPanelInputsFromActive();
            refreshFilterBadge();
            triggerSearch($.trim(searchInput.val() || ''));
        });

        // Initial state
        refreshBulkBar();
        refreshFilterBadge();

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
                    entry.el.addClass('html-import-item-publishing');
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
