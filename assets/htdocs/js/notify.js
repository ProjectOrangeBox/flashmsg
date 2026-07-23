/**
 * notify.js - growl-style renderer for orange/flashmsg (traditional pages).
 *
 * Reads the JSON the server embedded in the page:
 *
 *   <script type="application/json" id="flash-messages">
 *       <?= json_encode($flash_messages_array) ?>
 *   </script>
 *
 * where $flash_messages_array is the configured "view variable" the flashmsg
 * service mirrors into the data service - the getMessages(true) shape:
 *
 *   { "messages": [{"type":"success","msg":"Saved.","sticky":false}, ...],
 *     "count": 1, "initial_pause": 3, "pause_for_each": 1000 }
 *
 * Non-sticky notices auto-dismiss (initial_pause seconds, plus
 * pause_for_each ms per queued notice); sticky ones stay until clicked.
 *
 * Programmatic use:
 *   notify.add('Saved.', 'success');       // sticky inferred from type
 *   notify.add('Broken.', 'danger', true); // or forced
 *   notify.removeAll();
 */
var notify = {
    stickyTypes: ['danger', 'warning'],
    initialPause: 3,
    pauseForEach: 1000,

    css: `
.notice-wrap {
    position: fixed;
    top: 8px;
    right: 8px;
    width: 33%;
    z-index: 9999;
    opacity: 0.95;
}
.notice-item {
    display: block;
    position: relative;
    margin: 0 0 6px 0;
    padding: 12px;
    border-radius: 4px;
    color: #fff;
    cursor: pointer;
    font-family: sans-serif;
}
.notice-item.info    { background: #2478c8; }
.notice-item.success { background: #2f9e44; }
.notice-item.warning { background: #b8860b; }
.notice-item.danger  { background: #c0392b; }
@media (max-width: 767px) {
    .notice-wrap {
        width: 94% !important;
    }
}
`,

    wrap: null,

    boot: function () {
        // inject our css once
        var style = document.createElement('style');
        style.textContent = notify.css;
        document.head.appendChild(style);

        notify.wrap = document.createElement('div');
        notify.wrap.className = 'notice-wrap';
        document.body.appendChild(notify.wrap);

        // consume the server-embedded payload when present
        var tag = document.getElementById('flash-messages');

        if (!tag) {
            return;
        }

        var payload;

        try {
            payload = JSON.parse(tag.textContent);
        } catch (e) {
            return;
        }

        if (!payload || !Array.isArray(payload.messages)) {
            return;
        }

        notify.initialPause = payload.initial_pause ?? notify.initialPause;
        notify.pauseForEach = payload.pause_for_each ?? notify.pauseForEach;

        payload.messages.forEach(function (m, index) {
            notify.add(m.msg, m.type, m.sticky, index);
        });
    },

    add: function (msg, type, sticky, index) {
        type = type || 'info';
        sticky = (sticky !== undefined) ? sticky : notify.stickyTypes.includes(type);
        index = index || 0;

        var item = document.createElement('div');
        item.className = 'notice-item ' + type;
        item.textContent = msg;

        // click always dismisses
        item.addEventListener('click', function () {
            notify.remove(item);
        });

        notify.wrap.appendChild(item);

        if (!sticky) {
            var delay = (notify.initialPause * 1000) + (index * notify.pauseForEach);

            setTimeout(function () {
                notify.remove(item);
            }, delay);
        }

        return item;
    },

    remove: function (item) {
        if (item && item.parentNode) {
            item.parentNode.removeChild(item);
        }
    },

    removeAll: function () {
        if (notify.wrap) {
            notify.wrap.textContent = '';
        }
    },
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', notify.boot);
} else {
    notify.boot();
}
