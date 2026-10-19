import cytoscape from 'cytoscape';
import dagre from 'cytoscape-dagre';
import edgehandles from 'cytoscape-edgehandles';
import svg from 'cytoscape-svg';
import * as yaml from 'js-yaml';
import '../css/network-editor.css';

cytoscape.use(dagre);
cytoscape.use(edgehandles);
cytoscape.use(svg);

const clone = (value) => JSON.parse(JSON.stringify(value));
const uid = () => crypto.randomUUID();
const colors = { online: '#22c55e', offline: '#f87171', warning: '#fbbf24', unknown: '#64748b' };
const statusNames = { online: 'آنلاین', offline: 'آفلاین', warning: 'نیازمند بررسی', unknown: 'نامشخص' };
const paths = {
    server: '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6h.01M7 17h.01M12 6h5M12 17h5"/>',
    switch: '<rect x="2" y="7" width="20" height="11" rx="2"/><path d="M5 11h2m3 0h2m3 0h2M5 15h2m3 0h2m3 0h2M12 3v4"/>',
    router: '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M6 11V4m12 7V4M7 16h.01M11 16h.01M8 5a6 6 0 0 1 8 0"/>',
    firewall: '<path d="M12 2l9 4v6c0 5-5 8-9 10-4-2-9-5-9-10V6z"/><path d="M8 8h8M8 12h8m-4-4v4m-4 4h8"/>',
    isp: '<circle cx="12" cy="12" r="10"/><ellipse cx="12" cy="12" rx="4" ry="10"/><path d="M2 12h20M4 6h16M4 18h16"/>',
    computer: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 22h8m-4-5v5"/>',
    nas: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 4 18 4 18 0V5M3 12c0 4 18 4 18 0"/>',
    ap: '<path d="M3 8a15 15 0 0 1 18 0M6 12a10 10 0 0 1 12 0M9 16a5 5 0 0 1 6 0"/><circle cx="12" cy="20" r="1"/>',
    vm: '<path d="M12 2l10 6v9l-10 5L2 17V8zM2 8l10 6 10-6M12 14v8"/>',
    camera: '<rect x="2" y="6" width="15" height="13" rx="2"/><path d="M17 10l5-3v12l-5-3"/>',
};

function icon(type, color, status) {
    const aliases = { laptop: 'computer', mobile: 'computer', proxmox: 'server', docker_host: 'server', docker_container: 'vm', lxc: 'vm', soar: 'firewall', siem: 'server', edr: 'firewall', ups: 'nas', patch_panel: 'switch', pdu: 'switch' };
    const path = paths[type] || paths[aliases[type]] || paths.server;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="172" height="78" viewBox="0 0 172 78"><g transform="translate(13 26)" fill="none" stroke="' + color + '" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + path + '</g><circle cx="157" cy="12" r="3" fill="' + (colors[status] || colors.unknown) + '"/></svg>');
}

function rackImage(units, color, width, height) {
    const gap = (height - 64) / units;
    let lines = '';
    for (let i = 0; i < units; i++) {
        const y = 38 + i * gap;
        lines += '<path d="M32 ' + y + 'H' + (width - 14) + '" stroke="' + color + '" stroke-opacity=".2"/><text x="9" y="' + (y + gap * .65) + '" fill="' + color + '" font-size="9" font-family="sans-serif">' + (i + 1) + '</text>';
    }
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '">' + lines + '</svg>');
}

function rackSlot(node, rack) {
    const units = Number(rack.data.rack_units || 42);
    const start = Number(node.data.rack_u || 1), height = Number(node.data.height_u || 1);
    return {
        width: Math.max(40, rack.width - 58), height: height * 28 - 4,
        x: rack.x + 14, y: rack.y - (units * 28 + 64) / 2 + 38 + (start - 1) * 28 + height * 14,
    };
}

