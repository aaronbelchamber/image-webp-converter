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
    var totals = { trashed: 0, pending_cleanup: 0, skipped: 0, error: 0 };

    function post(action, data) {
        var params = new URLSearchParams(data || {});
        params.set('action', action);
        params.set('nonce', iwcBulkConvert.nonce);
        return fetch(iwcBulkConvert.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
        }).then(function (res) { return res.json(); });
    }

    scanButton.addEventListener('click', function () {
        scanButton.disabled = true;
        summaryEl.textContent = 'Scanning…';

        post('iwc_bulk_scan').then(function (response) {
            scanButton.disabled = false;
            if (!response.success) {
                summaryEl.textContent = 'Scan failed.';
                return;
            }

            var data = response.data;
            queue = [];
            data.unreferenced.forEach(function (id) { queue.push({ id: id, bucket: 'unreferenced' }); });
            data.plain_content.forEach(function (id) { queue.push({ id: id, bucket: 'plain_content' }); });

            var total = queue.length;
            var lines = [];
            lines.push(data.unreferenced.length + ' ready to convert immediately (not used anywhere yet).');
            lines.push(data.plain_content.length + ' will be converted and have their content references updated automatically.');
            lines.push(data.serialized_only_count + ' are used in a way this tool doesn\'t safely update yet (widgets, page builders) — left untouched.');
            summaryEl.innerHTML = lines.map(function (l) { return '<p>' + l + '</p>'; }).join('');

            if (total > 0) {
                var startBtn = document.createElement('button');
                startBtn.type = 'button';
                startBtn.className = 'button button-primary';
                startBtn.textContent = 'Start Conversion (' + total + ')';
                startBtn.addEventListener('click', function () { runQueue(); });
                summaryEl.appendChild(startBtn);
            }
        });
    });

    function runQueue() {
        progressWrap.style.display = 'block';
        var total = queue.length;
        var processed = 0;
        totals = { trashed: 0, pending_cleanup: 0, skipped: 0, error: 0 };

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
                }
                var pct = Math.round((processed / total) * 100);
                progressBar.value = pct;
                progressText.textContent = processed + ' / ' + total + ' processed';
                next();
            });
        }

        next();
    }

    function finish() {
        progressText.textContent = 'Done.';
        finalSummaryEl.innerHTML =
            '<p><strong>Conversion complete.</strong></p>' +
            '<p>' + totals.trashed + ' converted and cleaned up automatically.</p>' +
            '<p>' + totals.pending_cleanup + ' converted, content updated, originals kept pending your review — see the Cleanup Review tab.</p>' +
            (totals.error ? '<p>' + totals.error + ' had an error and were left unchanged.</p>' : '') +
            (totals.skipped ? '<p>' + totals.skipped + ' were skipped.</p>' : '');
    }
})();
