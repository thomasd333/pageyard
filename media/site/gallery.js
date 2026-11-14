/* Pageyard image viewer for [data-py-gallery] grids: arrows, swipe, Esc, click outside the image to close. */
(function () {
    'use strict';

    var dialog = null, image = null, caption = null, links = [], index = 0, opener = null;

    function button(cls, label, icon, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'py-viewer-btn ' + cls;
        b.setAttribute('aria-label', label);
        b.innerHTML = icon;
        b.addEventListener('click', function (event) {
            event.stopPropagation();
            onClick();
        });
        return b;
    }

    function show(i) {
        index = (i + links.length) % links.length;
        var link = links[index];
        image.src = link.href;
        image.alt = link.title;
        caption.textContent = link.title;
        // The neighbours load ahead, so moving on is instant.
        [index - 1, index + 1].forEach(function (n) {
            new Image().src = links[(n + links.length) % links.length].href;
        });
    }

    function build(labels) {
        dialog = document.createElement('dialog');
        dialog.className = 'py-viewer';
        image = document.createElement('img');
        image.addEventListener('click', function (event) { event.stopPropagation(); });
        caption = document.createElement('div');
        caption.className = 'py-viewer-caption';
        var prev = button('py-viewer-prev', labels.prev, '&#10094;', function () { show(index - 1); });
        var next = button('py-viewer-next', labels.next, '&#10095;', function () { show(index + 1); });
        dialog.append(image, caption, prev, next, button('py-viewer-close', labels.close, '&#10005;', function () { dialog.close(); }));
        dialog.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') { show(index - 1); }
            if (event.key === 'ArrowRight') { show(index + 1); }
        });
        dialog.addEventListener('close', function () {
            image.removeAttribute('src');
            if (opener) { opener.focus(); }
        });
        var startX = null;
        dialog.addEventListener('touchstart', function (event) { startX = event.touches[0].clientX; }, { passive: true });
        dialog.addEventListener('touchend', function (event) {
            var moved = event.changedTouches[0].clientX - startX;
            if (Math.abs(moved) > 50) { show(index + (moved < 0 ? 1 : -1)); }
        });
        document.body.append(dialog);
    }

    document.querySelectorAll('[data-py-gallery]').forEach(function (grid) {
        var own = Array.prototype.slice.call(grid.querySelectorAll('a'));
        own.forEach(function (link, i) {
            link.addEventListener('click', function (event) {
                if (!window.HTMLDialogElement) { return; }  // the link opens the image
                event.preventDefault();
                if (!dialog) { build({ close: grid.dataset.close, prev: grid.dataset.prev, next: grid.dataset.next }); }
                links = own;
                opener = link;
                var single = own.length < 2;
                dialog.querySelector('.py-viewer-prev').hidden = single;
                dialog.querySelector('.py-viewer-next').hidden = single;
                show(i);
                dialog.showModal();
            });
        });
    });
})();