export default function networkEditor(initial) {
    let cy, handles, resizeObserver, timer, animationTimer, unsubscribe = [];
    let recording = true, lastState = '', sequence = 0, clipboard = null;
    return {
        graph: clone(initial), selected: null, search: '', dirty: false, saving: false, error: '', message: '',
        connecting: false, connectSource: null, history: [], future: [], items: [], edgeItems: [],
        stats: { total: 0, online: 0, offline: 0, warning: 0, unknown: 0 },
        zoomValue: 100, readOnly: false, autoSave: false, minimap: '', showHelp: false,

        init() {
            this.graph.settings = { theme: 'dark', grid: true, snap: false, minimap: true, ...this.graph.settings };
            this.$nextTick(() => this.boot());
        },

        boot() {
            cy = cytoscape({
                container: this.$refs.canvas, elements: [], layout: { name: 'preset' },
                minZoom: 0.08, maxZoom: 3.5, wheelSensitivity: 0.15,
                boxSelectionEnabled: true, selectionType: 'single',
                style: this.styles(),
            });
            this.apply(this.graph, false);
            handles = cy.edgehandles({
                canConnect: (source, target) => !this.readOnly && source.id() !== target.id() && source.data('kind') === 'device' && target.data('kind') === 'device',
                snap: true, noEdgeEventsInDraw: true,
            });
            handles.disable();
            cy.on('ehcomplete', (_event, source, target, added) => {
                added.remove(); this.edgeForm({ source: source.id(), target: target.id() });
            });
            cy.on('tap', 'node', (event) => {
                const node = event.target;
                if (node.hasClass('eh-handle')) return;
                this.selected = this.record(node);
                if (this.connecting && node.data('kind') === 'device') {
                    if (!this.connectSource) { this.connectSource = node.id(); this.message = 'حالا تجهیز مقصد را انتخاب کنید'; }
                    else if (this.connectSource !== node.id()) {
                        this.edgeForm({ source: this.connectSource, target: node.id() });
                        this.connectSource = null; this.message = '';
                    }
                }
            });
            cy.on('tap', 'edge', (event) => { if (!event.target.hasClass('eh-preview')) this.selected = this.record(event.target); });
            cy.on('tap', (event) => { if (event.target === cy) { cy.elements().unselect(); this.selected = null; this.connectSource = null; } });
            cy.on('dbltap', 'node, edge', (event) => { this.selected = this.record(event.target); this.editSelected(); });
            cy.on('dragfree', 'node', (event) => {
                const record = event.target.data('record');
                const parent = event.target.parent();
                if (record?.kind === 'device' && parent.data('kind') === 'rack') {
                    const slot = rackSlot(this.record(event.target), this.record(parent));
                    event.target.position({ x: slot.x, y: slot.y });
                    this.commit(); return;
                }
                if (this.graph.settings.snap && !event.target.isParent()) {
                    const p = event.target.position(); event.target.position({ x: Math.round(p.x / 24) * 24, y: Math.round(p.y / 24) * 24 });
                }
                this.commit();
            });
            cy.on('zoom pan', () => { this.zoomValue = Math.round(cy.zoom() * 100); });
            resizeObserver = new ResizeObserver(() => { cy.resize(); });
            resizeObserver.observe(this.$refs.canvas);
            const listen = (name, action) => {
                const listener = (event) => { if (event.detail.networkId === this.graph.network_id) action(event.detail); };
                window.addEventListener(name, listener); unsubscribe.push(() => window.removeEventListener(name, listener));
            };
            listen('network-upsert-node', ({ node }) => this.upsertNode(node));
            listen('network-upsert-edge', ({ edge }) => this.upsertEdge(edge));
            listen('network-restore', ({ graph }) => { this.apply(graph, false); this.commit(); this.message = 'نسخه انتخاب‌شده روی بوم قرار گرفت؛ برای ثبت، ذخیره کنید.'; });
            const keyboard = (event) => this.keyboard(event);
            window.addEventListener('keydown', keyboard); unsubscribe.push(() => window.removeEventListener('keydown', keyboard));
            const unload = (event) => { if (this.dirty) { event.preventDefault(); event.returnValue = ''; } };
            window.addEventListener('beforeunload', unload); unsubscribe.push(() => window.removeEventListener('beforeunload', unload));
            animationTimer = setInterval(() => {
                if (document.hidden || this.readOnly) return;
                const animated = cy.edges().filter(e => e.data('record')?.style?.animated);
                if (animated.length) animated.style('line-dash-offset', -((Date.now() / 90) % 30));
            }, 140);
            this.refresh();
        },

        destroy() {
            unsubscribe.forEach(stop => stop()); clearTimeout(timer); clearInterval(animationTimer);
            resizeObserver?.disconnect(); handles?.destroy(); cy?.destroy();
        },

        styles() {
            const light = this.graph.settings.theme === 'light';
            const matrix = this.graph.settings.theme === 'matrix';
            const text = light ? '#0f172a' : matrix ? '#86efac' : '#e2e8f0';
            return [
                { selector: 'node', style: {
                    'shape': 'roundrectangle', 'width': 'data(width)', 'height': 'data(height)',
                    'background-color': light ? '#ffffff' : '#111b2b', 'border-width': 1.3, 'border-color': 'data(color)',
                    'label': 'data(caption)', 'color': text, 'font-family': 'Tahoma', 'font-size': 12,
                    'text-valign': 'center', 'text-halign': 'center', 'text-margin-x': 14, 'text-wrap': 'wrap',
                    'text-max-width': 132, 'line-height': 1.7, 'background-image': 'data(icon)',
                    'background-fit': 'contain', 'background-clip': 'none', 'background-image-opacity': 1,
                    'overlay-opacity': 0, 'padding': 0,
                } },
                { selector: 'node[kind = "zone"], node[kind = "rack"]', style: {
                    'shape': 'roundrectangle', 'background-color': 'data(color)', 'background-opacity': 0.045,
                    'border-width': 1, 'border-style': 'dashed', 'label': 'data(label)',
                    'text-valign': 'top', 'text-halign': 'left', 'text-margin-x': 14, 'text-margin-y': 24,
                    'text-wrap': 'none', 'font-size': 'data(fontSize)', 'font-weight': 600,
                    'color': 'data(color)', 'background-image': 'none', 'padding': 35,
                    'min-width': 'data(width)', 'min-height': 'data(height)', 'text-max-width': 1200,
                    'compound-sizing-wrt-labels': 'exclude', 'z-compound-depth': 'bottom',
                } },
                { selector: 'node[kind = "rack"]', style: { 'border-style': 'solid', 'border-width': 3, 'background-opacity': 0.08, 'background-image': 'data(rackImage)', 'background-fit': 'contain', 'padding': 0, 'text-halign': 'center', 'text-margin-x': 0, 'text-margin-y': 26 } },
                { selector: '.rack-anchor', style: { 'width': 1, 'height': 1, 'opacity': 0, 'events': 'no', 'label': '', 'border-width': 0, 'background-image': 'none', 'padding': 0 } },
                { selector: 'node[mountedInRack = 1]', style: { 'font-size': 9, 'text-margin-x': 0, 'text-max-width': 220, 'background-image': 'none' } },
                { selector: 'node[kind = "device"]:parent', style: { 'padding': 35, 'min-width': 220, 'min-height': 140, 'background-image': 'none', 'label': 'data(label)', 'text-valign': 'top', 'text-margin-y': 22, 'text-margin-x': 0 } },
                { selector: 'node[kind = "text"]', style: {
                    'background-opacity': 0, 'background-image': 'none', 'border-width': 0,
                    'color': 'data(color)', 'font-size': 'data(fontSize)', 'label': 'data(label)',
                    'text-max-width': 600, 'text-margin-x': 0,
                } },
                { selector: 'edge', style: {
                    'curve-style': 'bezier', 'width': 'data(lineWidth)', 'line-color': 'data(color)',
                    'line-style': 'data(lineStyle)', 'target-arrow-shape': 'data(arrow)', 'target-arrow-color': 'data(color)',
                    'arrow-scale': 0.8, 'label': 'data(label)', 'color': light ? '#475569' : '#94a3b8',
                    'font-family': 'Tahoma', 'font-size': 9, 'text-background-color': light ? '#f8fafc' : '#0b1220',
                    'text-background-opacity': 1, 'text-background-padding': 5, 'text-background-shape': 'roundrectangle',
                    'text-rotation': 'none', 'text-margin-y': -8,
                    'source-label': 'data(sourcePort)', 'target-label': 'data(targetPort)',
                    'source-text-offset': 'data(portOffset)', 'target-text-offset': 'data(portOffset)',
                    'source-text-margin-y': 'data(sourcePortMargin)', 'target-text-margin-y': 'data(targetPortMargin)',
                    'opacity': 0.85,
                } },
                { selector: ':selected', style: { 'border-width': 3, 'border-color': '#22d3ee', 'overlay-color': '#22d3ee', 'overlay-opacity': 0.09, 'overlay-padding': 7 } },
                { selector: 'edge:selected', style: { 'line-color': '#22d3ee', 'width': 3 } },
                { selector: '.dimmed', style: { 'opacity': 0.16 } },
                { selector: '.eh-handle', style: { 'background-color': '#22d3ee', 'width': 12, 'height': 12, 'shape': 'ellipse', 'border-width': 2, 'border-color': '#0b1220', 'background-image': 'none', 'label': '', 'overlay-opacity': 0 } },
                { selector: '.eh-preview, .eh-ghost-edge', style: { 'line-color': '#22d3ee', 'width': 2, 'line-style': 'dashed' } },
                { selector: '.collapsed-child', style: { 'display': 'none' } },
            ];
        },

        element(node) {
            const color = /^#[0-9a-f]{6}$/i.test(node.style?.color) ? node.style.color : '#38bdf8';
            const inRack = node.kind === 'device' && node.parent && (cy.getElementById(node.parent).data('kind') === 'rack' || this.graph.nodes.some(n => n.id === node.parent && n.kind === 'rack'));
            const caption = node.kind === 'device' && !inRack ? node.label + (node.data.ip ? '\n' + node.data.ip : '') : node.label;
            const data = {
                id: node.id, kind: node.kind, label: node.label, caption, color, width: node.width, height: node.height,
                mountedInRack: inRack ? 1 : 0,
                fontSize: node.style.font_size || 14, icon: node.kind === 'device' ? icon(node.type, color, node.data.status) : '', record: clone(node),
                rackImage: node.kind === 'rack' ? rackImage(node.data.rack_units || 42, color, node.width, node.height) : '',
            };
            if (node.parent) data.parent = node.parent;
            return { group: 'nodes', data, position: { x: node.x, y: node.y } };
        },

        edgeElement(edge) {
            const label = [edge.label, edge.data.vlan ? 'VLAN ' + edge.data.vlan : '', edge.data.speed].filter(Boolean).join(' · ');
            const position = id => {
                const rendered = cy.getElementById(id);
                return rendered.length ? rendered.position() : this.graph.nodes.find(n => n.id === id);
            };
            const from = position(edge.source), to = position(edge.target);
            const vertical = Math.abs((from?.x || 0) - (to?.x || 0)) < 30;
            const diagonal = !vertical && Math.abs((from?.y || 0) - (to?.y || 0)) >= 30;
            return { group: 'edges', data: {
                id: edge.id, source: edge.source, target: edge.target, label,
                color: edge.style.color || ({ wifi: '#a78bfa', fibre: '#fbbf24', virtual: '#22d3ee' }[edge.type]) || '#64748b',
                lineWidth: edge.style.width || 2, lineStyle: edge.style.animated ? 'dashed' : edge.style.line || 'solid',
                arrow: edge.style.arrow ? 'triangle' : 'none', sourcePort: edge.data.source_port || '', targetPort: edge.data.target_port || '', record: clone(edge),
                portOffset: vertical ? 14 : diagonal ? 60 : 32,
                sourcePortMargin: vertical ? 0 : diagonal ? -10 : 10,
                targetPortMargin: vertical ? 0 : 10,
            } };
        },

        apply(graph, fit = false) {
            recording = false;
            this.graph = clone(graph);
            this.graph.settings = { theme: 'dark', grid: true, snap: false, minimap: true, ...graph.settings };
            this.graph.nodes.filter(n => n.kind === 'rack').forEach(rack => {
                rack.height = (rack.data.rack_units || 42) * 28 + 64;
                this.graph.nodes.filter(n => n.parent === rack.id && n.kind === 'device').forEach(n => Object.assign(n, rackSlot(n, rack)));
            });
            cy.elements().remove();
            // Parents precede children in the renderer, while persisted positions stay absolute.
            const pending = [...this.graph.nodes], ordered = [], done = new Set();
            while (pending.length) {
                const index = pending.findIndex(n => !n.parent || done.has(n.parent));
                if (index < 0) throw new Error('گروه‌بندی نامعتبر است.');
                const node = pending.splice(index, 1)[0]; done.add(node.id); ordered.push(this.element(node));
            }
            cy.add(ordered);
            this.graph.nodes.filter(n => n.kind === 'rack').forEach(rack => this.anchorRack(rack));
            cy.add(this.graph.edges.map(edge => this.edgeElement(edge)));
            cy.style(this.styles()); this.applyCollapsed();
            if (!fit && graph.viewport?.zoom) cy.viewport(graph.viewport);
            else cy.fit(undefined, 65);
            this.selected = null; recording = true; this.refresh();
            if (!lastState) lastState = JSON.stringify(this.snapshot(false));
        },

        record(element) {
            const record = clone(element.data('record'));
            if (element.isNode()) { record.x = element.position('x'); record.y = element.position('y'); record.parent = element.data('parent') || null; }
            return record;
        },

        anchorRack(rack) {
            // Compound parents otherwise center themselves around their children, moving U3 to the middle.
            for (const [corner, direction] of [['top', -1], ['bottom', 1]]) {
                const id = 'rack-anchor-' + rack.id + '-' + corner;
                const position = { x: rack.x + direction * (rack.width / 2 - 1.5), y: rack.y + direction * (rack.height / 2 - 1.5) };
                const existing = cy.getElementById(id);
                if (existing.length) existing.position(position);
                else cy.add({ group: 'nodes', data: { id, parent: rack.id, width: 1, height: 1, caption: '', color: '#64748b', icon: '' }, position, classes: 'rack-anchor', selectable: false, grabbable: false });
            }
        },

        snapshot(viewport = true) {
            return { ...clone(this.graph),
                nodes: cy.nodes().filter(n => !n.hasClass('eh-handle') && n.data('record')).map(n => this.record(n)),
                edges: cy.edges().filter(e => e.data('record')).map(e => this.record(e)),
                viewport: viewport ? { zoom: cy.zoom(), pan: cy.pan() } : {},
            };
        },

        commit() {
            if (!recording) return;
            const next = JSON.stringify(this.snapshot(false));
            if (next === lastState) return;
            this.history.push(lastState); if (this.history.length > 60) this.history.shift();
            this.future = []; lastState = next; this.markDirty(); this.refresh();
        },

        markDirty() {
            this.dirty = true; this.error = ''; sequence++;
            clearTimeout(timer);
            if (this.autoSave) timer = setTimeout(() => this.save(), 5000);
        },

        refresh() {
            this.items = cy.nodes().filter(n => n.data('record')).map(n => this.record(n));
            this.edgeItems = cy.edges().filter(e => e.data('record')).map(e => this.record(e));
            const devices = this.items.filter(n => n.kind === 'device');
            this.stats = { total: devices.length, online: 0, offline: 0, warning: 0, unknown: 0 };
            devices.forEach(n => this.stats[n.data.status || 'unknown']++);
            this.zoomValue = Math.round(cy.zoom() * 100);
            this.updateMinimap();
        },

        get filteredItems() {
            const query = this.search.toLowerCase();
            return this.items.filter(n => !query || [n.label, n.type, n.data.ip, n.data.hostname, ...(n.data.tags || [])].join(' ').toLowerCase().includes(query));
        },

        center() { const extent = cy.extent(); return { x: (extent.x1 + extent.x2) / 2, y: (extent.y1 + extent.y2) / 2 }; },
        parents(except = null) {
            return Object.fromEntries(this.items.filter(n => n.id !== except && (['zone', 'rack'].includes(n.kind) || ['proxmox', 'docker_host', 'vm'].includes(n.type))).map(n => [n.id, n.label]));
        },
        async openAction(name, arguments_ = {}) {
            if (document.fullscreenElement) await document.exitFullscreen();
            return this.$wire.mountAction(name, arguments_);
        },
        form(kind, node = null, type = null) {
            if (this.readOnly) return;
            this.openAction(kind, { node: node ? clone(node) : {}, type, parents: this.parents(node?.id) });
        },
        addEquipment() {
            if (!this.readOnly) this.openAction('existingEquipment');
        },
        edgeForm(edge) {
            if (this.readOnly) return;
            this.openAction('edge', { edge: clone(edge) });
        },
        editSelected() {
            if (!this.selected || this.readOnly) return;
            if (this.selected.source) this.edgeForm(this.selected);
            else this.form(this.selected.kind, this.selected);
        },

        upsertNode(patch) {
            if (this.readOnly) return;
            const placed = patch.equipment_id && this.items.find(n => n.equipment_id === patch.equipment_id && n.id !== patch.id);
            if (placed) {
                this.focus(placed.id); this.message = 'این تجهیز قبلاً روی همین بوم قرار گرفته است.';
                return;
            }
            const exists = patch.id && cy.getElementById(patch.id).length;
            const old = exists ? this.record(cy.getElementById(patch.id)) : {};
            const position = this.center();
            const kind = patch.kind || 'device';
            const node = { id: uid(), kind, type: kind === 'device' ? 'server' : kind,
                x: position.x, y: position.y, width: kind === 'device' ? 172 : 400, height: kind === 'device' ? 78 : 300,
                parent: null, data: {}, style: {}, ...old, ...clone(patch),
            };
            node.x = Number(node.x); node.y = Number(node.y); node.width = Number(node.width); node.height = Number(node.height);
            node.parent = node.parent || null; node.data = node.data || {}; node.style = node.style || {};
            if (node.parent === node.id || (node.parent && cy.getElementById(node.parent).ancestors().some(n => n.id() === node.id))) {
                this.error = 'این گروه‌بندی چرخه ایجاد می‌کند و قابل اعمال نیست.'; return;
            }
            if (node.kind === 'device') {
                node.data.status ||= 'unknown';
                for (const key of ['rack_u', 'height_u', 'ports']) if (node.data[key] != null) node.data[key] = Number(node.data[key]);
                node.data.services?.forEach(s => { if (s.port != null) s.port = Number(s.port); });
            }
            if (node.kind === 'rack') { node.data.rack_units = Number(node.data.rack_units || 42); node.height = node.data.rack_units * 28 + 64; }
            if (node.style.font_size) node.style.font_size = Number(node.style.font_size);
            if (node.parent && cy.getElementById(node.parent).data('kind') === 'rack') {
                const rack = this.record(cy.getElementById(node.parent));
                const units = rack.data.rack_units || 42;
                const u = node.data.rack_u || 1, h = node.data.height_u || 1;
                if (u + h - 1 > units || this.items.some(n => n.id !== node.id && n.parent === rack.id && u < (n.data.rack_u || 1) + (n.data.height_u || 1) && (n.data.rack_u || 1) < u + h)) {
                    this.error = 'یونیت انتخاب‌شده اشغال است یا از ارتفاع رک خارج می‌شود.'; return;
                }
                Object.assign(node, rackSlot(node, rack));
            }
            if (exists) {
                const ele = cy.getElementById(node.id);
                ele.move({ parent: node.parent }); ele.data(this.element(node).data); ele.position({ x: node.x, y: node.y });
            } else cy.add(this.element(node));
            if (node.kind === 'rack') {
                this.anchorRack(node);
                cy.nodes().filter(n => n.data('record')?.kind === 'device' && n.data('parent') === node.id).forEach(n => {
                    const child = { ...this.record(n), ...rackSlot(this.record(n), node) };
                    n.data(this.element(child).data); n.position({ x: child.x, y: child.y });
                });
            }
            this.selected = node; this.commit();
        },

        upsertEdge(patch) {
            if (this.readOnly) return;
            const edge = { id: uid(), type: 'ethernet', label: '', data: {}, style: {}, ...clone(patch) };
            if (!cy.getElementById(edge.source).length || !cy.getElementById(edge.target).length || edge.source === edge.target) return;
            edge.data ||= {}; edge.style ||= {};
            if (edge.data.vlan) edge.data.vlan = Number(edge.data.vlan);
            if (edge.style.width) edge.style.width = Number(edge.style.width);
            if (cy.getElementById(edge.id).length) cy.getElementById(edge.id).data(this.edgeElement(edge).data);
            else cy.add(this.edgeElement(edge));
            this.selected = edge; this.commit();
        },

        focus(id) {
            const element = cy.getElementById(id);
            cy.elements().unselect(); element.select(); cy.animate({ center: { eles: element }, duration: 180 });
            this.selected = this.record(element);
            if (this.connecting && element.isNode() && element.data('kind') === 'device') {
                if (!this.connectSource) { this.connectSource = id; this.message = 'حالا تجهیز مقصد را انتخاب کنید'; }
                else if (this.connectSource !== id) { this.edgeForm({ source: this.connectSource, target: id }); this.connectSource = null; this.message = ''; }
            }
        },
        highlight() {
            cy.elements().removeClass('dimmed');
            if (this.search) { const ids = new Set(this.filteredItems.map(n => n.id)); cy.nodes().filter(n => !ids.has(n.id())).addClass('dimmed'); }
        },
        removeSelected() {
            if (this.readOnly || !this.selected) return;
            const selected = cy.$(':selected');
            const target = selected.length ? selected : cy.getElementById(this.selected.id);
            if (!window.confirm('موارد انتخاب‌شده و ارتباط‌های وابسته حذف شوند؟')) return;
            target.remove(); this.selected = null; this.commit();
        },
        clear() {
            if (this.readOnly || !window.confirm('تمام اجزای این نقشه از بوم حذف شوند؟ تا زمان ذخیره، نسخه سرور تغییر نمی‌کند.')) return;
            cy.elements().remove(); this.selected = null; this.commit();
        },
        undo() {
            if (this.readOnly || !this.history.length) return;
            this.future.push(JSON.stringify(this.snapshot(false)));
            const previous = this.history.pop(); this.apply(JSON.parse(previous), true); lastState = previous; this.markDirty();
        },
        redo() {
            if (this.readOnly || !this.future.length) return;
            this.history.push(JSON.stringify(this.snapshot(false)));
            const next = this.future.pop(); this.apply(JSON.parse(next), true); lastState = next; this.markDirty();
        },
        copySelection() {
            let nodes = cy.nodes(':selected');
            if (!nodes.length && this.selected && !this.selected.source) nodes = cy.getElementById(this.selected.id);
            nodes = nodes.union(nodes.descendants());
            nodes = nodes.filter(n => n.data('record'));
            const ids = new Set(nodes.map(n => n.id()));
            clipboard = { nodes: nodes.map(n => this.record(n)), edges: cy.edges().filter(e => ids.has(e.source().id()) && ids.has(e.target().id())).map(e => this.record(e)) };
            this.message = clipboard.nodes.length + ' مورد کپی شد';
        },
        paste() {
            if (this.readOnly || !clipboard?.nodes.length) return;
            const ids = Object.fromEntries(clipboard.nodes.map(n => [n.id, uid()]));
            const graph = this.snapshot();
            graph.nodes.push(...clipboard.nodes.map(n => ({ ...clone(n), id: ids[n.id], equipment_id: null, equipment_revision: null, parent: ids[n.parent] || null, x: n.x + 45, y: n.y + 45, label: n.label + ' (کپی)' })));
            graph.edges.push(...clipboard.edges.map(e => ({ ...clone(e), id: uid(), source: ids[e.source], target: ids[e.target] })));
            this.apply(graph); this.commit();
        },
        duplicate() { this.copySelection(); this.paste(); },
        toggleConnect() {
            if (this.readOnly) return;
            this.connecting = !this.connecting; this.connectSource = null;
            if (this.connecting) { handles.enable(); handles.enableDrawMode(); this.message = 'از مبدا به مقصد بکشید یا دو تجهیز را به ترتیب انتخاب کنید'; }
            else { handles.disableDrawMode(); handles.disable(); this.message = ''; }
        },
        toggleReadOnly() {
            if (this.connecting) this.toggleConnect();
            this.readOnly = !this.readOnly;
            if (this.readOnly) cy.autoungrabify(true); else cy.autoungrabify(false);
        },
        applyCollapsed() {
            cy.nodes().removeClass('collapsed-child');
            cy.nodes().filter(n => n.data('record')?.style?.collapsed).forEach(n => n.descendants().addClass('collapsed-child'));
        },
        collapse() {
            if (!this.selected || this.readOnly) return;
            const ele = cy.getElementById(this.selected.id);
            if (!ele.isParent()) return;
            const node = this.record(ele); node.style.collapsed = !node.style.collapsed;
            ele.data('record', node); this.applyCollapsed(); this.selected = node; this.commit();
        },
        layout(mode = 'TB') {
            if (this.readOnly || !cy.nodes().length) return;
            const layout = mode === 'grid' ? { name: 'grid', avoidOverlap: true, condense: false, spacingFactor: 1.7 }
                : { name: 'dagre', rankDir: mode, nodeSep: 70, rankSep: 100, spacingFactor: 1.2 };
            cy.layout({ ...layout, animate: false, fit: true, padding: 60 }).run();
            cy.nodes().filter(n => n.data('record')?.kind === 'rack').map(n => this.record(n)).forEach(rack => {
                this.anchorRack(rack);
                cy.nodes().filter(n => n.data('record')?.kind === 'device' && n.data('parent') === rack.id).forEach(n => {
                    const child = { ...this.record(n), ...rackSlot(this.record(n), rack) };
                    n.data(this.element(child).data); n.position({ x: child.x, y: child.y });
                });
            });
            cy.fit(undefined, 60); this.commit();
        },
        align(axis) {
            if (this.readOnly) return;
            const nodes = cy.nodes(':selected').filter(n => !n.isParent()); if (nodes.length < 2) return;
            const value = nodes.reduce((sum, n) => sum + n.position(axis), 0) / nodes.length;
            nodes.forEach(n => n.position(axis, value)); this.commit();
        },
        setTheme(theme) { this.graph.settings.theme = theme; cy.style(this.styles()); this.commit(); },
        settingsChanged() { this.commit(); },
        zoomBy(factor) { cy.zoom({ level: Math.max(0.08, Math.min(3.5, cy.zoom() * factor)), renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } }); },
        fit() { cy.fit(undefined, 55); this.updateMinimap(); },
        fullscreen() {
            if (document.fullscreenElement) document.exitFullscreen(); else this.$root.requestFullscreen();
        },
        updateMinimap() {
            if (this.graph.settings.minimap && cy.nodes().length) {
                try { this.minimap = cy.png({ full: true, maxWidth: 180, maxHeight: 100, bg: this.graph.settings.theme === 'light' ? '#f8fafc' : '#0b1220' }); } catch { this.minimap = ''; }
            } else this.minimap = '';
        },
        async save() {
            if (this.saving || this.readOnly) return;
            const atStart = sequence;
            this.saving = true; this.error = ''; this.message = '';
            try {
                const result = await this.$wire.saveMap(this.snapshot());
                if (!result.ok) { this.error = result.error; return; }
                this.graph.revision = result.graph.revision;
                // Keep edits made during save while carrying inventory identity and conflict versions forward.
                const savedNodes = Object.fromEntries(result.graph.nodes.map(n => [n.id, n]));
                const syncIdentity = node => {
                    const saved = savedNodes[node.id];
                    return saved ? { ...node, equipment_id: saved.equipment_id, equipment_revision: saved.equipment_revision } : node;
                };
                cy.nodes().filter(n => n.data('record')).forEach(n => n.data('record', syncIdentity(this.record(n))));
                if (this.selected && !this.selected.source) this.selected = syncIdentity(this.selected);
                this.refresh();
                this.dirty = sequence !== atStart;
                this.message = this.dirty ? 'نسخه قبلی ذخیره شد؛ تغییرات جدید هنوز ذخیره نشده‌اند.' : 'نقشه ذخیره شد · نسخه ' + this.graph.revision;
                // Undo entries must always save against the latest server revision.
                const syncHistory = s => { const g = JSON.parse(s); g.revision = this.graph.revision; g.nodes = g.nodes.map(syncIdentity); return JSON.stringify(g); };
                this.history = this.history.map(syncHistory);
                this.future = this.future.map(syncHistory);
                lastState = JSON.stringify(this.snapshot(false));
            } catch { this.error = 'ذخیره انجام نشد. تغییرات روی بوم باقی مانده‌اند؛ اتصال به همین پروژه را بررسی کنید.'; }
            finally { this.saving = false; }
        },
        async reload() {
            if (this.dirty && !window.confirm('تغییرات ذخیره‌نشده کنار گذاشته شوند و نسخه سرور باز شود؟')) return;
            try { this.apply(await this.$wire.reloadMap(), true); this.history = []; this.future = []; this.dirty = false; this.error = ''; lastState = JSON.stringify(this.snapshot(false)); }
            catch { this.error = 'نسخه سرور دریافت نشد.'; }
        },
        statusLabel(status) { return statusNames[status] || 'نامشخص'; },
        statusColor(status) { return colors[status] || colors.unknown; },
        download(content, name, type) {
            const url = URL.createObjectURL(new Blob([content], { type }));
            const a = document.createElement('a'); a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 2000);
        },
        exportFile(format) {
            const graph = this.snapshot();
            const name = 'network-' + this.graph.network_id;
            if (format === 'json') this.download(JSON.stringify(graph, null, 2), name + '.json', 'application/json');
            if (format === 'yaml') this.download(yaml.dump(graph, { noRefs: true }), name + '.yaml', 'text/yaml');
            if (format === 'png') {
                const a = document.createElement('a'); a.href = cy.png({ full: true, scale: 2, bg: this.graph.settings.theme === 'light' ? '#f8fafc' : '#0b1220' }); a.download = name + '.png'; a.click();
            }
            if (format === 'svg') this.download(cy.svg({ full: true, scale: 1, bg: '#0b1220' }), name + '.svg', 'image/svg+xml');
            if (format === 'markdown') {
                const escape = v => String(v || '').replaceAll('|', '\\|').replaceAll('\n', ' ');
                let md = '# ' + graph.name + '\n\n| تجهیز | نوع | IP | وضعیت |\n|---|---|---|---|\n';
                graph.nodes.filter(n => n.kind === 'device').forEach(n => { md += '| ' + [n.label, n.type, n.data.ip, this.statusLabel(n.data.status)].map(escape).join(' | ') + ' |\n'; });
                md += '\n## ارتباط‌ها\n\n'; const labels = Object.fromEntries(graph.nodes.map(n => [n.id, n.label]));
                graph.edges.forEach(e => { md += '- ' + escape(labels[e.source]) + ' → ' + escape(labels[e.target]) + ' (' + escape(e.type) + ')\n'; });
                this.download(md, name + '.md', 'text/markdown');
            }
        },
        async importFile(event) {
            const file = event.target.files[0]; event.target.value = '';
            if (!file || this.readOnly) return;
            try {
                if (file.size > 3_000_000) throw new Error('حداکثر اندازه فایل ۳ مگابایت است.');
                const text = await file.text();
                const value = file.name.toLowerCase().endsWith('.json') ? JSON.parse(text) : yaml.load(text, { schema: yaml.JSON_SCHEMA });
                if (!value || value.format !== 'filament-network' || value.version !== 1 || !Array.isArray(value.nodes) || !Array.isArray(value.edges)) throw new Error('فایل باید خروجی JSON یا YAML همین ادیتور باشد.');
                const ids = Object.fromEntries(value.nodes.map(n => [n.id, uid()]));
                if (Object.keys(ids).length !== value.nodes.length || value.nodes.length > 1000 || value.edges.length > 3000) throw new Error('تعداد یا شناسه اجزای فایل نامعتبر است.');
                value.nodes = value.nodes.map(n => ({ ...n, id: ids[n.id], equipment_id: null, equipment_revision: null, parent: n.parent ? ids[n.parent] : null }));
                value.edges = value.edges.map(e => ({ ...e, id: uid(), source: ids[e.source], target: ids[e.target] }));
                const graph = { ...value, network_id: this.graph.network_id, name: this.graph.name, revision: this.graph.revision, viewport: {} };
                const validation = await this.$wire.validateImport(graph);
                if (!validation.ok) throw new Error(validation.error);
                if (!window.confirm('فایل شامل ' + graph.nodes.length + ' جزء و ' + graph.edges.length + ' ارتباط است. جایگزین بوم فعلی شود؟')) return;
                this.apply(graph, true); this.commit(); this.message = 'فایل روی بوم وارد شد؛ برای ثبت در دیتابیس ذخیره کنید.';
            } catch (error) { this.error = error.message || 'فایل قابل خواندن نیست.'; }
        },
        keyboard(event) {
            const typing = /input|textarea|select/i.test(event.target.tagName) || event.target.isContentEditable;
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); this.save(); return; }
            if (typing || Array.from(document.querySelectorAll('[role="dialog"]')).some(el => el.getClientRects().length)) return;
            if (event.key === 'Escape') { if (this.connecting) this.toggleConnect(); this.selected = null; cy.elements().unselect(); }
            if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); this.removeSelected(); }
            if (event.ctrlKey || event.metaKey) {
                const key = event.key.toLowerCase();
                if (key === 'z') { event.preventDefault(); event.shiftKey ? this.redo() : this.undo(); }
                if (key === 'y') { event.preventDefault(); this.redo(); }
                if (key === 'c') { event.preventDefault(); this.copySelection(); }
                if (key === 'v') { event.preventDefault(); this.paste(); }
                if (key === 'd') { event.preventDefault(); this.duplicate(); }
            }
        },
    };
}
