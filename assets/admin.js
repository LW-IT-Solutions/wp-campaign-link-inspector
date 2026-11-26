/* Campaign Link Inspector: local, admin-only presentation. */
(function () {
    'use strict';
    const start = document.getElementById('cpli-start');
    if (!start || !window.CPLI) return;
    const next = document.getElementById('cpli-next');
    const exp = document.getElementById('cpli-export');
    const status = document.getElementById('cpli-status');
    const spinner = document.getElementById('cpli-spinner');
    const empty = document.getElementById('cpli-empty');
    const report = document.getElementById('cpli-report');
    const tiles = document.getElementById('cpli-tiles');
    const chart = document.getElementById('cpli-chart');
    const bars = document.getElementById('cpli-bars');
    const filterLine = document.getElementById('cpli-filter-line');
    const filterText = document.getElementById('cpli-filter-text');
    const filterReset = document.getElementById('cpli-filter-reset');
    const tableBox = document.getElementById('cpli-table');
    const body = document.getElementById('cpli-results');
    const text = CPLI.text || {};
    const labels = CPLI.labels || {};
    const kinds = CPLI.kinds || {};
    const order = ['check', 'review', 'hint'];
    let batch = 1, rows = [], busy = false, filter = '';

    function el(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined && content !== null) node.textContent = String(content);
        return node;
    }
    function format(template, values) {
        let i = 0;
        return String(template || '').replace(/%(\d+\$)?[sd]/g, (match, pos) => {
            const index = pos ? parseInt(pos, 10) - 1 : i++;
            return values[index] === undefined ? '' : String(values[index]);
        });
    }
    function kindOf(code) {
        return kinds[code] || 'hint';
    }
    function notice(type, lines) {
        status.replaceChildren();
        const box = el('div', 'notice inline notice-' + type);
        lines.filter(Boolean).forEach(line => box.append(el('p', '', line)));
        status.append(box);
    }
    function setBusy(on) {
        busy = on;
        spinner.classList.toggle('is-active', on);
        report.setAttribute('aria-busy', on ? 'true' : 'false');
        start.disabled = on;
        if (on) { next.disabled = true; exp.disabled = true; }
    }

    function renderTiles(result) {
        tiles.replaceChildren();
        const first = (batch - 1) * 10 + 1;
        const withFindings = rows.filter(row => row.position).length;
        [
            [text.page, batch, result.posts ? format(text.range, [first, first + result.posts - 1]) : '', ''],
            [text.posts, result.posts, '', ''],
            [text.links, result.links, '', ''],
            [text.findings, withFindings, '', withFindings ? 'is-warn' : 'is-ok'],
            [text.limits, result.limited, '', result.limited ? 'is-warn' : '']
        ].forEach(([label, value, sub, mod]) => {
            const tile = el('div', 'cpli-tile ' + mod);
            tile.append(el('dt', '', label));
            const dd = el('dd', '', value);
            if (sub) dd.append(el('span', 'cpli-tile-sub', sub));
            tile.append(dd);
            tiles.append(tile);
        });
    }

    function renderChart() {
        const counts = {};
        rows.forEach(row => (row.codes || []).forEach(code => { counts[code] = (counts[code] || 0) + 1; }));
        const codes = Object.keys(counts).sort((a, b) =>
            order.indexOf(kindOf(a)) - order.indexOf(kindOf(b)) || counts[b] - counts[a]);
        bars.replaceChildren();
        chart.hidden = !codes.length;
        const max = Math.max(1, ...codes.map(code => counts[code]));
        codes.forEach(code => {
            const li = el('li');
            const button = el('button', 'cpli-bar is-' + kindOf(code));
            button.type = 'button';
            button.dataset.code = code;
            button.setAttribute('aria-pressed', filter === code ? 'true' : 'false');
            button.append(el('span', 'cpli-bar-label', labels[code] || code));
            const track = el('span', 'cpli-bar-track');
            track.setAttribute('aria-hidden', 'true');
            const fill = el('span', 'cpli-bar-fill');
            fill.style.width = Math.max(4, Math.round(counts[code] / max * 100)) + '%';
            track.append(fill);
            button.append(track, el('span', 'cpli-bar-value', counts[code]));
            button.addEventListener('click', () => setFilter(filter === code ? '' : code));
            li.append(button);
            bars.append(li);
        });
    }

    function renderRows() {
        body.replaceChildren();
        rows.forEach(row => {
            const tr = el('tr');
            tr.dataset.codes = (row.codes || []).join(' ');
            const content = el('td', 'cpli-col-content');
            content.dataset.label = text.colContent;
            content.append(el('strong', 'cpli-title', row.title || ('#' + row.post_id)));
            if (row.edit) {
                const url = new URL(row.edit, location.href);
                if (url.origin === location.origin && ['http:', 'https:'].includes(url.protocol)) {
                    const edit = el('a', 'cpli-edit', text.edit);
                    edit.href = url.href;
                    content.append(edit);
                }
            }
            const position = el('td', 'cpli-col-position', row.position ? format(text.position, [row.position]) : text.whole);
            position.dataset.label = text.colPosition;
            const link = el('td', 'cpli-col-url');
            link.dataset.label = text.colUrl;
            if (row.url) link.append(el('code', '', row.url)); else link.textContent = '—';
            const findings = el('td', 'cpli-col-finding');
            findings.dataset.label = text.colFinding;
            const badges = el('div', 'cpli-badges');
            (row.codes || []).forEach(code => badges.append(el('span', 'cpli-badge is-' + kindOf(code), labels[code] || code)));
            findings.append(badges);
            const list = el('ul', 'cpli-messages');
            (row.messages || []).forEach(message => list.append(el('li', '', message)));
            findings.append(list);
            tr.append(content, position, link, findings);
            body.append(tr);
        });
        tableBox.hidden = !rows.length;
        applyFilter();
    }

    function setFilter(code) {
        filter = code;
        bars.querySelectorAll('.cpli-bar').forEach(button =>
            button.setAttribute('aria-pressed', button.dataset.code === filter ? 'true' : 'false'));
        applyFilter();
    }
    function applyFilter() {
        body.querySelectorAll('tr').forEach(tr => {
            tr.hidden = !!filter && !tr.dataset.codes.split(' ').includes(filter);
        });
        filterLine.hidden = !filter;
        filterText.textContent = filter ? format(text.filtered, [labels[filter] || filter]) : '';
    }

    async function scan(number) {
        if (busy) return;
        setBusy(true);
        notice('info', [text.loading]);
        try {
            const data = new URLSearchParams({action: 'cpli_scan', nonce: CPLI.nonce, batch: String(number)});
            const response = await fetch(CPLI.url, {method: 'POST', credentials: 'same-origin', body: data});
            const json = await response.json();
            if (!response.ok || !json.success || !Array.isArray(json.data.rows)) throw new Error('scan');
            const result = json.data;
            rows = result.rows; batch = number; filter = '';
            empty.hidden = true;
            report.hidden = false;
            renderTiles(result);
            renderChart();
            renderRows();
            notice(rows.length ? 'info' : 'success', [rows.length ? '' : text.empty, result.more ? text.more : text.done]);
            next.disabled = !result.more;
            exp.disabled = !rows.length;
        } catch (_) {
            notice('error', [text.error]);
        } finally {
            setBusy(false);
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
    filterReset.addEventListener('click', () => setFilter(''));
    exp.addEventListener('click', () => {
        const data = [['post_id', 'title', 'anchor_position', 'url_query_values_omitted', 'rule_codes', 'findings']];
        rows.forEach(row => data.push([row.post_id, row.title, row.position, row.url, row.codes.join('|'), row.messages.join(' ')]));
        const blob = new Blob(['﻿' + data.map(row => row.map(csv).join(',')).join('\r\n')], {type: 'text/csv;charset=utf-8'});
        const url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = 'campaign-links-batch-' + batch + '.csv';
        a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
}());
