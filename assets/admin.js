/* global jQuery, AICR */
(function ($) {
    'use strict';

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setStatus($el, msg, cls) {
        $el.removeClass('is-error is-success').text(msg || '');
        if (cls) { $el.addClass(cls); }
    }

    function bindMetabox() {
        var $box = $('.aicr-metabox');
        if (!$box.length) { return; }
        var postId = parseInt($box.data('post-id'), 10);
        var $status = $box.find('.aicr-status');
        var $spinner = $box.find('.aicr-spinner');
        var $picker = $box.find('.aicr-field-picker');
        var $list = $box.find('.aicr-field-list');
        var $preview = $box.find('.aicr-preview');
        var $items = $box.find('.aicr-preview-items');

        var fieldsCache = null;

        function loadFields(cb) {
            if (fieldsCache) { cb(fieldsCache); return; }
            $.post(AICR.ajax_url, {
                action: 'aicr_list_fields',
                nonce: AICR.nonce,
                post_id: postId
            }).done(function (r) {
                if (r && r.success) {
                    fieldsCache = r.data.items || [];
                    cb(fieldsCache);
                } else {
                    setStatus($status, AICR.i18n.error + (r && r.data && r.data.message ? r.data.message : ''), 'is-error');
                }
            }).fail(function () {
                setStatus($status, AICR.i18n.error + 'AJAX failure', 'is-error');
            });
        }

        $box.on('click', '#aicr-select-fields', function () {
            loadFields(function (items) {
                if (!items.length) {
                    $list.html('<em>' + escapeHtml('No eligible fields found. Check settings.') + '</em>');
                } else {
                    var html = items.map(function (it) {
                        return '<label>'
                            + '<input type="checkbox" class="aicr-field-cb" value="' + escapeHtml(it.id) + '" checked /> '
                            + '<strong>' + escapeHtml(it.label) + '</strong>'
                            + '<span class="aicr-field-meta">[' + escapeHtml(it.type) + ' / ' + escapeHtml(it.format) + ']</span>'
                            + '<span class="aicr-field-preview">' + escapeHtml(it.preview) + '</span>'
                            + '</label>';
                    }).join('');
                    $list.html(html);
                }
                $picker.prop('hidden', !$picker.prop('hidden'));
            });
        });

        $box.on('click', '#aicr-generate', function () {
            setStatus($status, AICR.i18n.generating, '');
            $spinner.addClass('is-active');
            $preview.prop('hidden', true);
            $items.empty();

            var selected = $list.find('.aicr-field-cb:checked').map(function () { return this.value; }).get();
            var data = {
                action: 'aicr_preview',
                nonce: AICR.nonce,
                post_id: postId,
                extra: $('#aicr_extra_prompt').val() || ''
            };
            if (selected.length) {
                data.fields = selected;
            }

            $.post(AICR.ajax_url, data).done(function (r) {
                $spinner.removeClass('is-active');
                if (!r || !r.success) {
                    setStatus($status, AICR.i18n.error + (r && r.data && r.data.message ? r.data.message : ''), 'is-error');
                    return;
                }
                renderPreview(r.data.items || []);
                setStatus($status, '', '');
            }).fail(function () {
                $spinner.removeClass('is-active');
                setStatus($status, AICR.i18n.error + 'AJAX failure', 'is-error');
            });
        });

        function renderPreview(items) {
            if (!items.length) {
                $items.html('<em>' + escapeHtml(AICR.i18n.no_preview) + '</em>');
                $preview.prop('hidden', false);
                return;
            }
            var html = items.map(function (it) {
                return '<div class="aicr-preview-item" data-id="' + escapeHtml(it.id) + '">'
                    + '<header>'
                    + '<h4>' + escapeHtml(it.label) + ' <small style="color:#888">[' + escapeHtml(it.format) + ']</small></h4>'
                    + '<label class="aicr-approve"><input type="checkbox" class="aicr-approve-cb" checked /> Approve</label>'
                    + '</header>'
                    + '<div class="aicr-preview-cols">'
                    + '<div class="aicr-preview-col"><h5>Original</h5><pre>' + escapeHtml(it.original) + '</pre></div>'
                    + '<div class="aicr-preview-col"><h5>Rewritten</h5><textarea class="aicr-rewritten">' + escapeHtml(it.rewritten) + '</textarea></div>'
                    + '</div>'
                    + '</div>';
            }).join('');
            $items.html(html);
            $preview.prop('hidden', false);
        }

        $box.on('click', '#aicr-discard', function () {
            $items.empty();
            $preview.prop('hidden', true);
            setStatus($status, '', '');
        });

        $box.on('click', '#aicr-apply', function () {
            if (!confirm(AICR.i18n.apply_confirm)) { return; }
            var approved = [];
            var values = {};
            $items.find('.aicr-preview-item').each(function () {
                var $it = $(this);
                var id = $it.data('id');
                if ($it.find('.aicr-approve-cb').is(':checked')) {
                    approved.push(id);
                    values[id] = $it.find('.aicr-rewritten').val();
                }
            });
            if (!approved.length) {
                setStatus($status, 'Nothing approved.', 'is-error');
                return;
            }
            $spinner.addClass('is-active');
            $.post(AICR.ajax_url, {
                action: 'aicr_apply',
                nonce: AICR.nonce,
                post_id: postId,
                approved: approved,
                values: values
            }).done(function (r) {
                $spinner.removeClass('is-active');
                if (!r || !r.success) {
                    setStatus($status, AICR.i18n.error + (r && r.data && r.data.message ? r.data.message : ''), 'is-error');
                    return;
                }
                setStatus($status, AICR.i18n.applied + ' (' + (r.data.applied || 0) + ')', 'is-success');
                // Optional: reload the editor to show new content. We let user save manually for safety.
            }).fail(function () {
                $spinner.removeClass('is-active');
                setStatus($status, AICR.i18n.error + 'AJAX failure', 'is-error');
            });
        });
    }

    function bindSettings() {
        var $testBtn = $('#aicr-test-key');
        if ($testBtn.length) {
            $testBtn.on('click', function () {
                var $res = $('#aicr-test-result');
                setStatus($res, 'Testing...', '');
                $.post(AICR.ajax_url, { action: 'aicr_test_key', nonce: AICR.nonce })
                    .done(function (r) {
                        if (r && r.success) {
                            setStatus($res, r.data.message, 'is-success');
                        } else {
                            setStatus($res, (r && r.data && r.data.message) ? r.data.message : 'Error', 'is-error');
                        }
                    }).fail(function () { setStatus($res, 'AJAX failure', 'is-error'); });
            });
        }
        var $upBtn = $('#aicr-check-updates');
        if ($upBtn.length) {
            $upBtn.on('click', function () {
                var $res = $('#aicr-update-result');
                setStatus($res, 'Checking...', '');
                $.post(AICR.ajax_url, { action: 'aicr_check_update', nonce: AICR.nonce })
                    .done(function (r) {
                        if (r && r.success) {
                            var d = r.data || {};
                            var msg = 'Current: ' + d.current + ' / Latest: ' + (d.latest || '?');
                            if (d.newer) {
                                msg += ' — update available.';
                                setStatus($res, msg, 'is-success');
                            } else {
                                msg += ' — up to date.';
                                setStatus($res, msg, '');
                            }
                        } else {
                            setStatus($res, (r && r.data && r.data.message) ? r.data.message : 'Error', 'is-error');
                        }
                    }).fail(function () { setStatus($res, 'AJAX failure', 'is-error'); });
            });
        }
    }

    $(function () {
        bindMetabox();
        bindSettings();
    });
}(jQuery));
