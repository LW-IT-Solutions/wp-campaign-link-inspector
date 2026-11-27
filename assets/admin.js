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
    const success = document.getElementById('cpli-success');
    const successText = document.getElementById('cpli-success-text');
    const ringCard = document.getElementById('cpli-ring-card');
    const ringBox = document.getElementById('cpli-ring');
    const ringLegend = document.getElementById('cpli-ring-legend');
    const ringHelp = document.getElementById('cpli-ring-help');
    const history = document.getElementById('cpli-history');
    const historyBars = document.getElementById('cpli-history-bars');
    const historySum = document.getElementById('cpli-history-sum');
    const tools = document.getElementById('cpli-tools');
    const search = document.getElementById('cpli-search');
    const kindBox = document.getElementById('cpli-kinds');
    const matches = document.getElementById('cpli-matches');
    const text = CPLI.text || {};
    const labels = CPLI.labels || {};
    const kinds = CPLI.kinds || {};
    const order = ['check', 'review', 'hint'];
    const kindText = {check: text.kindCheck, review: text.kindReview, hint: text.kindHint, clean: text.kindClean};
    const fieldText = {ok: text.fieldOk, empty: text.fieldEmpty, missing: text.fieldMiss, issue: text.fieldIssue};
    const fieldIcon = {ok: '✓', empty: '○', missing: '–', issue: '!'};
    // Namespace taken from the inline illustration of the page, so no address is written in the code.
    const svgNs = document.querySelector('#cpli-empty svg').namespaceURI;
    const calm = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    let batch = 1, rows = [], busy = false, filter = '', kindFilter = '', query = '';
    // Batches read on this page: kept in memory only, for the progress strip.
    const seen = new Map();
    let endBatch = 0;

    function el(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined && content !== null) node.textContent = String(content);
        return node;
    }
    function svg(tag, attrs) {
        const node = document.createElementNS(svgNs, tag);
        Object.keys(attrs || {}).forEach(key => node.setAttribute(key, attrs[key]));
        return node;
    }
    function format(template, values) {
        let i = 0;
        return String(template || '').replace(/%(\d+\$)?[sd]/g, (match, pos) => {
            const index = pos ? parseInt(pos, 10) - 1 : i++;
            return values[index] === undefined ? '' : String(values[index]);
        }).replace(/%%/g, '%');
    }
    function kindOf(code) {
        return kinds[code] || 'hint';
    }
    // The most serious group of a row: one color per link in the ring.
    function worstKind(row) {
        const list = (row.codes || []).map(kindOf);
        return order.find(kind => list.includes(kind)) || 'hint';
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

    // Numbers count up once when they appear; with reduced motion they are set at once.
    function countUp(node, value) {
        const end = Number(value) || 0;
        node.textContent = String(end);
        if (calm || end < 2) return;
        const t0 = performance.now(), span = 600;
        node.textContent = '0';
        const step = now => {
            const p = Math.min(1, (now - t0) / span);
            node.textContent = String(Math.round(end * (1 - Math.pow(1 - p, 3))));
            if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
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
            const dd = el('dd');
            const num = el('span', 'cpli-tile-num', value);
            dd.append(num);
            if (sub) dd.append(el('span', 'cpli-tile-sub', sub));
            tile.append(dd);
            tiles.append(tile);
            countUp(num, value);
        });
    }

    // One tooltip for the ring, shown on hover and on keyboard focus.
    let tipNode = null;
    function tip(label) {
        if (!tipNode || tipNode.parentNode !== ringBox) {
            tipNode = el('div', 'cpli-tip');
            tipNode.setAttribute('aria-hidden', 'true');
            ringBox.append(tipNode);
        }
        tipNode.textContent = label;
        tipNode.hidden = false;
    }
    function untip() {
        if (tipNode) tipNode.hidden = true;
    }

    // Ring: every inspected link once, by its most serious finding, plus the links without findings.
    function renderRing(result) {
        const parts = {check: 0, review: 0, hint: 0};
        rows.forEach(row => { if (row.position) parts[worstKind(row)]++; });
        const flagged = parts.check + parts.review + parts.hint;
        const total = Math.max(result.links, flagged);
        ringCard.hidden = !total;
        ringBox.replaceChildren();
        ringLegend.replaceChildren();
        tipNode = null;
        if (!total) return;
        const clean = total - flagged;
        const share = n => Math.round(n / total * 100);
        ringHelp.textContent = flagged ? text.ringFilter : '';
        const r = 15.9155, len = 2 * Math.PI * r;
        const chartSvg = svg('svg', {viewBox: '0 0 42 42', class: 'cpli-ring-svg', role: 'group', 'aria-label': format(text.ringCenter, [share(clean)])});
        chartSvg.append(svg('circle', {cx: 21, cy: 21, r: r, class: 'cpli-ring-track'}));
        let offset = 0;
        const list = [['clean', clean]].concat(order.map(kind => [kind, parts[kind]])).filter(item => item[1] > 0);
        list.forEach(([kind, n], index) => {
            const dash = n / total * len;
            const gap = list.length > 1 ? Math.min(0.6, dash / 3) : 0;
            const label = format(text.ringPart, [n, share(n), kindText[kind]]);
            const seg = svg('circle', {
                cx: 21, cy: 21, r: r, class: 'cpli-ring-seg is-' + kind,
                'stroke-dasharray': (dash - gap).toFixed(3) + ' ' + (len - dash + gap).toFixed(3),
                'stroke-dashoffset': (len / 4 - offset).toFixed(3),
                tabindex: '0', role: kind === 'clean' ? 'img' : 'button', 'aria-label': label, 'data-kind': kind
            });
            if (kind !== 'clean') seg.setAttribute('aria-pressed', kindFilter === kind ? 'true' : 'false');
            seg.style.setProperty('--cpli-delay', (index * 90) + 'ms');
            const title = svg('title');
            title.textContent = label;
            seg.append(title);
            offset += dash;
            const pick = () => { if (kind !== 'clean') setKind(kindFilter === kind ? '' : kind, true); };
            seg.addEventListener('click', pick);
            seg.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); pick(); }
            });
            seg.addEventListener('mouseenter', () => tip(label));
            seg.addEventListener('focus', () => tip(label));
            seg.addEventListener('mouseleave', untip);
            seg.addEventListener('blur', untip);
            chartSvg.append(seg);
            const li = el('li', 'is-' + kind);
            li.append(el('span', 'cpli-ring-key'), el('span', 'cpli-ring-name', kindText[kind]), el('strong', 'cpli-ring-num', n));
            ringLegend.append(li);
        });
        const center = el('div', 'cpli-ring-center');
        center.append(el('strong', '', share(clean) + ' %'), el('span', '', text.ringClean));
        center.setAttribute('aria-hidden', 'true');
        ringBox.append(chartSvg, center);
        ringBox.classList.remove('is-drawn');
        if (calm) {
            ringBox.classList.add('is-drawn');
        } else {
            requestAnimationFrame(() => requestAnimationFrame(() => ringBox.classList.add('is-drawn')));
        }
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

    // URLs break only after "/", "?", "&" or "=": each piece is kept together, so words are never cut in the middle.
    function addText(node, value) {
        if (node.tagName !== 'CODE') {
            node.append(document.createTextNode(value));
            return;
        }
        let part = '';
        const flush = () => { if (part) node.append(el('span', 'cpli-seg', part)); part = ''; };
        for (const ch of String(value)) {
            part += ch;
            if ('/?&='.includes(ch)) flush();
        }
        flush();
    }

    function fieldMatrix(fields) {
        const names = Object.keys(fields || {});
        if (!names.length) return null;
        const list = el('ul', 'cpli-fields');
        list.setAttribute('aria-label', text.fieldsLabel);
        names.forEach(name => {
            const state = fields[name];
            const li = el('li', 'is-' + state);
            li.title = 'utm_' + name + ': ' + (fieldText[state] || state);
            const icon = el('span', 'cpli-field-icon', fieldIcon[state] || '');
            icon.setAttribute('aria-hidden', 'true');
            li.append(icon, el('span', 'cpli-field-name', name), el('span', 'screen-reader-text', ': ' + (fieldText[state] || state)));
            list.append(li);
        });
        return list;
    }

    function renderRows() {
        body.replaceChildren();
        rows.forEach(row => {
            const tr = el('tr');
            tr.dataset.codes = (row.codes || []).join(' ');
            tr.dataset.kinds = Array.from(new Set((row.codes || []).map(kindOf))).join(' ');
            tr.dataset.find = ((row.title || '') + ' ' + (row.url || '')).toLowerCase();
            const content = el('td', 'cpli-col-content');
            content.dataset.label = text.colContent;
            const title = el('strong', 'cpli-title cpli-hl', row.title || ('#' + row.post_id));
            title.dataset.raw = title.textContent;
            content.append(title);
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
            if (row.url) {
                const code = el('code', 'cpli-hl');
                code.dataset.raw = row.url;
                addText(code, row.url);
                link.append(code);
                const matrix = fieldMatrix(row.fields);
                if (matrix) link.append(matrix);
            } else {
                link.textContent = '—';
            }
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
        tools.hidden = !rows.length;
        renderKinds();
        applyFilter();
    }

    // Group chips with counts above the table.
    function renderKinds() {
        kindBox.replaceChildren();
        const counts = {check: 0, review: 0, hint: 0};
        rows.forEach(row => new Set((row.codes || []).map(kindOf)).forEach(kind => counts[kind]++));
        order.filter(kind => counts[kind]).forEach(kind => {
            const chip = el('button', 'cpli-kind is-' + kind);
            chip.type = 'button';
            chip.dataset.kind = kind;
            chip.setAttribute('aria-pressed', kindFilter === kind ? 'true' : 'false');
            const dot = el('span', 'cpli-kind-dot');
            dot.setAttribute('aria-hidden', 'true');
            chip.append(dot, el('span', '', kindText[kind]), el('span', 'cpli-kind-num', counts[kind]));
            chip.addEventListener('click', () => setKind(kindFilter === kind ? '' : kind, false));
            kindBox.append(chip);
        });
    }

    function setFilter(code) {
        filter = code;
        bars.querySelectorAll('.cpli-bar').forEach(button =>
            button.setAttribute('aria-pressed', button.dataset.code === filter ? 'true' : 'false'));
        applyFilter();
    }
    function setKind(kind, scroll) {
        kindFilter = kind;
        kindBox.querySelectorAll('.cpli-kind').forEach(chip =>
            chip.setAttribute('aria-pressed', chip.dataset.kind === kindFilter ? 'true' : 'false'));
        ringBox.querySelectorAll('.cpli-ring-seg').forEach(seg => {
            seg.classList.toggle('is-dim', !!kindFilter && seg.dataset.kind !== kindFilter);
            if (seg.dataset.kind !== 'clean') seg.setAttribute('aria-pressed', seg.dataset.kind === kindFilter ? 'true' : 'false');
        });
        applyFilter();
        if (scroll && kindFilter && !tools.hidden) tools.scrollIntoView({behavior: calm ? 'auto' : 'smooth', block: 'start'});
    }

    // Marks every hit of the filter text inside an element; text is only ever added as text nodes.
    function highlight(node, needle) {
        const raw = node.dataset.raw || '';
        node.replaceChildren();
        if (!needle) {
            addText(node, raw);
            return;
        }
        const lower = raw.toLowerCase();
        let from = 0, at;
        while ((at = lower.indexOf(needle, from)) !== -1) {
            if (at > from) addText(node, raw.slice(from, at));
            node.append(el('mark', 'cpli-mark', raw.slice(at, at + needle.length)));
            from = at + needle.length;
        }
        if (from < raw.length) addText(node, raw.slice(from));
    }

    function applyFilter() {
        let shown = 0;
        body.querySelectorAll('tr').forEach(tr => {
            const ok = (!filter || tr.dataset.codes.split(' ').includes(filter))
                && (!kindFilter || tr.dataset.kinds.split(' ').includes(kindFilter))
                && (!query || tr.dataset.find.includes(query));
            tr.hidden = !ok;
            if (ok) shown++;
            tr.querySelectorAll('.cpli-hl').forEach(node => highlight(node, ok ? query : ''));
        });
        filterLine.hidden = !filter;
        filterText.textContent = filter ? format(text.filtered, [labels[filter] || filter]) : '';
        const active = filter || kindFilter || query;
        matches.textContent = !active ? '' : (shown ? format(text.matches, [shown]) : text.noMatch);
        matches.classList.toggle('is-none', !!active && !shown);
    }

    // Progress strip: one stacked column per batch read on this page.
    function renderHistory() {
        historyBars.replaceChildren();
        const list = Array.from(seen.values()).sort((a, b) => a.batch - b.batch);
        history.hidden = !list.length;
        if (!list.length) return;
        const max = Math.max(1, ...list.map(item => item.links));
        let items = 0, links = 0, flagged = 0;
        list.forEach(item => {
            items += item.posts; links += item.links; flagged += item.flagged;
            const li = el('li');
            const button = el('button', 'cpli-hbar' + (item.batch === batch ? ' is-current' : ''));
            button.type = 'button';
            button.setAttribute('aria-label', format(text.histBar, [item.batch, item.posts, item.links, item.flagged]));
            button.title = button.getAttribute('aria-label');
            if (item.batch === batch) button.setAttribute('aria-current', 'true');
            const column = el('span', 'cpli-hbar-col');
            column.setAttribute('aria-hidden', 'true');
            column.style.height = Math.max(8, Math.round(item.links / max * 100)) + '%';
            const clean = Math.max(0, item.links - item.flagged);
            [['clean', clean]].concat(order.map(kind => [kind, item.parts[kind]])).forEach(([kind, n]) => {
                if (!n) return;
                const part = el('span', 'cpli-hbar-part is-' + kind);
                part.style.flexGrow = String(n);
                column.append(part);
            });
            if (!item.links) column.classList.add('is-empty');
            const num = el('span', 'cpli-hbar-num', item.batch);
            num.setAttribute('aria-hidden', 'true');
            button.append(column, num);
            button.addEventListener('click', () => scan(item.batch));
            li.append(button);
            historyBars.append(li);
        });
        if (endBatch) {
            const li = el('li', 'cpli-hend');
            const flag = el('span', 'dashicons dashicons-flag');
            flag.setAttribute('aria-hidden', 'true');
            li.append(flag, el('span', 'screen-reader-text', text.histEnd));
            li.title = text.histEnd;
            historyBars.append(li);
        }
        historySum.textContent = format(text.histSum, [list.length, items, links, flagged]) + (endBatch ? ' ' + text.histEnd : '');
    }

    function showSuccess(on, message) {
        success.hidden = !on;
        success.classList.remove('is-play');
        if (!on) return;
        successText.textContent = message;
        if (calm) {
            success.classList.add('is-play');
        } else {
            requestAnimationFrame(() => requestAnimationFrame(() => success.classList.add('is-play')));
        }
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
            rows = result.rows; batch = number; filter = ''; kindFilter = '';
            const parts = {check: 0, review: 0, hint: 0};
            rows.forEach(row => { if (row.position) parts[worstKind(row)]++; });
            seen.set(number, {batch: number, posts: result.posts, links: result.links, flagged: parts.check + parts.review + parts.hint, parts: parts});
            if (!result.more) endBatch = number;
            empty.hidden = true;
            report.hidden = false;
            renderTiles(result);
            renderRing(result);
            renderChart();
            renderRows();
            renderHistory();
            showSuccess(!rows.length, text.clean);
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
    start.addEventListener('click', () => { seen.clear(); endBatch = 0; scan(1); });
    next.addEventListener('click', () => scan(batch + 1));
    filterReset.addEventListener('click', () => setFilter(''));
    search.addEventListener('input', () => { query = search.value.trim().toLowerCase(); applyFilter(); });
    search.addEventListener('keydown', event => {
        if (event.key === 'Escape' && search.value) { event.preventDefault(); search.value = ''; query = ''; applyFilter(); }
    });
    // "/" jumps to the filter field; never while typing in another field.
    document.addEventListener('keydown', event => {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || tools.hidden) return;
        const t = event.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        event.preventDefault();
        search.focus();
    });
    exp.addEventListener('click', () => {
        const data = [['post_id', 'title', 'anchor_position', 'url_query_values_omitted', 'rule_codes', 'findings']];
        rows.forEach(row => data.push([row.post_id, row.title, row.position, row.url, row.codes.join('|'), row.messages.join(' ')]));
        const blob = new Blob(['﻿' + data.map(row => row.map(csv).join(',')).join('\r\n')], {type: 'text/csv;charset=utf-8'});
        const url = URL.createObjectURL(blob), a = document.createElement('a');
        a.href = url; a.download = 'campaign-links-batch-' + batch + '.csv';
        a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
}());
