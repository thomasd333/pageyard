/* Pageyard administrator: TinyMCE editors and image fields. Config in window.PY_EDITOR. */
(function () {
    'use strict';

    var cfg = window.PY_EDITOR;
    if (!cfg) {
        return;
    }

    // Uploads a file through task=media.upload; resolves with {path, url}.
    function upload(file, folder) {
        var data = new FormData();
        data.append('task', 'media.upload');
        data.append('token', cfg.token);
        data.append('folder', folder || 'pages');
        data.append('file', file, file.name || 'image.png');
        return fetch(cfg.uploadUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (json) {
                    if (!response.ok || !json.path) {
                        throw new Error(json.error || cfg.strings.uploadFailed);
                    }
                    return json;
                });
            });
    }

    // Media library in a modal for image fields; the picker page answers with postMessage.
    var pickerModal = null;
    var pickerTarget = null;
    // The picker opens in the folder of the current image, if there is one.
    function pickerUrl(current) {
        var match = /^(images\/[A-Za-z0-9_.\/-]+)\/[^\/]+$/.exec(current || '');
        return cfg.picker + (match ? '&folder=' + encodeURIComponent(match[1]) : '');
    }

    function openLibrary(onPick, current) {
        if (!pickerModal) {
            var el = document.createElement('div');
            el.className = 'modal fade';
            el.tabIndex = -1;
            el.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">'
                + '<div class="modal-header"><h2 class="modal-title fs-5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
                + '<div class="modal-body p-0"><iframe title="" style="display:block;width:100%;height:72vh;border:0"></iframe></div></div></div>';
            el.querySelector('.modal-title').textContent = cfg.strings.libraryTitle;
            el.querySelector('.btn-close').setAttribute('aria-label', cfg.strings.close);
            el.querySelector('iframe').title = cfg.strings.libraryTitle;
            document.body.appendChild(el);
            pickerModal = new window.bootstrap.Modal(el);
            el.addEventListener('hidden.bs.modal', function () { pickerTarget = null; });
            window.addEventListener('message', function (event) {
                var data = event.origin === location.origin && event.data && event.data.data;
                if (data && data.pyMediaPath && pickerTarget) {
                    pickerTarget(data.pyMediaPath);
                    pickerModal.hide();
                }
            });
        }
        pickerTarget = onPick;
        pickerModal._element.querySelector('iframe').src = pickerUrl(current);
        pickerModal.show();
    }

    // Thumbnail / image fields.
    document.querySelectorAll('[data-image-field]').forEach(function (box) {
        var path = box.querySelector('[data-image-path]');
        var file = box.querySelector('[data-image-file]');
        var remove = box.querySelector('[data-image-remove]');
        var preview = box.querySelector('.py-image-preview');
        var img = preview.querySelector('img');
        var error = box.querySelector('[data-image-error]');

        function show(value) {
            path.value = value;
            img.src = value ? cfg.siteRoot + value : '';
            preview.classList.toggle('d-none', !value);
            remove.classList.toggle('d-none', !value);
        }
        function fail(message) {
            error.textContent = message;
            error.classList.toggle('d-none', !message);
        }

        file.addEventListener('change', function () {
            if (!file.files.length) {
                return;
            }
            fail('');
            box.classList.add('py-busy');
            upload(file.files[0], box.getAttribute('data-folder'))
                .then(function (json) { show(json.path); })
                .catch(function (e) { fail(e.message); })
                .finally(function () { box.classList.remove('py-busy'); file.value = ''; });
        });
        remove.addEventListener('click', function () { show(''); });
        // "From the library" next to the upload button.
        var browse = document.createElement('button');
        browse.type = 'button';
        browse.className = 'btn btn-sm btn-outline-secondary text-nowrap';
        remove.parentNode.classList.add('flex-wrap');
        browse.innerHTML = '<i class="bi bi-images me-1" aria-hidden="true"></i>';
        browse.appendChild(document.createTextNode(cfg.strings.library));
        remove.parentNode.insertBefore(browse, remove);
        browse.addEventListener('click', function () { openLibrary(function (value) { fail(''); show(value); }, path.value.trim()); });
        path.addEventListener('change', function () { show(path.value.trim()); });
    });

    // "Insert script": {script path params} for a file from scripts/ (list from Scripts::available()).
    function scriptDialog(editor) {
        var scripts = cfg.scripts || [];
        var examples = {};
        var items = scripts.map(function (s) {
            examples[s.path] = s.example;
            return { value: s.path, text: s.description ? s.path + ' – ' + s.description : s.path };
        });
        var body = items.length
            ? [
                { type: 'listbox', name: 'script', label: cfg.strings.scriptFile, items: items },
                { type: 'input', name: 'params', label: cfg.strings.scriptParams },
                { type: 'htmlpanel', html: '<p style="opacity:.75">' + editor.dom.encode(cfg.strings.scriptHint) + '</p>' }
            ]
            : [{ type: 'htmlpanel', html: '<p>' + editor.dom.encode(cfg.strings.scriptNone) + '</p>' }];
        editor.windowManager.open({
            title: cfg.strings.script,
            body: { type: 'panel', items: body },
            initialData: items.length ? { script: items[0].value, params: examples[items[0].value] || '' } : {},
            buttons: [
                { type: 'cancel', text: 'Cancel' },
                { type: 'submit', text: 'Insert', primary: true, enabled: items.length > 0 }
            ],
            onChange: function (api, details) {
                if (details.name === 'script') {
                    api.setData({ params: examples[api.getData().script] || '' });
                }
            },
            onSubmit: function (api) {
                var data = api.getData();
                var params = (data.params || '').trim();
                editor.insertContent(editor.dom.encode('{script ' + data.script + (params ? ' ' + params : '') + '}'));
                api.close();
            }
        });
    }

    // "Gallery": puts a {script built-in/gallery ...} tag in its own paragraph.
    function galleryDialog(editor) {
        var s = cfg.strings;
        var folders = (cfg.galleries || []).map(function (f) {
            return { value: f.path, text: f.path + ' (' + f.images + ')' };
        });
        var columns = ['2', '3', '4', '5', '6'].map(function (n) { return { value: n, text: n }; });
        var body = folders.length
            ? [
                { type: 'listbox', name: 'folder', label: s.galleryFolder, items: folders },
                { type: 'listbox', name: 'columns', label: s.galleryColumns, items: columns },
                { type: 'listbox', name: 'order', label: s.galleryOrder, items: [
                    { value: 'name', text: s.galleryByName },
                    { value: 'newest', text: s.galleryNewest }
                ] },
                { type: 'input', name: 'limit', label: s.galleryLimit, inputMode: 'numeric' },
                { type: 'checkbox', name: 'captions', label: s.galleryCaptions },
                { type: 'htmlpanel', html: '<p style="opacity:.75">' + editor.dom.encode(s.galleryHint) + '</p>' }
            ]
            : [{ type: 'htmlpanel', html: '<p>' + editor.dom.encode(s.galleryNone) + '</p>' }];
        editor.windowManager.open({
            title: s.gallery,
            body: { type: 'panel', items: body },
            initialData: folders.length ? { folder: folders[0].value, columns: '4', order: 'name', limit: '', captions: false } : {},
            buttons: [
                { type: 'cancel', text: 'Cancel' },
                { type: 'submit', text: 'Insert', primary: true, enabled: folders.length > 0 }
            ],
            onSubmit: function (api) {
                var data = api.getData();
                var limit = parseInt(data.limit, 10);
                var tag = '{script built-in/gallery folder=' + data.folder + ' columns=' + data.columns
                    + (data.order === 'newest' ? ' order=newest' : '')
                    + (limit > 0 ? ' limit=' + limit : '')
                    + (data.captions ? ' captions' : '') + '}';
                editor.insertContent('<p>' + editor.dom.encode(tag) + '</p>');
                api.close();
            }
        });
    }

    // "Read more": the line between what lists show and the rest of the page. There is one, so the
    // button moves it. It stays outside other elements, or the server couldn't cut the text there.
    function readMoreLine(editor) {
        var body = editor.getBody();
        editor.undoManager.transact(function () {
            editor.dom.remove(editor.dom.select('hr.py-readmore'));
            var top = editor.selection.getNode();
            while (top && top !== body && top.parentNode !== body) {
                top = top.parentNode;
            }
            if (top && top !== body && !/^(P|H[1-6]|PRE)$/.test(top.nodeName)) {
                editor.dom.insertAfter(editor.dom.create('hr', { 'class': 'py-readmore' }), top);
            } else {
                editor.insertContent('<hr class="py-readmore">');
            }
        });
        editor.nodeChanged();
    }

    // Fullscreen stays in the top right corner, whatever the rows of buttons do: it lives outside
    // the toolbar, in the space that admin.css reserves on the right of the header.
    function pinFullscreenButton(editor) {
        var header = editor.getContainer().querySelector('.tox-editor-header');
        if (!header) {
            return;
        }
        var label = editor.translate('Fullscreen');
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tox-tbtn py-tox-fullscreen';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.setAttribute('aria-pressed', 'false');
        var icon = document.createElement('span');
        icon.className = 'tox-icon tox-tbtn__icon-wrap';
        icon.innerHTML = editor.ui.registry.getAll().icons.fullscreen || '';
        button.appendChild(icon);
        button.addEventListener('click', function () {
            editor.execCommand('mceFullScreen');
            editor.focus();
        });
        editor.on('FullscreenStateChanged', function (event) {
            button.classList.toggle('tox-tbtn--enabled', event.state);
            button.setAttribute('aria-pressed', event.state ? 'true' : 'false');
        });
        header.classList.add('py-tox-header');
        header.appendChild(button);
    }

    // TinyMCE. Content keeps image paths relative to the site root ("images/..."), so a site
    // can move between folders; the site template resolves them when rendering.
    if (window.tinymce && document.querySelector('textarea.py-editor')) {
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        document.querySelectorAll('textarea.py-editor').forEach(function (area) {
            var readMore = area.hasAttribute('data-readmore');
            window.tinymce.init({
                target: area,
                license_key: 'gpl',
                base_url: cfg.base,
                suffix: '.min',
                language: cfg.language || undefined,
                skin: dark ? 'oxide-dark' : 'oxide',
                content_css: cfg.css,
                content_style: ':root { --py-readmore: ' + JSON.stringify(cfg.strings.readMore) + '; }',
                height: parseInt(area.getAttribute('data-height'), 10) || 300,
                min_height: 160,
                resize: true,
                promotion: false,
                branding: false,
                menubar: false,
                plugins: 'advlist autolink charmap code fullscreen image link lists media nonbreaking searchreplace table wordcount',
                // Fullscreen is not listed: pinFullscreenButton() keeps it visible in the corner.
                // One row on a Full HD screen, with the sidebar open; narrower, it wraps. "wrap", because
                // the default hides whatever doesn't fit under "...", and images and scripts must stay in sight.
                toolbar: 'blocks | ' + (readMore ? 'pyreadmore | ' : '') + 'bold italic underline strikethrough forecolor | bullist numlist align | '
                    + 'image pygallery media pyscript | link table charmap hr | code',
                toolbar_mode: 'wrap',
                document_base_url: location.origin + cfg.siteRoot,
                relative_urls: true,
                remove_script_host: true,
                entity_encoding: 'raw',
                image_caption: true,
                image_advtab: true,
                image_class_list: [
                    { title: '-', value: '' },
                    { title: 'Responsive (img-fluid)', value: 'img-fluid' },
                    { title: 'Rounded', value: 'img-fluid rounded' },
                    { title: 'Thumbnail', value: 'img-thumbnail' }
                ],
                table_class_list: [
                    { title: 'Bootstrap', value: 'table' },
                    { title: 'Bootstrap striped', value: 'table table-striped' },
                    { title: '-', value: '' }
                ],
                file_picker_types: 'image',
                file_picker_callback: function (callback, value) {
                    window.tinymce.activeEditor.windowManager.openUrl({
                        title: cfg.strings.libraryTitle,
                        url: pickerUrl(value),
                        width: 1100,
                        height: 680,
                        onMessage: function (api, message) {
                            var data = (message && message.data) || message || {};
                            if (data.pyMediaPath) {
                                callback(data.pyMediaPath, { alt: '' });
                                api.close();
                            }
                        }
                    });
                },
                automatic_uploads: true,
                images_file_types: 'jpg,jpeg,png,gif,webp,avif',
                images_upload_handler: function (blob) {
                    return upload(blob.blob(), 'pages').then(function (json) { return json.path; });
                },
                setup: function (editor) {
                    // Only after the language pack: TinyMCE skips the pack if the language already has texts.
                    editor.on('ScriptsLoaded', function () {
                        if (cfg.language) {
                            window.tinymce.addI18n(cfg.language, cfg.tinymceStrings);
                        }
                    });
                    // Keep the textarea in sync so the toolbar's submit buttons post current content.
                    editor.on('change input undo redo', function () { editor.save(); });
                    editor.on('keydown mousedown', function () {
                        if (window.pyActivity) { window.pyActivity(); }
                    });
                    // Content as loaded (normalised by TinyMCE), for the unsaved-changes warning in admin.js.
                    editor.on('PreInit', function () {
                        // Bootstrap's dark variables for the content, chosen on load like the skin.
                        if (dark) {
                            editor.getDoc().documentElement.setAttribute('data-bs-theme', 'dark');
                        }
                    });
                    editor.on('init', function () {
                        pinFullscreenButton(editor);
                        (window.pyEditorInitial = window.pyEditorInitial || {})[editor.id] = editor.getContent();
                    });
                    if (readMore) {
                        editor.ui.registry.addButton('pyreadmore', {
                            icon: 'page-break',
                            text: cfg.strings.readMore,
                            tooltip: cfg.strings.readMoreHint,
                            onAction: function () { readMoreLine(editor); }
                        });
                    }
                    editor.ui.registry.addButton('pygallery', {
                        icon: 'gallery',
                        tooltip: cfg.strings.gallery,
                        onAction: function () { galleryDialog(editor); }
                    });
                    editor.ui.registry.addButton('pyscript', {
                        icon: 'code-sample',
                        tooltip: cfg.strings.script,
                        onAction: function () { scriptDialog(editor); }
                    });
                }
            });
        });
        var form = document.getElementById('adminForm');
        if (form) {
            form.addEventListener('submit', function () { window.tinymce.triggerSave(); }, true);
        }
    }
})();
