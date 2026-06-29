/**
 * Minimal canvas signature pad (no external dependencies).
 *
 * Captures pointer/touch drawing on a <canvas> and serialises it as a PNG
 * data URI into the associated hidden input on every change, so the value is
 * submitted with the form. Works for mouse, touch and pen input.
 */
(function () {
    'use strict';

    function initPad(container) {
        var canvas = container.querySelector('[data-signature-canvas]');
        var input = container.querySelector('[data-signature-input]');
        var clearBtn = container.querySelector('[data-signature-clear]');

        if (!canvas || !input) {
            return;
        }

        var ctx = canvas.getContext('2d');
        var drawing = false;
        var hasContent = false;

        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#000';

        function pos(event) {
            var rect = canvas.getBoundingClientRect();
            var point = event.touches ? event.touches[0] : event;
            return {
                x: (point.clientX - rect.left) * (canvas.width / rect.width),
                y: (point.clientY - rect.top) * (canvas.height / rect.height)
            };
        }

        function start(event) {
            event.preventDefault();
            drawing = true;
            var p = pos(event);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        }

        function move(event) {
            if (!drawing) {
                return;
            }
            event.preventDefault();
            var p = pos(event);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hasContent = true;
        }

        function end() {
            if (!drawing) {
                return;
            }
            drawing = false;
            input.value = hasContent ? canvas.toDataURL('image/png') : '';
        }

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        canvas.addEventListener('touchend', end);

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasContent = false;
                input.value = '';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var pads = document.querySelectorAll('[data-workflow-signature]');
        for (var i = 0; i < pads.length; i++) {
            initPad(pads[i]);
        }
    });
})();
