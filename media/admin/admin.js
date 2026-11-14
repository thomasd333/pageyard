/* Pageyard administrator: sidebar, theme, list checkboxes and toolbar checks. No dependencies besides Bootstrap. */
(function () {
    'use strict';

    var root = document.documentElement;
    var mobile = window.matchMedia('(max-width: 991.98px)');

    function store(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* private mode */ }
    }

    // Sidebar: collapsed to icons on wide screens, sliding panel on small ones.
    var toggle = document.getElementById('py-sidebar-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            if (mobile.matches) {
                root.classList.toggle('py-sidebar-open');
            } else {
                var closed = root.classList.toggle('py-sidebar-closed');
                store('py-sidebar', closed ? 'closed' : 'open');
            }
        });
        document.getElementById('py-sidebar-backdrop').addEventListener('click', function () {
            root.classList.remove('py-sidebar-open');
        });
    }

    // Light / dark switch; the choice overrides the system setting.
    var theme = document.getElementById('py-theme-toggle');
    if (theme) {
        theme.addEventListener('click', function () {
            var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-bs-theme', next);
            store('py-theme', next);
        });
    }

    var form = document.getElementById('adminForm');
    function rows() {
        return form ? Array.prototype.slice.call(form.querySelectorAll('input[name="cid[]"]')) : [];
    }

    // "Check all" in list headers.
    document.querySelectorAll('[data-check-all]').forEach(function (all) {
        all.addEventListener('change', function () {
            rows().forEach(function (cb) { cb.checked = all.checked; });
        });
    });

    // Row buttons (e.g. the status icon) act on their own row only.
    document.querySelectorAll('[data-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            rows().forEach(function (cb) { cb.checked = cb.id === btn.getAttribute('data-row'); });
        });
    });

    // Toolbar buttons that need selected rows; deleting asks first.
    document.querySelectorAll('button[data-list-required]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            if (!rows().some(function (cb) { return cb.checked; })) {
                event.preventDefault();
                event.stopPropagation();
                window.alert(window.PY.selectFirst);
                return;
            }
            if (btn.value && /\.delete$/.test(btn.value) && !window.confirm(window.PY.confirmDelete)) {
                event.preventDefault();
            }
        }, true);
    });

    // Buttons that ask before doing something; first in line, so a "no" leaves the row unchecked.
    document.querySelectorAll('button[data-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            if (!window.confirm(btn.getAttribute('data-confirm'))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        }, true);
    });

    // Small forms that ask before doing something (e.g. restoring a version).
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (event) {
            if (!window.confirm(f.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Sandboxed content previews grow to the height of their content (no scripts run inside them).
    document.querySelectorAll('iframe[data-autoheight]').forEach(function (frame) {
        var fit = function () {
            var doc = frame.contentDocument;
            if (doc && doc.documentElement) {
                frame.style.height = doc.documentElement.scrollHeight + 'px';
            }
        };
        frame.addEventListener('load', fit);
        fit();
    });

    // Tabs of a form that come back after saving: the open one goes along in the "tab" field.
    document.querySelectorAll('[data-remember-tab]').forEach(function (tabs) {
        tabs.addEventListener('shown.bs.tab', function (event) {
            var field = tabs.closest('form').elements.tab;
            field.value = event.target.id.replace(/^tab-/, '');
        });
    });

    // Edit forms (data-track-changes): warn before leaving with unsaved changes. Plain fields are
    // compared with a snapshot taken on load; TinyMCE editors with their content right after their
    // own init (editor.js keeps it in window.pyEditorInitial). Submitting the form (any toolbar
    // button) leaves without a warning; "Close"/"Cancel" asks first when something changed.
    if (form && form.hasAttribute('data-track-changes')) {
        var editorFields = function (name) {
            var el = form.elements[name];
            return el && el.classList && el.classList.contains('py-editor');
        };
        var serialize = function () {
            var parts = [];
            new FormData(form).forEach(function (value, name) {
                if (name !== 'token' && name !== 'tab' && !editorFields(name)) {
                    parts.push(name + '=' + (typeof value === 'string' ? value : value.name));
                }
            });
            return parts.join('&');
        };
        var initial = serialize();
        var leaving = false;
        var changed = function () {
            if (serialize() !== initial) {
                return true;
            }
            var editors = window.tinymce ? window.tinymce.get() : [];
            var start = window.pyEditorInitial || {};
            return editors.some(function (ed) {
                return Object.prototype.hasOwnProperty.call(start, ed.id) && ed.getContent() !== start[ed.id];
            });
        };
        document.querySelectorAll('button[form="adminForm"][formnovalidate]').forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                if (changed() && !window.confirm(window.PY.confirmDiscard)) {
                    event.preventDefault();
                }
            });
        });
        // Writing in a form sends nothing to the server, so the session could end under someone working.
        // While there is activity (never when idle) the server is asked at most every tenth of the
        // session's length: about once an hour with 600 minutes. If the session did end, nothing is lost:
        // saving waits, a notice offers logging in in another tab, and the form takes over the new token.
        var sessionMs = window.PY.sessionSeconds * 1000;
        var askEvery = Math.max(30000, sessionMs / 10);
        var confirmedAt = Date.now();   // when the server last confirmed the session
        var askedAt = confirmedAt;
        var notice = null;
        var showNotice = function () {
            if (notice) {
                return;
            }
            notice = document.createElement('div');
            notice.className = 'alert alert-warning shadow position-fixed bottom-0 end-0 m-3';
            notice.style.zIndex = 1080;
            notice.style.maxWidth = '28rem';
            notice.setAttribute('role', 'alert');
            notice.textContent = window.PY.sessionLost;
            var link = document.createElement('a');
            link.className = 'btn btn-sm btn-warning d-block mt-2';
            link.href = window.PY.adminUrl;
            link.target = '_blank';
            link.textContent = window.PY.sessionLogin;
            notice.appendChild(link);
            document.body.appendChild(notice);
        };
        var askServer = function (then) {
            askedAt = Date.now();
            fetch(window.PY.pingUrl, { credentials: 'same-origin' }).then(function (response) {
                return response.status === 401 ? { expired: true } : response.json();
            }).catch(function () {
                return {};  // no answer (server down): says nothing about the session
            }).then(function (answer) {
                if (answer.token) {
                    confirmedAt = Date.now();
                    document.querySelectorAll('input[name="token"]').forEach(function (field) { field.value = answer.token; });
                    if (window.PY_EDITOR) {
                        window.PY_EDITOR.token = answer.token;
                    }
                    if (notice) {
                        notice.remove();
                        notice = null;
                    }
                } else if (answer.expired) {
                    showNotice();
                }
                if (then) {
                    then(!answer.expired);
                }
            });
        };
        // Also called by the TinyMCE editors (editor.js), whose events stay inside their frames.
        window.pyActivity = function () {
            if (Date.now() - askedAt > askEvery) {
                askServer();
            }
        };
        ['keydown', 'pointerdown'].forEach(function (name) {
            document.addEventListener(name, window.pyActivity, { capture: true, passive: true });
        });
        window.addEventListener('focus', function () {  // back from logging in in another tab
            if (notice) {
                askServer();
            }
        });
        form.addEventListener('submit', function (event) {
            var button = event.submitter;
            var discards = button && button.hasAttribute('formnovalidate');
            if (!discards && (notice || Date.now() - confirmedAt > sessionMs - 10000)) {
                event.preventDefault();
                askServer(function (alive) {
                    if (alive) {
                        confirmedAt = Date.now();  // also when there was no answer: let the browser try
                        form.requestSubmit(button);
                    }
                });
                return;
            }
            leaving = true;
        });
        window.addEventListener('beforeunload', function (event) {
            if (!leaving && changed()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    // Same idea as Pageyard\Str::slug() on the server (which has the final word when saving).
    var MAP = { 'ł': 'l', 'Ł': 'l', 'ß': 'ss', 'æ': 'ae', 'Æ': 'ae', 'ø': 'o', 'Ø': 'o', 'đ': 'd', 'Đ': 'd', 'þ': 'th', 'œ': 'oe', 'ı': 'i' };
    function slug(text) {
        return text.replace(/[łŁßæÆøØđĐþœı]/g, function (c) { return MAP[c]; })
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 190);
    }

    // Alias/path fields show what will be generated from the title while they are empty.
    document.querySelectorAll('[data-slug-source]').forEach(function (title) {
        var pane = title.closest('.tab-pane') || document;
        var alias = pane.querySelector('[data-slug-target]');
        if (!alias) {
            return;
        }
        var update = function () { alias.placeholder = slug(title.value); };
        title.addEventListener('input', update);
        update();
    });

    // Conditional fields: data-show-if="type:page,list" / data-hide-if="home:1" (several rules: "a:1;b:x");
    // data-enable-if on a fieldset greys it out instead (disabled fields aren't posted).
    function fieldValue(name) {
        var value = '';
        document.querySelectorAll('[name="' + name + '"]').forEach(function (el) {
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) { value = el.value; }
            } else {
                value = el.value;
            }
        });
        return value;
    }
    function rulesMatch(spec) {
        return spec.split(';').every(function (rule) {
            var parts = rule.split(':');
            return parts[1].split(',').indexOf(fieldValue(parts[0].trim())) !== -1;
        });
    }
    var conditional = document.querySelectorAll('[data-show-if], [data-hide-if], [data-enable-if]');
    function applyConditions() {
        conditional.forEach(function (el) {
            if (el.hasAttribute('data-enable-if')) {
                el.disabled = !rulesMatch(el.getAttribute('data-enable-if'));
            }
            var show = el.hasAttribute('data-show-if') ? rulesMatch(el.getAttribute('data-show-if')) : true;
            if (show && el.hasAttribute('data-hide-if') && rulesMatch(el.getAttribute('data-hide-if'))) {
                show = false;
            }
            el.hidden = !show;
        });
    }
    if (conditional.length) {
        document.addEventListener('change', applyConditions);
        applyConditions();
    }

    // A new hero block starts on the home page only: a large banner on every page is rarely meant.
    var newBlock = document.querySelector('form[data-new-block]');
    if (newBlock) {
        newBlock.addEventListener('change', function (event) {
            if (event.target.name === 'type' && event.target.value === 'hero' && fieldValue('assignment') === 'all') {
                document.getElementById('f-assign-only').checked = true;
                document.getElementById('f-target-home').checked = true;
                applyConditions();
            }
        });
    }

    // Code textareas: Tab indents (4 spaces) instead of leaving the field; Shift+Tab outdents a line.
    document.querySelectorAll('[data-code-editor]').forEach(function (area) {
        area.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey) {
                return;
            }
            event.preventDefault();
            var start = area.selectionStart, end = area.selectionEnd, value = area.value;
            var lineStart = value.lastIndexOf('\n', start - 1) + 1;
            if (event.shiftKey) {
                var removed = value.substr(lineStart, 4).match(/^ {0,4}/)[0].length;
                area.value = value.slice(0, lineStart) + value.slice(lineStart + removed);
                area.selectionStart = area.selectionEnd = Math.max(lineStart, start - removed);
            } else {
                area.value = value.slice(0, start) + '    ' + value.slice(end);
                area.selectionStart = area.selectionEnd = start + 4;
            }
        });
    });

    // Filters that apply on change.
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });
})();
