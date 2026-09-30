/* Campaign Link Inspector: local, admin-only presentation. */
(function () {
    'use strict';
    const start = document.getElementById('cpli-start');
    if (!start) return;
    const next = document.getElementById('cpli-next');
    const exp = document.getElementById('cpli-export');
    const status = document.getElementById('cpli-status');
    const body = document.getElementById('cpli-results');
    let batch = 1, rows = [], busy = false;
    async function scan(number) {
        if (busy) return;
        busy = true; start.disabled = true; next.disabled = true; exp.disabled = true;
        rows = []; body.replaceChildren(); status.textContent = CPLI.text.loading;
        try {
            const data = new URLSearchParams({action: 'cpli_scan', nonce: CPLI.nonce, batch: String(number)});
            const response = await fetch(CPLI.url, {method: 'POST', credentials: 'same-origin', body: data});
            const json = await response.json();
            if (!response.ok || !json.success || !Array.isArray(json.data.rows)) throw new Error('scan');
            const result = json.data;
            rows = result.rows; batch = number;
            rows.forEach(row => {
                const tr = document.createElement('tr');
                const content = document.createElement('td');
                const title = document.createElement('div'); title.textContent = row.title || ('#' + row.post_id);
                content.append(title);
                if (row.edit) {
                    const url = new URL(row.edit, location.href);
                    if (url.origin === location.origin && ['http:', 'https:'].includes(url.protocol)) {
                        const edit = document.createElement('a');
                        edit.href = url.href; edit.textContent = CPLI.text.edit;
                        content.append(edit);
                    }
                }
                tr.append(content);
                [row.position || '—', row.url].forEach(value => {
                    const td = document.createElement('td'); td.textContent = value; tr.append(td);
                });
                const findings = document.createElement('td');
                row.messages.forEach(message => {
                    const line = document.createElement('div'); line.textContent = message; findings.append(line);
                });
                tr.append(findings);
                body.append(tr);
            });
            status.replaceChildren();
            [
                [CPLI.text.page, batch, '—', CPLI.text.posts, result.posts,
                    '—', CPLI.text.links, result.links, '—', CPLI.text.limits, result.limited].join(' '),
                [result.more ? CPLI.text.more : CPLI.text.done, rows.length ? '' : CPLI.text.empty].join(' ').trim()
            ].forEach(text => {
                const line = document.createElement('span'); line.textContent = text; status.append(line);
            });
            next.disabled = !result.more;
            exp.disabled = !rows.length;
        } catch (_) {
            status.textContent = CPLI.text.error;
        } finally {
            busy = false; start.disabled = false;
        }
    }
    function csv(value) {
        let text = String(value == null ? '' : value);
        // Protect spreadsheet consumers from formula interpretation.
        if (/^[\s\u0000-\u001f]*[=+@-]/.test(text) || /^[\t\r\n]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }
    start.addEventListener('click', () => scan(1));
    next.addEventListener('click', () => scan(batch + 1));
    exp.addEventListener('click', () => {
        const data = [['post_id', 'title', 'anchor_position', 'url_query_values_omitted', 'rule_codes', 'findings']];
        rows.forEach(row => data.push([row.post_id, row.title, row.position, row.url, row.codes.join('|'), row.messages.join(' ')]));
        const blob = new Blob(['\uFEFF' + data.map(row => row.map(csv).join(',')).join('\r\n')], {type: 'text/csv;charset=utf-8'});
        const url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = 'campaign-links-batch-' + batch + '.csv';
        a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
}());
