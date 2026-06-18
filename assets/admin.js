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

    function nowHHMMSS() {
        var d = new Date();
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    /**
     * Reads an SSE stream from a fetch() ReadableStream. Calls onEvent(name, data) per event.
     * Returns a promise resolved when the stream closes.
     */
    function readSSE(response, onEvent) {
        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '';

        function parseBuffer() {
            var sep;
            while ((sep = buffer.indexOf('\n\n')) !== -1) {
                var raw = buffer.slice(0, sep);
                buffer = buffer.slice(sep + 2);
                var lines = raw.split('\n');
                var ev = 'message';
                var dataLines = [];
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i];
                    if (line.indexOf('event:') === 0) { ev = line.slice(6).trim(); }
                    else if (line.indexOf('data:') === 0) { dataLines.push(line.slice(5).trim()); }
                }
                var dataStr = dataLines.join('\n');
                var data = null;
                if (dataStr) {
                    try { data = JSON.parse(dataStr); } catch (e) { data = dataStr; }
                }
                onEvent(ev, data);
            }
        }

        function pump() {
            return reader.read().then(function (r) {
                if (r.done) { return; }
                buffer += decoder.decode(r.value, { stream: true });
                parseBuffer();
                return pump();
            });
        }
        return pump();
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
        var $log = $box.find('.aicr-log');
        var $logBody = $box.find('.aicr-log-body');

        var fieldsCache = null;

        function appendLog(level, text, meta) {
            if (!$log.length) { return; }
            $log.prop('hidden', false);
            var metaHtml = meta ? ' <span class="aicr-log-meta">' + escapeHtml(meta) + '</span>' : '';
            var line = '<div class="aicr-log-line aicr-log-' + escapeHtml(level) + '">'
                + '<span class="aicr-log-time">' + nowHHMMSS() + '</span> '
                + '<span class="aicr-log-msg">' + escapeHtml(text) + '</span>'
                + metaHtml
                + '</div>';
            $logBody.append(line);
            $logBody.scrollTop($logBody[0].scrollHeight);
        }

        function clearLog() {
            $logBody.empty();
            $log.prop('hidden', true);
        }

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
            clearLog();
            appendLog('info', 'Initiating preview…');

            var selected = $list.find('.aicr-field-cb:checked').map(function () { return this.value; }).get();

            var formData = new FormData();
            formData.append('post_id', String(postId));
            formData.append('nonce', AICR.nonce);
            formData.append('extra', $('#aicr_extra_prompt').val() || '');
            selected.forEach(function (v) { formData.append('fields[]', v); });

            // Stream via fetch + SSE.
            fetch(AICR.stream_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            }).then(function (response) {
                if (!response.ok || !response.body) {
                    throw new Error('HTTP ' + response.status);
                }
                appendLog('info', 'Connected to server. Streaming progress…');
                return readSSE(response, handleSSE);
            }).then(function () {
                $spinner.removeClass('is-active');
            }).catch(function (err) {
                $spinner.removeClass('is-active');
                appendLog('error', String(err.message || err));
                setStatus($status, AICR.i18n.error + (err.message || err), 'is-error');
            });
        });

        function handleSSE(ev, data) {
            switch (ev) {
                case 'status':
                    appendLog('info', data && data.message ? data.message : '');
                    setStatus($status, data && data.message ? data.message : '', '');
                    break;
                case 'field_start':
                    appendLog('step', '[' + data.index + '/' + data.total + '] ' + data.label,
                        data.format + ' · ' + data.chars + ' chars');
                    break;
                case 'field_sending':
                    appendLog('info', '  → Sending to ' + (data.model || 'Anthropic') + '…');
                    break;
                case 'field_done':
                    appendLog('ok',
                        '  ✓ Received in ' + data.elapsed + 's',
                        'in ' + data.input_tokens + ' / out ' + data.output_tokens + ' tok · $' + data.cost + ' · ' + data.preview_chars + ' chars');
                    break;
                case 'field_error':
                    appendLog('error', '  ✗ Failed: ' + (data.message || ''), 'after ' + data.elapsed + 's');
                    break;
                case 'error':
                    appendLog('error', data && data.message ? data.message : 'Unknown error');
                    setStatus($status, AICR.i18n.error + (data && data.message ? data.message : ''), 'is-error');
                    break;
                case 'done':
                    appendLog('ok', 'All fields processed. Total: $' + (data && data.total_cost != null ? (+data.total_cost).toFixed(5) : '0'));
                    renderPreview((data && data.items) || []);
                    setStatus($status, '', '');
                    break;
                case 'close':
                    // Stream closed by server.
                    break;
                default:
                    appendLog('info', ev + ': ' + JSON.stringify(data || {}));
            }
        }

        function renderPreview(items) {
            if (!items.length) {
                $items.html('<em>' + escapeHtml(AICR.i18n.no_preview) + '</em>');
                $preview.prop('hidden', false);
                return;
            }
            var html = items.map(function (it) {
                var hasError = !!it.error;
                var errBanner = hasError
                    ? '<div class="aicr-field-error">' + escapeHtml('Failed: ' + it.error) + '</div>'
                    : '';
                return '<div class="aicr-preview-item" data-id="' + escapeHtml(it.id) + '">'
                    + '<header>'
                    + '<h4>' + escapeHtml(it.label) + ' <small style="color:#888">[' + escapeHtml(it.format) + ']</small></h4>'
                    + '<label class="aicr-approve"><input type="checkbox" class="aicr-approve-cb"' + (hasError ? '' : ' checked') + ' /> Approve</label>'
                    + '</header>'
                    + errBanner
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
            clearLog();
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
                appendLog('ok', 'Applied ' + (r.data.applied || 0) + ' field(s).');
                setStatus($status, AICR.i18n.applied + ' (' + (r.data.applied || 0) + ')', 'is-success');
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
                setStatus($res, 'Testing…', '');
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
                setStatus($res, 'Checking…', '');
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
