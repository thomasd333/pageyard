/* Pageyard administrator: media library (Content > Media) and the media picker inside dialogs. */
(function () {
    'use strict';

    var box = document.querySelector('.py-media');
    var strings = window.PY_MEDIA || {};
    if (!box) {
        return;
    }
    var form = document.getElementById('adminForm');
    var tokenField = document.querySelector('input[name="token"]');

    // Uploads: several files, one after another, into the current folder; then reload.
    var zone = box.querySelector('[data-dropzone]');
    var input = box.querySelector('[data-upload-input]');
    var label = box.querySelector('[data-upload-label]');
    function upload(files) {
        files = Array.prototype.slice.call(files);
        if (!files.length) {
            return;
        }
        var errors = [];
        var done = 0;
        zone.classList.add('py-busy');
        var next = function () {
            if (!files.length) {
                if (errors.length) {
                    window.alert(errors.join('\n'));
                }
                location.reload();
                return;
            }
            var file = files.shift();
            label.textContent = (strings.uploading || '') + ' ' + (++done) + ' / ' + (done + files.length) + ': ' + file.name;
            var data = new FormData();
            data.append('task', 'media.upload');
            data.append('token', tokenField.value);
            data.append('dir', box.getAttribute('data-upload-dir'));
            data.append('file', file, file.name);
            fetch(box.getAttribute('data-upload-url'), { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || !j.path) { throw new Error(j.error || strings.uploadFailed); } }); })
                .catch(function (e) { errors.push(file.name + ': ' + e.message); })
                .finally(next);
        };
        next();
    }
    input.addEventListener('change', function () { upload(input.files); });
    ['dragenter', 'dragover'].forEach(function (type) {
        zone.addEventListener(type, function (e) { e.preventDefault(); zone.classList.add('py-dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        zone.addEventListener(type, function (e) { e.preventDefault(); zone.classList.remove('py-dragover'); });
    });
    zone.addEventListener('drop', function (e) { upload(e.dataTransfer.files); });

    // Picker: hand the chosen path to the opener (TinyMCE URL dialog or an image field's modal).
    box.querySelectorAll('[data-pick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.parent.postMessage({ mceAction: 'customAction', data: { pyMediaPath: btn.getAttribute('data-pick') } }, location.origin);
        });
    });

    // Copy a path ("images/...") to paste into a field or the editor.
    box.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject())
                .then(function () { btn.title = strings.copied; btn.querySelector('i').className = 'bi bi-clipboard-check'; })
                .catch(function () { window.prompt('', text); });
        });
    });

    // Deleting images that pages still use: say which in the confirmation (admin.js asks with PY.confirmDelete).
    if (form && window.PY) {
        var plain = window.PY.confirmDelete;
        form.addEventListener('change', function () {
            var used = Array.prototype.slice.call(form.querySelectorAll('input[name="cid[]"]:checked'))
                .map(function (cb) { return cb.closest('[data-used]'); })
                .filter(Boolean)
                .map(function (card) { return card.getAttribute('data-used'); });
            window.PY.confirmDelete = used.length ? strings.confirmDeleteUsed.replace('%s', used.join(', ')) : plain;
        });
    }
})();
