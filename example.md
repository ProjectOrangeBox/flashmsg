# orange/flashmsg — Examples

Worked examples for both delivery modes. See [README.md](README.md) for the
API and configuration reference.

## Bootstrapping

```php
use orange\flashmsg\Flashmsg;

// traditional app (session-backed, view mirroring, events):
$flash = Flashmsg::getInstance(
    [],
    container()->session,     // enables redirect()/keep()
    container()->input,
    container()->output,
    container()->data,        // mirrors the queue into the view variable
    container()->events,      // fires 'flash.msg' per message
);

// stateless JSON API - the SPA minimum:
$flash = Flashmsg::getInstance([], null, container()->input, container()->output);
```

## Traditional: the PRG flow

```php
use orange\flashmsg\FlashMsgInterface;

// POST /profile - validation failed
$flash->msgs($errors, FlashMsgInterface::DANGER)->redirect('@');   // back to the form

// POST /profile - success
$flash->msg('Profile updated.', FlashMsgInterface::SUCCESS);
$flash->redirect('/profile');
```

On the redirected-to page the queue is already drained into the view
variable (`flash_messages_array` by default — deliberately namespaced so it
can't collide with a page's own variables). Render it server-side:

```php
<?php foreach ($flash_messages_array['messages'] as $m): ?>
    <div class="alert alert-<?= $m['type'] ?>"><?= htmlspecialchars($m['msg']) ?></div>
<?php endforeach ?>
```

...or hand it to the bundled popup renderer:

```php
<script type="application/json" id="flash-messages"><?= json_encode($flash_messages_array) ?></script>
<script src="/js/notify.js"></script>
```

## Traditional: message on the SAME page (no redirect)

The queue mirrors into the view variable on every `msg()` call, so a
controller that renders directly just adds messages and renders:

```php
$flash->msg('Welcome back!', FlashMsgInterface::SUCCESS);

return $this->view->render('dashboard');   // template reads $flash_messages_array
```

## SPA: embed in every JSON response automatically

One `before.output` listener makes flash delivery invisible to your
controllers — this is the recommended wiring (in the app's
`config/event.php`):

```php
return [
    'before.output' => [
        [function ($router, $input, $output) {
            $flash = container()->flash;

            // only touch JSON responses that have something to say
            if (!$flash->hasMessages() || !str_contains($output->getContentType(), 'json')) {
                return;
            }

            $payload = json_decode($output->get(), true) ?? [];
            $payload['flash_messages_array'] = $flash->pull();   // pull() = deliver exactly once

            $output->write(json_encode($payload), false);
        }],
    ],
];
```

Now any handler can flash without thinking about transport:

```php
public function store(): string
{
    // ... persist ...

    container()->flash->msg('Record saved.', FlashMsgInterface::SUCCESS);

    return $this->response(201);   // body gains a "flash_messages_array" key automatically
}
```

Because construction drains the session (when one is wired), a server-side
redirect *into* the SPA — a login POST, an OAuth callback — leaves messages
that ride out on the SPA's **first** API response through this same listener.

## SPA: the Vue side

A small Pinia store + a fetch wrapper that feeds it (matching the records
app's store pattern):

```ts
// stores/flash.ts
import { defineStore } from 'pinia'

export interface FlashMessage { type: string; msg: string; sticky: boolean }

export const useFlashStore = defineStore('flash', {
    state: () => ({ toasts: [] as FlashMessage[] }),
    actions: {
        // call on EVERY api response body
        absorb(body: any) {
            body?.flash_messages_array?.messages?.forEach((m: FlashMessage) => this.toasts.push(m))
        },
        dismiss(index: number) {
            this.toasts.splice(index, 1)
        },
    },
})
```

```ts
// the api wrapper every store call goes through
async function api(url: string, options?: RequestInit) {
    const response = await fetch(url, options)
    const body = response.status === 204 ? null : await response.json()

    useFlashStore().absorb(body)

    return body
}
```

```vue
<!-- FlashToasts.vue - auto-dismiss non-sticky, click dismisses anything -->
<template>
    <div class="toasts">
        <div v-for="(t, i) in flash.toasts" :key="i"
             :class="['toast', t.type]" @click="flash.dismiss(i)">
            {{ t.msg }}
        </div>
    </div>
</template>

<script setup lang="ts">
import { watch } from 'vue'
import { useFlashStore } from '@/stores/flash'

const flash = useFlashStore()

watch(() => flash.toasts.length, () => {
    flash.toasts.forEach((t, i) => {
        if (!t.sticky) {
            setTimeout(() => flash.dismiss(i), 3000 + i * 1000)
        }
    })
})
</script>
```

## Delivering to the NEXT request without a redirect

```php
// this handler responds 200 with a page, but the message belongs to
// whatever the user loads next
$flash->msg('Your export is running - check back shortly.')->keep();
```

## Types, aliases, and stickiness

```php
$flash->msg('Saved.', FlashMsgInterface::SUCCESS);   // 'success'
$flash->msg('Careful.', 'warning');                  // sticky by default
$flash->msg('Boom.', 'red');                         // legacy alias -> 'danger', sticky

// list form - one shared type
$flash->msgs(['One saved.', 'Two saved.'], FlashMsgInterface::SUCCESS);

// pair form - message => type (note: a purely numeric message can't be a
// PHP array key; use the list form or msg() for those)
$flash->msgs(['Saved.' => 'success', 'Email failed.' => 'danger']);

// identical messages queue once - safe to flash in a loop
foreach ($rows as $row) {
    $flash->msg('Some rows were skipped.', 'warning');
}
```

## Inspection

```php
$flash->hasMessages();          // bool
count($flash);                  // Countable
$flash->getMessages();          // read without clearing (plain list)
$flash->getMessages(true);      // + count and pause metadata
$flash->clear();                // drop everything
json_encode($flash);            // JsonSerializable - the detailed shape
```
