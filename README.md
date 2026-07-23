# Flashmsg

One-request flash messages (success/error/info banners) with **two delivery
modes sharing one queue** — so the same package serves a traditional
server-rendered application and a SPA (Vue, React, ...) talking to a JSON API.

```text
                        ┌──────────► redirect() / keep()      traditional: PRG + session
msg() / msgs() ─► queue │
     session drain ─────┴──────────► pull() / jsonSerialize()  SPA: ride the JSON response
```

**A cookbook of worked examples lives in [example.md](example.md).**

## Traditional (PRG) delivery

```php
use orange\flashmsg\Flashmsg;
use orange\flashmsg\FlashMsgInterface;

$flash = Flashmsg::getInstance($config, $session, $input, $output, $data);

$flash->msg('Profile updated.', FlashMsgInterface::SUCCESS);
$flash->msgs(['Field A is required.', 'Field B is invalid.'], FlashMsgInterface::DANGER);

$flash->redirect('/profile');   // persists the queue in the session, then redirects
$flash->redirect('@');          // '@' (or no argument) redirects to the HTTP referer
```

On the next request, construction drains the session back into the queue and
(when a `DataInterface` was supplied) mirrors it into the configured
`view variable`, so a template can render it — or embed it for
[notify.js](#js-popup-integration).

## SPA / JSON delivery

The session becomes optional — a stateless API can pass `null`:

```php
$flash = Flashmsg::getInstance($config, null, $input, $output);

$flash->msg('Record saved.', FlashMsgInterface::SUCCESS);

// in the response builder: return the messages AND clear the queue,
// so the batch rides exactly one response
$payload['flash_messages_array'] = $flash->pull();
```

`pull()` (and `jsonSerialize()` — the service itself can be dropped into a
payload) emit the detailed shape:

```json
{ "messages": [{"type": "success", "msg": "Record saved.", "sticky": false}],
  "count": 1, "initial_pause": 3, "pause_for_each": 1000 }
```

Because construction still drains the session when one *is* provided, a
server-side redirect **into** the SPA (a login POST, an OAuth callback)
leaves messages that ride out on the SPA's first API response — one
mechanism, both worlds. See example.md for the `before.output` event
listener that makes this automatic for every JSON response, and for the
Vue-side toast store sketch.

## API

| Method | Purpose |
| --- | --- |
| `msg(string $msg, ?string $type = null)` | queue one message (repeat identical messages are idempotent) |
| `msgs(array $array, ?string $type = null)` | queue several: a list (one shared type) or `message => type` pairs |
| `redirect(?string $redirect = null)` | persist queue to session + redirect; `'@'`/null → HTTP referer |
| `keep()` | persist queue to session for the next request *without* redirecting |
| `pull(bool $detailed = true)` | return the messages **and clear the queue** (SPA embed) |
| `getMessages(bool $detailed = false)` | read without clearing |
| `hasMessages()` / `count()` (Countable) | queue inspection |
| `clear()` | empty the queue |
| `jsonSerialize()` (JsonSerializable) | detailed shape, for embedding the service directly |

Message types are semantic — `danger`, `warning`, `info`, `success`
(constants on `FlashMsgInterface`). Legacy color names (`red`, `yellow`,
`blue`, `green`) resolve through the configured `type aliases` map, so old
call sites keep working while everything stored and delivered is canonical.
`danger` and `warning` are sticky by default (require manual dismissal in
the JS layer).

## Configuration

`src/config/flashmsg.php` (merged under anything you pass in):

| Key | Default | Purpose |
| --- | --- | --- |
| `sticky types` | `['danger', 'warning']` | semantic types requiring manual dismissal |
| `type aliases` | `red/yellow/blue/green` map | legacy color → semantic type resolution |
| `initial pause` | `3` | seconds before the first auto-dismiss |
| `pause for each` | `1000` | ms added per queued message |
| `default type` | `info` | type when `msg()` gets none |
| `http referer` | `''` | fallback when the input service carries no referer |
| `view variable` | `flash_messages_array` | data-service key the queue mirrors into (namespaced so it can't collide with a page's own variables) |
| `session msg key` | `__#internal::flash::msg#__` | session storage key |

Constructor: `(array $config, ?SessionInterface $session, InputInterface $input, OutputInterface $output, ?DataInterface $data = null, ?EventInterface $event = null)`
— session, data, and event are all optional. Without a session,
`redirect()`/`keep()` throw (`InvalidValue`) since there is nothing to carry
the messages across requests. When an event service is supplied, `flash.msg`
fires for every added message.

## JS popup integration

`assets/htdocs/js/notify.js` renders growl-style popups on traditional
pages. Embed the view variable as JSON — no globals, no editing the JS:

```php
<script type="application/json" id="flash-messages"><?= json_encode($flash_messages_array) ?></script>
<script src="/js/notify.js"></script>
```

Non-sticky notices auto-dismiss using `initial_pause`/`pause_for_each`;
sticky ones stay until clicked. `notify.add(msg, type)` is available for
purely client-side notices.

## Testing

```sh
composer test          # or: cd unittest && sh runUnitTests.sh
```
