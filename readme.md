# Flashmsg

Queues one-request flash messages (success/error/info banners), persisting them across a redirect via the session, and exposes them both to a PHP view and to a small JS popup (`notify.js`).

## Example

```php
use peels\flashmsg\Flashmsg;
use peels\flashmsg\FlashMsgInterface;

$flash = Flashmsg::getInstance($config, $session, $input, $output, $data); // $data implements DataInterface

$flash->msg('Profile updated.', FlashMsgInterface::SUCCESS);
$flash->msgs(['Field A is required.', 'Field B is invalid.'], FlashMsgInterface::DANGER);

$flash->redirect('/profile'); // stores messages in the session, then redirects

// on the next page load, Flashmsg pulls them back out of the session automatically
$messages = $flash->getMessages(); // [['type' => 'green', 'msg' => 'Profile updated.', 'sticky' => false], ...]
```

If a `DataInterface` is supplied, the current messages are also written to the configured `view variable` (see `flashmsg/src/config`) on every call, so a template can render them directly without calling `getMessages()` itself.

## JS popup integration

Include `notify.js` on each page that needs to show flash messages (or just add it to every page) — add a few flash messages, redirect to another page, and they pop up.

Make sure to edit `notify.js` line 6 so it matches the view variable populated by your server when building the page:

```html
<script>var flashMsgs = "[{text:'Foo',style:'success'},{text:'Bar',style:'danager'}]";</script>
```

The variable is `flashMsgs` in this case, so line 6 should be:

```js
notify.messages = flashMsgs;
```
