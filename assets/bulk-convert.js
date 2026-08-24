(function () {
    'use strict';

    var BATCH_SIZE = 5;

    // Translated strings come from wp_localize_script; see IWC_Admin::script_strings().
    // The fallbacks keep the UI legible if localisation ever fails to attach,
    // rather than rendering "undefined" at the user.
    var i18n = (typeof iwcBulkConvert !== 'undefined' && iwcBulkConvert.i18n) ? iwcBulkConvert.i18n : {};

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    /**
     * Fill %s / %1$s / %2$s placeholders, the way PHP's sprintf does.
     *
     * Numbered placeholders matter: several languages need the count somewhere
     * other than the front of the sentence, and a translator cannot move a
     * bare %s past another one.
     */
    function fmt(template, args) {
        var i = 0;
        return String(template).replace(/%(\d+\$)?s/g, function (_match, position) {
            if (position) {
                return args[parseInt(position, 10) - 1];
            }
            return args[i++];
        });
    }

    var scanButton = document.getElementById('iwc-scan-button');
    var summaryEl = document.getElementById('iwc-scan-summary');
    var progressWrap = document.getElementById('iwc-progress-wrap');
    var progressBar = document.getElementById('iwc-progress-bar');
    var progressText = document.getElementById('iwc-progress-text');
    var finalSummaryEl = document.getElementById('iwc-final-summary');

    var queue = []; // [{id, bucket}, ...]
    var serializedOnlyCount = 0;
    var totals = { trashed: 0, pending_cleanup: 0, references_failed: 0, skipped: 0, error: 0 };
    var converting = false;

    function post(action, data) {
        var params = new URLSearchParams(data || {});
        params.set('action', action);
        params.set('nonce', iwcBulkConvert.nonce);
        return fetch(iwcBulkConvert.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('Server returned ' + res.status);
            }
            return res.json();
        });
    }

    function fail(el, message) {
        el.innerHTML = '<div class="notice notice-error"><p>' + message + '</p></div>';
    }

    // --- Scan (paged) -----------------------------------------------------

    scanButton.addEventListener('click', function () {
        if (converting) {
            return;
        }
        scanButton.disabled = true;
        queue = [];
        serializedOnlyCount = 0;
        finalSummaryEl.innerHTML = '';
        summaryEl.textContent = t('scanning', 'Scanning…');
        scanPage(0, 0, null);
    });

    // The server buckets one page per request; keep asking until it says
    // done, carrying the last_id cursor forward. Scanning a whole Media
    // Library in a single request times out on anything real-sized.
    function scanPage(afterId, scanned, total) {
        post('iwc_bulk_scan', afterId ? { after_id: afterId } : {}).then(function (response) {
            if (!response || !response.success) {
                scanButton.disabled = false;
                fail(summaryEl, t('scanFailed', 'Scan failed. Nothing was changed.'));
                return;
            }

            var data = response.data;
            if (typeof data.total === 'number') {
                total = data.total;
            }
            scanned += data.scanned;

            data.unreferenced.forEach(function (id) { queue.push({ id: id, bucket: 'unreferenced' }); });
            data.plain_content.forEach(function (id) { queue.push({ id: id, bucket: 'plain_content' }); });
            serializedOnlyCount += data.serialized_only_count;

            if (!data.done) {
                summaryEl.textContent = total
                    ? fmt(t('scanningProgress', 'Scanning… %1$s / %2$s images checked'), [scanned, total])
                    : fmt(t('scanningCount', 'Scanning… %s images checked'), [scanned]);
                scanPage(data.last_id, scanned, total);
                return;
            }

            scanButton.disabled = false;
            renderScanSummary();
        }).catch(function (err) {
            scanButton.disabled = false;
            fail(summaryEl, fmt(t('scanFailedReason', 'Scan failed: %s. Nothing was changed.'), [err.message]));
        });
    }

    function renderScanSummary() {
        var total = queue.length;
        var unreferenced = queue.filter(function (i) { return i.bucket === 'unreferenced'; }).length;
        var plainContent = total - unreferenced;

        var lines = [
            fmt(t('readyNow', '%s ready to convert immediately (not used anywhere yet).'), [unreferenced]),
            fmt(t('readyWithRewrite', '%s will be converted and have their content references updated automatically.'), [plainContent]),
            fmt(t('leftUntouched', '%s are used in a way this tool does not safely update yet (widgets, page builders) — left untouched.'), [serializedOnlyCount]),
        ];
        summaryEl.innerHTML = lines.map(function (l) { return '<p>' + l + '</p>'; }).join('');

        if (total > 0) {
            var startBtn = document.createElement('button');
            startBtn.type = 'button';
            startBtn.className = 'button button-primary';
            startBtn.textContent = fmt(t('startConversion', 'Start Conversion (%s)'), [total]);
            startBtn.addEventListener('click', function () {
                startBtn.disabled = true;
                scanButton.disabled = true;
                runQueue();
            });
            summaryEl.appendChild(startBtn);
        }
    }

    // --- Convert ----------------------------------------------------------

    function runQueue() {
        converting = true;
        progressWrap.style.display = 'block';
        var total = queue.length;
        var processed = 0;
        totals = { trashed: 0, pending_cleanup: 0, references_failed: 0, skipped: 0, error: 0 };

        function next() {
            if (queue.length === 0) {
                finish();
                return;
            }
            var batch = queue.splice(0, BATCH_SIZE);
            // A batch must share one bucket, since the endpoint takes a single bucket param.
            var bucket = batch[0].bucket;
            var sameBucket = batch.filter(function (item) { return item.bucket === bucket; });
            var rest = batch.filter(function (item) { return item.bucket !== bucket; });
            queue = rest.concat(queue);

            var ids = sameBucket.map(function (item) { return item.id; });

            post('iwc_bulk_process_batch', { 'attachment_ids[]': ids, bucket: bucket }).then(function (response) {
                processed += ids.length;
                if (response.success) {
                    Object.keys(response.data.results).forEach(function (id) {
                        var status = response.data.results[id].status;
                        if (totals[status] !== undefined) {
                            totals[status]++;
                        }
                    });
                } else {
                    totals.error += ids.length;
                }
                progressBar.value = Math.round((processed / total) * 100);
                progressText.textContent = fmt(t('progress', '%1$s / %2$s processed'), [processed, total]);
                next();
            }).catch(function (err) {
                // Without this the queue stalled silently on the first 502
                // from a slow batch, leaving a frozen progress bar and no
                // indication anything had gone wrong.
                converting = false;
                scanButton.disabled = false;
                progressText.textContent = fmt(t('stoppedAfter', 'Stopped after %1$s of %2$s.'), [processed, total]);
                fail(finalSummaryEl, fmt(
                    t('conversionStopped', 'Conversion stopped: %s. Images already processed are safe; re-scan to continue with the rest.'),
                    [err.message]
                ));
            });
        }

        next();
    }

    function finish() {
        converting = false;
        scanButton.disabled = false;
        progressText.textContent = t('done', 'Done.');
        var parts = [
            '<p><strong>' + t('complete', 'Conversion complete.') + '</strong></p>',
            '<p>' + fmt(t('summaryTrashed', '%s converted and cleaned up automatically.'), [totals.trashed]) + '</p>',
            '<p>' + fmt(t('summaryPending', '%s converted, content updated, originals kept pending your review — see the Cleanup Review tab.'), [totals.pending_cleanup]) + '</p>',
        ];
        if (totals.references_failed) {
            parts.push('<p>' + fmt(t('summaryRefFailed', '%s converted, but some references could not be updated automatically — originals kept and not offered for cleanup.'), [totals.references_failed]) + '</p>');
        }
        if (totals.error) {
            parts.push('<p>' + fmt(t('summaryError', '%s had an error and were left unchanged.'), [totals.error]) + '</p>');
        }
        if (totals.skipped) {
            parts.push('<p>' + fmt(t('summarySkipped', '%s were skipped.'), [totals.skipped]) + '</p>');
        }
        finalSummaryEl.innerHTML = parts.join('');
    }
})();
