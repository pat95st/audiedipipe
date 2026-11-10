/*
 * Pipeline editor: GitLab-like stage graph with drag & drop, stage palette,
 * effect rack units, workflow stage forms, presets and live preview.
 */
(function () {
    'use strict';

    var AEP = window.AEP;
    var t = AEP.t;
    var dataEl = document.getElementById('aep-editor-data');
    if (!dataEl) { return; }
    var data = JSON.parse(dataEl.textContent);
    var pipeline = data.pipeline;
    var selected = pipeline.stages.length ? 0 : -1;
    var dirty = false;
    var presets = data.presets || [];

    var ICONS = {};
    var CATEGORIES = {};
    Object.keys(data.types).forEach(function (type) {
        ICONS[type] = data.types[type].icon;
        CATEGORIES[type] = data.types[type].category;
    });
    var CATEGORY_ORDER = ['restore', 'eq', 'dynamics', 'master', 'manual', 'deliver'];

    var $ = function (selector, root) { return (root || document).querySelector(selector); };
    var markDirty = function () { dirty = true; };
    var clone = function (value) { return JSON.parse(JSON.stringify(value)); };
    var stageLabel = function (stage) { return stage.name || t('stage.' + stage.type); };
    var defaultsFor = function (type) {
        if (data.effects[type]) { return clone(data.effects[type].defaults); }
        return clone(data.workflowDefaults[type] || {});
    };
    var newKey = function () { return 's' + Date.now().toString(36) + Math.floor(Math.random() * 1000); };

    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ---------------- General settings ---------------- */
    var fillSelect = function (select, items, value, emptyLabel) {
        select.innerHTML = '';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = emptyLabel;
        select.appendChild(empty);
        items.forEach(function (item) {
            var o = document.createElement('option');
            o.value = item.id;
            o.textContent = item.name + (item.type ? ' (' + t('js.storage.' + item.type) + ')' : '');
            o.selected = String(item.id) === String(value);
            select.appendChild(o);
        });
    };
    document.querySelectorAll('[data-field]').forEach(function (input) {
        var field = input.getAttribute('data-field');
        if (field === 'source_id') {
            fillSelect(input, data.sources, pipeline.source_id, t('js.no_source'));
        } else if (field === 'metadata_template_id') {
            fillSelect(input, data.templates, pipeline.metadata_template_id, t('js.no_template'));
        } else if (input.type === 'checkbox') {
            input.checked = !!pipeline[field];
        } else {
            input.value = pipeline[field] || '';
        }
        input.addEventListener('input', function () {
            pipeline[field] = input.type === 'checkbox' ? input.checked : input.value;
            if (field === 'name') { $('[data-editor-title]').textContent = input.value || t('js.new_pipeline'); }
            markDirty();
        });
        input.addEventListener('change', function () {
            pipeline[field] = input.type === 'checkbox' ? input.checked : input.value;
            markDirty();
        });
    });

    var notifications = pipeline.notifications || { events: [], recipients: '', roles: [] };
    pipeline.notifications = notifications;
    var checkboxList = function (container, items, values, labelPrefix, onChange) {
        container.innerHTML = '';
        items.forEach(function (item) {
            var id = 'chk-' + labelPrefix.replace(/\W/g, '') + '-' + item;
            var wrap = document.createElement('div');
            wrap.className = 'form-check';
            wrap.innerHTML = '<input class="form-check-input" type="checkbox" id="' + id + '"><label class="form-check-label small" for="' + id + '"></label>';
            var input = wrap.querySelector('input');
            input.checked = values.indexOf(item) !== -1;
            wrap.querySelector('label').textContent = t(labelPrefix + item);
            input.addEventListener('change', function () {
                var index = values.indexOf(item);
                if (input.checked && index === -1) { values.push(item); }
                if (!input.checked && index !== -1) { values.splice(index, 1); }
                onChange();
            });
            container.appendChild(wrap);
        });
    };
    checkboxList($('[data-notify-events]'), data.events, notifications.events, 'js.event.', markDirty);
    checkboxList($('[data-notify-roles]'), ['admin', 'cutter', 'reviewer'], notifications.roles, 'js.role.', markDirty);
    var recipients = $('[data-notify-recipients]');
    recipients.value = notifications.recipients || '';
    recipients.addEventListener('input', function () { notifications.recipients = recipients.value; markDirty(); });

    /* ---------------- Stage palette ---------------- */
    var palette = $('[data-stage-palette]');
    CATEGORY_ORDER.forEach(function (category) {
        var types = Object.keys(data.types).filter(function (type) { return CATEGORIES[type] === category; });
        if (!types.length) { return; }
        var header = document.createElement('h6');
        header.className = 'dropdown-header aep-cat-' + category;
        header.textContent = t('js.category.' + category);
        palette.appendChild(header);
        types.forEach(function (type) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'dropdown-item aep-type-' + type;
            item.innerHTML = '<i class="bi ' + ICONS[type] + '"></i><span></span>';
            item.querySelector('span').textContent = t('stage.' + type);
            item.addEventListener('click', function () {
                var stage = { key: newKey(), type: type, name: '', enabled: true, params: defaultsFor(type) };
                var insertAt = selected >= 0 ? selected + 1 : pipeline.stages.length;
                pipeline.stages.splice(insertAt, 0, stage);
                selected = insertAt;
                markDirty();
                renderGraph();
                renderDetail();
            });
            palette.appendChild(item);
        });
    });

    /* ---------------- Stage graph ---------------- */
    var graph = $('[data-stage-graph]');
    var dragIndex = -1;
    var renderGraph = function () {
        graph.innerHTML = '';
        if (!pipeline.stages.length) {
            graph.innerHTML = '<div class="aep-empty small w-100"><i class="bi bi-plus-square-dotted"></i><p>' + AEP.escape(t('js.no_stages')) + '</p></div>';
            return;
        }
        pipeline.stages.forEach(function (stage, index) {
            var node = document.createElement('div');
            node.className = 'aep-editor-stage aep-type-' + stage.type + (index === selected ? ' active' : '') + (stage.enabled ? '' : ' disabled');
            node.draggable = true;
            node.tabIndex = 0;
            node.setAttribute('role', 'button');
            node.setAttribute('aria-pressed', index === selected ? 'true' : 'false');
            node.innerHTML =
                '<div class="d-flex justify-content-between align-items-start"><span class="aep-es-cat"></span><i class="bi aep-es-icon"></i></div>' +
                '<div class="aep-es-name"></div>' +
                '<div class="aep-es-tools">' +
                '<button type="button" data-act="left" title="' + AEP.escape(t('js.move_left')) + '"><i class="bi bi-chevron-left"></i></button>' +
                '<button type="button" data-act="right" title="' + AEP.escape(t('js.move_right')) + '"><i class="bi bi-chevron-right"></i></button>' +
                '<button type="button" data-act="toggle" title="' + AEP.escape(t('js.toggle_stage')) + '"><i class="bi ' + (stage.enabled ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>' +
                '<button type="button" data-act="copy" title="' + AEP.escape(t('js.duplicate_stage')) + '"><i class="bi bi-copy"></i></button>' +
                '<button type="button" data-act="delete" title="' + AEP.escape(t('js.delete_stage')) + '"><i class="bi bi-trash"></i></button>' +
                '</div>';
            node.querySelector('.aep-es-cat').textContent = (index + 1) + ' · ' + t('js.category.' + CATEGORIES[stage.type]);
            node.querySelector('.aep-es-icon').classList.add(ICONS[stage.type] || 'bi-gear');
            node.querySelector('.aep-es-name').textContent = stageLabel(stage);

            node.addEventListener('click', function (e) {
                var act = e.target.closest('[data-act]');
                if (act) {
                    e.stopPropagation();
                    handleAction(act.getAttribute('data-act'), index);
                    return;
                }
                selected = index;
                renderGraph();
                renderDetail();
            });
            node.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selected = index; renderGraph(); renderDetail(); }
                if (e.key === 'ArrowLeft' && e.altKey) { handleAction('left', index); }
                if (e.key === 'ArrowRight' && e.altKey) { handleAction('right', index); }
                if (e.key === 'Delete') { handleAction('delete', index); }
            });
            node.addEventListener('dragstart', function (e) {
                dragIndex = index;
                node.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(index));
            });
            node.addEventListener('dragend', function () { node.classList.remove('dragging'); dragIndex = -1; });
            node.addEventListener('dragover', function (e) {
                if (dragIndex === -1) { return; }
                e.preventDefault();
                graph.querySelectorAll('.drop-before').forEach(function (n) { n.classList.remove('drop-before'); });
                node.classList.add('drop-before');
            });
            node.addEventListener('dragleave', function () { node.classList.remove('drop-before'); });
            node.addEventListener('drop', function (e) {
                e.preventDefault();
                node.classList.remove('drop-before');
                if (dragIndex === -1 || dragIndex === index) { return; }
                move(dragIndex, dragIndex < index ? index - 1 : index);
            });
            graph.appendChild(node);
        });
        // drop zone at the end
        var end = document.createElement('div');
        end.style.minWidth = '2rem';
        end.addEventListener('dragover', function (e) { if (dragIndex !== -1) { e.preventDefault(); } });
        end.addEventListener('drop', function (e) { e.preventDefault(); if (dragIndex !== -1) { move(dragIndex, pipeline.stages.length - 1); } });
        graph.appendChild(end);
        var active = graph.querySelector('.active');
        if (active && active.scrollIntoView) { active.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
    };
    var move = function (from, to) {
        var stage = pipeline.stages.splice(from, 1)[0];
        pipeline.stages.splice(to, 0, stage);
        selected = to;
        markDirty();
        renderGraph();
        renderDetail();
    };
    var handleAction = function (action, index) {
        var stage = pipeline.stages[index];
        if (action === 'left' && index > 0) { move(index, index - 1); return; }
        if (action === 'right' && index < pipeline.stages.length - 1) { move(index, index + 1); return; }
        if (action === 'toggle') { stage.enabled = !stage.enabled; }
        if (action === 'copy') {
            var copy = clone(stage);
            copy.key = newKey();
            pipeline.stages.splice(index + 1, 0, copy);
            selected = index + 1;
        }
        if (action === 'delete') {
            if (!window.confirm(t('js.confirm_delete_stage', { name: stageLabel(stage) }))) { return; }
            pipeline.stages.splice(index, 1);
            selected = Math.min(selected, pipeline.stages.length - 1);
        }
        markDirty();
        renderGraph();
        renderDetail();
    };

    /* ---------------- Stage detail ---------------- */
    var detail = $('[data-stage-detail]');
    var formRow = function (parent, labelKey, control, cols) {
        var col = document.createElement('div');
        col.className = cols || 'col-md-6';
        var id = 'f-' + Math.random().toString(36).slice(2);
        control.id = id;
        var label = document.createElement('label');
        label.className = 'form-label small';
        label.htmlFor = id;
        label.textContent = t(labelKey);
        col.appendChild(label);
        col.appendChild(control);
        parent.appendChild(col);
        return col;
    };
    var input = function (value, onInput, type) {
        var el = document.createElement('input');
        el.className = 'form-control form-control-sm';
        el.type = type || 'text';
        el.value = value === null || value === undefined ? '' : value;
        el.addEventListener('input', function () { onInput(type === 'number' ? Number(el.value) : el.value); markDirty(); });
        return el;
    };
    var textarea = function (value, onInput, rows) {
        var el = document.createElement('textarea');
        el.className = 'form-control form-control-sm';
        el.rows = rows || 3;
        el.value = value || '';
        el.addEventListener('input', function () { onInput(el.value); markDirty(); });
        return el;
    };
    var select = function (options, value, onChange) {
        var el = document.createElement('select');
        el.className = 'form-select form-select-sm';
        options.forEach(function (opt) {
            var o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.label;
            o.selected = String(opt.value) === String(value === null || value === undefined ? '' : value);
            el.appendChild(o);
        });
        el.addEventListener('change', function () { onChange(el.value); markDirty(); });
        return el;
    };
    var switchControl = function (parent, labelKey, checked, onChange) {
        var id = 'sw-' + Math.random().toString(36).slice(2);
        var wrap = document.createElement('div');
        wrap.className = 'col-12';
        wrap.innerHTML = '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="' + id + '"><label class="form-check-label small" for="' + id + '"></label></div>';
        var el = wrap.querySelector('input');
        el.checked = !!checked;
        wrap.querySelector('label').textContent = t(labelKey);
        el.addEventListener('change', function () { onChange(el.checked); markDirty(); });
        parent.appendChild(wrap);
    };

    var workflowForms = {
        manual_edit: function (row, params) {
            formRow(row, 'js.wf.template', select(
                [{ value: '', label: t('js.wf.template_pipeline') }].concat(data.templates.map(function (tpl) { return { value: tpl.id, label: tpl.name }; })),
                params.template_id, function (v) { params.template_id = v ? Number(v) : null; }));
            formRow(row, 'js.wf.instructions', textarea(params.instructions, function (v) { params.instructions = v; }), 'col-12');
            var hint = document.createElement('div');
            hint.className = 'col-12 small text-body-secondary';
            hint.textContent = t('js.wf.manual_hint');
            row.appendChild(hint);
        },
        review: function (row, params) {
            formRow(row, 'js.wf.instructions', textarea(params.instructions, function (v) { params.instructions = v; }), 'col-12');
            switchControl(row, 'js.wf.require_comment', params.require_comment_on_reject, function (v) { params.require_comment_on_reject = v; });
            var hint = document.createElement('div');
            hint.className = 'col-12 small text-body-secondary';
            hint.textContent = t('js.wf.review_hint');
            row.appendChild(hint);
        },
        notify: function (row, params) {
            formRow(row, 'js.wf.recipients', textarea(params.recipients, function (v) { params.recipients = v; }, 2), 'col-12');
            var roles = document.createElement('div');
            roles.className = 'col-12 d-flex flex-wrap gap-3';
            params.roles = params.roles || [];
            ['admin', 'cutter', 'reviewer'].forEach(function (role) {
                var id = 'r-' + role + Math.random().toString(36).slice(2);
                var w = document.createElement('div');
                w.className = 'form-check';
                w.innerHTML = '<input class="form-check-input" type="checkbox" id="' + id + '"><label class="form-check-label small" for="' + id + '"></label>';
                var c = w.querySelector('input');
                c.checked = params.roles.indexOf(role) !== -1;
                w.querySelector('label').textContent = t('js.role.' + role);
                c.addEventListener('change', function () {
                    var i = params.roles.indexOf(role);
                    if (c.checked && i === -1) { params.roles.push(role); }
                    if (!c.checked && i !== -1) { params.roles.splice(i, 1); }
                    markDirty();
                });
                roles.appendChild(w);
            });
            row.appendChild(roles);
            formRow(row, 'js.wf.subject', input(params.subject, function (v) { params.subject = v; }), 'col-12');
            formRow(row, 'js.wf.message', textarea(params.message, function (v) { params.message = v; }, 4), 'col-12');
            switchControl(row, 'js.wf.attach_link', params.attach_link, function (v) { params.attach_link = v; });
            var hint = document.createElement('div');
            hint.className = 'col-12 small text-body-secondary';
            hint.textContent = t('js.wf.notify_placeholders');
            row.appendChild(hint);
        },
        export: function (row, params) {
            var formatLabels = { mp3: 'MP3', aac: 'AAC (M4A)', opus: 'Opus', ogg: 'Ogg Vorbis', flac: 'FLAC', wav: 'WAV' };
            formRow(row, 'js.wf.format', select(data.formats.map(function (f) { return { value: f, label: formatLabels[f] || f }; }), params.format, function (v) { params.format = v; }), 'col-md-4');
            formRow(row, 'js.wf.bitrate', select([64, 96, 128, 160, 192, 256, 320].map(function (b) { return { value: b, label: b + ' kbit/s' }; }), params.bitrate, function (v) { params.bitrate = Number(v); }), 'col-md-4');
            formRow(row, 'js.wf.sample_rate', select([{ value: 0, label: t('js.wf.original') }, { value: 44100, label: '44.1 kHz' }, { value: 48000, label: '48 kHz' }, { value: 22050, label: '22.05 kHz' }], params.sample_rate, function (v) { params.sample_rate = Number(v); }), 'col-md-4');
            formRow(row, 'js.wf.channels', select([{ value: 0, label: t('js.wf.original') }, { value: 1, label: 'Mono' }, { value: 2, label: 'Stereo' }], params.channels, function (v) { params.channels = Number(v); }), 'col-md-4');
            formRow(row, 'js.wf.filename', input(params.filename, function (v) { params.filename = v; }), 'col-md-8');
            formRow(row, 'js.wf.destination', select([{ value: '', label: t('js.wf.destination_none') }].concat(data.sources.map(function (s) { return { value: s.id, label: s.name }; })), params.destination_id, function (v) { params.destination_id = v ? Number(v) : null; }), 'col-md-6');
            formRow(row, 'js.wf.destination_path', input(params.destination_path, function (v) { params.destination_path = v; }), 'col-md-6');
            switchControl(row, 'js.wf.embed_cover', params.embed_cover, function (v) { params.embed_cover = v; });
            var hint = document.createElement('div');
            hint.className = 'col-12 small text-body-secondary';
            hint.textContent = t('js.wf.filename_hint');
            row.appendChild(hint);
        },
        publish: function (row, params) {
            params.target_ids = params.target_ids || [];
            if (!data.targets.length) {
                var empty = document.createElement('div');
                empty.className = 'col-12 small text-body-secondary';
                empty.textContent = t('js.wf.no_targets');
                row.appendChild(empty);
                return;
            }
            data.targets.forEach(function (target) {
                var id = 'pt-' + target.id;
                var w = document.createElement('div');
                w.className = 'col-md-6';
                w.innerHTML = '<div class="form-check"><input class="form-check-input" type="checkbox" id="' + id + '"><label class="form-check-label" for="' + id + '"></label></div>';
                var c = w.querySelector('input');
                c.checked = params.target_ids.indexOf(target.id) !== -1;
                w.querySelector('label').textContent = target.name + ' (' + t('js.publish.' + target.type) + ')';
                c.addEventListener('change', function () {
                    var i = params.target_ids.indexOf(target.id);
                    if (c.checked && i === -1) { params.target_ids.push(target.id); }
                    if (!c.checked && i !== -1) { params.target_ids.splice(i, 1); }
                    markDirty();
                });
                row.appendChild(w);
            });
        }
    };

    var mergePreset = function (type, presetParams) {
        var base = defaultsFor(type);
        Object.keys(presetParams || {}).forEach(function (key) {
            if (key === 'bands' && Array.isArray(presetParams.bands) && Array.isArray(base.bands)) {
                base.bands = base.bands.map(function (band, i) {
                    return Object.assign({}, band, presetParams.bands[i] || {}, presetParams.bands[i] ? { enabled: presetParams.bands[i].enabled !== false } : {});
                });
            } else {
                base[key] = presetParams[key];
            }
        });
        return base;
    };

    var renderDetail = function () {
        detail.innerHTML = '';
        var stage = pipeline.stages[selected];
        if (!stage) {
            detail.innerHTML = '<div class="aep-empty small"><i class="bi bi-hand-index"></i><p>' + AEP.escape(t('js.select_stage')) + '</p></div>';
            updatePreviewButtons();
            return;
        }
        var head = document.createElement('div');
        head.className = 'aep-stage-detail-head';
        var nameInput = document.createElement('input');
        nameInput.className = 'form-control form-control-sm';
        nameInput.placeholder = t('stage.' + stage.type);
        nameInput.value = stage.name || '';
        nameInput.setAttribute('aria-label', t('js.stage_name'));
        nameInput.addEventListener('input', function () {
            stage.name = nameInput.value;
            var node = graph.children[selected];
            if (node) { node.querySelector('.aep-es-name').textContent = stageLabel(stage); }
            markDirty();
        });
        head.appendChild(nameInput);
        var swId = 'stage-enabled';
        var sw = document.createElement('div');
        sw.className = 'form-check form-switch mb-0';
        sw.innerHTML = '<input class="form-check-input" type="checkbox" role="switch" id="' + swId + '"><label class="form-check-label small" for="' + swId + '"></label>';
        sw.querySelector('input').checked = stage.enabled;
        sw.querySelector('label').textContent = t('js.stage_enabled');
        sw.querySelector('input').addEventListener('change', function (e) { stage.enabled = e.target.checked; markDirty(); renderGraph(); });
        head.appendChild(sw);

        var isEffect = !!data.effects[stage.type];
        if (isEffect) {
            var presetWrap = document.createElement('div');
            presetWrap.className = 'd-flex gap-1 ms-auto';
            var presetSelect = document.createElement('select');
            presetSelect.className = 'form-select form-select-sm';
            presetSelect.setAttribute('aria-label', t('js.presets'));
            var refreshPresets = function () {
                presetSelect.innerHTML = '<option value="">' + AEP.escape(t('js.load_preset')) + '</option>';
                presets.filter(function (p) { return p.effect_type === stage.type; }).forEach(function (p) {
                    var o = document.createElement('option');
                    o.value = p.id;
                    o.textContent = (p.builtin ? '★ ' : '') + p.name;
                    presetSelect.appendChild(o);
                });
                var o = document.createElement('option');
                o.value = '__defaults';
                o.textContent = t('js.reset_defaults');
                presetSelect.appendChild(o);
            };
            refreshPresets();
            presetSelect.addEventListener('change', function () {
                if (presetSelect.value === '__defaults') {
                    stage.params = defaultsFor(stage.type);
                } else {
                    var preset = presets.filter(function (p) { return String(p.id) === presetSelect.value; })[0];
                    if (!preset) { return; }
                    stage.params = mergePreset(stage.type, preset.params);
                    if (!stage.name) { stage.name = preset.name; }
                }
                markDirty();
                renderGraph();
                renderDetail();
            });
            var save = document.createElement('button');
            save.type = 'button';
            save.className = 'btn btn-sm btn-outline-secondary text-nowrap';
            save.innerHTML = '<i class="bi bi-bookmark-plus"></i>';
            save.title = t('js.save_preset');
            save.setAttribute('aria-label', t('js.save_preset'));
            save.addEventListener('click', function () {
                var name = window.prompt(t('js.preset_name'), stage.name || '');
                if (!name) { return; }
                AEP.request('POST', data.urls.presets, { effect_type: stage.type, name: name, params: stage.params }).then(function (result) {
                    presets.push({ id: result.id, effect_type: stage.type, name: name, params: clone(stage.params), builtin: false });
                    refreshPresets();
                    AEP.toast(t('js.preset_saved'), 'success');
                }).catch(function (error) { AEP.toast(error.message, 'danger'); });
            });
            presetWrap.appendChild(presetSelect);
            presetWrap.appendChild(save);
            head.appendChild(presetWrap);
        }
        detail.appendChild(head);

        var body = document.createElement('div');
        detail.appendChild(body);
        if (isEffect && AEP.Effects.has(stage.type)) {
            AEP.Effects.render(body, stage.type, stage.params, data.effects[stage.type].schema, function (params) {
                stage.params = params;
                markDirty();
                schedulePreview();
            });
        } else if (workflowForms[stage.type]) {
            var row = document.createElement('div');
            row.className = 'row g-3';
            workflowForms[stage.type](row, stage.params);
            body.appendChild(row);
        }
        updatePreviewButtons();
    };

    /* ---------------- Save ---------------- */
    var saveButton = $('[data-editor-save]');
    var errorBox = $('[data-editor-error]');
    var save = function () {
        errorBox.classList.add('d-none');
        if (!pipeline.name || !pipeline.name.trim()) {
            errorBox.textContent = t('js.name_required');
            errorBox.classList.remove('d-none');
            $('[data-field="name"]').focus();
            return;
        }
        saveButton.disabled = true;
        AEP.request('POST', data.urls.save, {
            id: pipeline.id,
            name: pipeline.name,
            description: pipeline.description,
            enabled: !!pipeline.enabled,
            source_id: pipeline.source_id || null,
            metadata_template_id: pipeline.metadata_template_id || null,
            stages: pipeline.stages,
            notifications: notifications
        }).then(function (result) {
            dirty = false;
            location.href = result.redirect;
        }).catch(function (error) {
            errorBox.textContent = error.message;
            errorBox.classList.remove('d-none');
            saveButton.disabled = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    };
    saveButton.addEventListener('click', save);
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); }
    });

    /* ---------------- Preview with own test file ---------------- */
    var preview = { token: null, source: null, mode: null };
    var audio = $('[data-preview-audio]');
    var statusEl = $('[data-preview-status]');
    var fileInput = $('[data-preview-file]');
    var buttons = document.querySelectorAll('[data-preview-play]');
    var previewTimer = null;
    var setStatus = function (text, spinning) {
        statusEl.innerHTML = (spinning ? '<span class="spinner-border spinner-border-sm me-1"></span>' : '') + AEP.escape(text || '');
    };
    var effectChain = function (upToSelected) {
        var chain = [];
        pipeline.stages.forEach(function (stage, index) {
            if (data.effects[stage.type] && stage.enabled && (upToSelected ? index <= selected : index === selected)) {
                chain.push({ type: stage.type, params: stage.params, enabled: true });
            }
        });
        return chain;
    };
    var updatePreviewButtons = function () {
        var stage = pipeline.stages[selected];
        var effect = stage && data.effects[stage.type];
        buttons.forEach(function (b) {
            var mode = b.getAttribute('data-preview-play');
            b.disabled = !preview.token || (mode === 'stage' && !effect) || (mode === 'chain' && !effectChain(true).length);
        });
    };
    var play = function (mode) {
        if (!preview.token) { return; }
        preview.mode = mode;
        buttons.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-preview-play') === mode); });
        var time = audio.currentTime || 0;
        var wasPlaying = !audio.paused;
        var load = function (url) {
            audio.src = url;
            audio.addEventListener('loadedmetadata', function once() {
                audio.removeEventListener('loadedmetadata', once);
                audio.currentTime = Math.min(time, audio.duration || time);
                if (wasPlaying || mode !== 'source') { audio.play().catch(function () {}); }
            });
        };
        if (mode === 'source') { load(preview.source); setStatus(''); return; }
        setStatus(t('js.preview_rendering'), true);
        AEP.request('POST', data.urls.previewRender, { token: preview.token, chain: effectChain(mode === 'chain') }).then(function (result) {
            setStatus(t('js.preview_ready'));
            load(result.url);
        }).catch(function (error) { setStatus(error.message); });
    };
    var schedulePreview = function () {
        if (!preview.token || preview.mode === null || preview.mode === 'source') { return; }
        clearTimeout(previewTimer);
        previewTimer = setTimeout(function () { play(preview.mode); }, 900);
    };
    buttons.forEach(function (b) { b.addEventListener('click', function () { play(b.getAttribute('data-preview-play')); }); });
    fileInput.addEventListener('change', function () {
        if (!fileInput.files[0]) { return; }
        var form = new FormData();
        form.append('file', fileInput.files[0]);
        setStatus(t('js.preview_uploading'), true);
        AEP.request('POST', data.urls.previewUpload, form).then(function (result) {
            preview.token = result.token;
            preview.source = result.source;
            $('[data-preview-name]').textContent = result.name + ' (' + AEP.formatTime(result.duration) + ')';
            setStatus(t('js.preview_loaded'));
            updatePreviewButtons();
            play('source');
        }).catch(function (error) { setStatus(error.message); });
    });

    renderGraph();
    renderDetail();
})();
