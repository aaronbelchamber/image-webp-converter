(function () {
    'use strict';

    var BATCH_SIZE = 5;

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
        summaryEl.textContent = 'Scanning…';
        scanPage(0, 0, null);
    });

    // The server buckets one page per request; keep asking until it says
    // done, carrying the last_id cursor forward. Scanning a whole Media
    // Library in a single request times out on anything real-sized.
    function scanPage(afterId, scanned, total) {
        post('iwc_bulk_scan', afterId ? { after_id: afterId } : {}).then(function (response) {
            if (!response || !response.success) {
                scanButton.disabled = false;
                fail(summaryEl, 'Scan failed. Nothing was changed.');
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
                    ? 'Scanning… ' + scanned + ' / ' + total + ' images checked'
                    : 'Scanning… ' + scanned + ' images checked';
                scanPage(data.last_id, scanned, total);
                return;
            }

            scanButton.disabled = false;
            renderScanSummary();
        }).catch(function (err) {
            scanButton.disabled = false;
            fail(summaryEl, 'Scan failed: ' + err.message + '. Nothing was changed.');
        });
    }

    function renderScanSummary() {
        var total = queue.length;
        var unreferenced = queue.filter(function (i) { return i.bucket === 'unreferenced'; }).length;
        var plainContent = total - unreferenced;

        var lines = [
            unreferenced + ' ready to convert immediately (not used anywhere yet).',
            plainContent + ' will be converted and have their content references updated automatically.',
            serializedOnlyCount + ' are used in a way this tool does not safely update yet (widgets, page builders) — left untouched.',
        ];
        summaryEl.innerHTML = lines.map(function (l) { return '<p>' + l + '</p>'; }).join('');

        if (total > 0) {
            var startBtn = document.createElement('button');
            startBtn.type = 'button';
            startBtn.className = 'button button-primary';
            startBtn.textContent = 'Start Conversion (' + total + ')';
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
                progressText.textContent = processed + ' / ' + total + ' processed';
                next();
            }).catch(function (err) {
                // Without this the queue stalled silently on the first 502
                // from a slow batch, leaving a frozen progress bar and no
                // indication anything had gone wrong.
                converting = false;
                scanButton.disabled = false;
                progressText.textContent = 'Stopped after ' + processed + ' of ' + total + '.';
                fail(finalSummaryEl,
                    'Conversion stopped: ' + err.message +
                    '. Images already processed are safe; re-scan to continue with the rest.');
            });
        }

        next();
    }

    function finish() {
        converting = false;
        scanButton.disabled = false;
        progressText.textContent = 'Done.';
        finalSummaryEl.innerHTML =
            '<p><strong>Conversion complete.</strong></p>' +
            '<p>' + totals.trashed + ' converted and cleaned up automatically.</p>' +
            '<p>' + totals.pending_cleanup + ' converted, content updated, originals kept pending your review — see the Cleanup Review tab.</p>' +
            (totals.references_failed
                ? '<p>' + totals.references_failed + ' converted, but some references could not be updated automatically — originals kept and not offered for cleanup.</p>'
                : '') +
            (totals.error ? '<p>' + totals.error + ' had an error and were left unchanged.</p>' : '') +
            (totals.skipped ? '<p>' + totals.skipped + ' were skipped.</p>' : '');
    }
})();
