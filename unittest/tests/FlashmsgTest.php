<?php

declare(strict_types=1);

use orange\framework\Data;
use orange\framework\Event;
use orange\framework\Input;
use orange\flashmsg\Flashmsg;
use orange\session\SessionInterface;
use orange\flashmsg\FlashMsgInterface;
use orange\framework\stubs\Output as OutputStub;
use orange\framework\exceptions\InvalidValue;

/**
 * Array-backed stand-in for the session service - just enough for Flashmsg,
 * which only calls get(), set(), and remove().
 */
class FlashMockSession implements SessionInterface
{
    public array $data = [];

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->remove($key);
    }

    public function start(array $customOptions = []): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function destroy(): bool
    {
        $this->data = [];

        return true;
    }

    public function destroyCookie(): bool
    {
        return true;
    }

    public function stop(): bool
    {
        return true;
    }

    public function abort(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        $default = func_num_args() > 1 ? func_get_arg(1) : null;

        return $this->data[$key] ?? $default;
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function getMulti(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function setMulti(array $items): static
    {
        $this->data = $items + $this->data;

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->data[$key]);

        return $this;
    }

    public function removeMulti(array $keys): static
    {
        foreach ($keys as $key) {
            $this->remove($key);
        }

        return $this;
    }

    public function removeAll(): static
    {
        $this->data = [];

        return $this;
    }

    public function regenerateId(bool $deleteOldSession = false): bool
    {
        return true;
    }

    public function reset(): bool
    {
        return true;
    }

    public function getFlash(string $key): mixed
    {
        return null;
    }

    public function setFlash(string $key, mixed $value): static
    {
        return $this;
    }

    public function removeFlash(string $key): static
    {
        return $this;
    }

    public function getTemp(string $key): mixed
    {
        return null;
    }

    public function setTemp(string $key, mixed $value, int $ttl = 60): static
    {
        return $this;
    }

    public function removeTemp(string $key): static
    {
        return $this;
    }

    public function id(?string $newId = null): string|false
    {
        return 'mock';
    }

    public function gc(): int|false
    {
        return 0;
    }
}

final class FlashmsgTest extends unitTestHelper
{
    private const SESSION_KEY = '__#internal::flash::msg#__';
    private const REFERER = 'https://www.example.com/about';

    protected $instance;
    private FlashMockSession $session;
    private $input;
    private $output;
    private $data;

    protected function setUp(): void
    {
        $this->session = new FlashMockSession();

        $this->input = Input::newInstance([
            'server' => [
                'HTTP_REFERER' => self::REFERER,
                'request_method' => 'get',
            ],
        ]);

        // the stub's own config dir lacks required keys (status codes, mimes)
        // so hand it the framework's real output config
        $this->output = OutputStub::newInstance(require __FRAMEWORK_SRC__ . '/config/output.php', $this->input);

        $this->data = Data::newInstance([]);

        $this->instance = $this->make();
    }

    private function make(?FlashMockSession $session = null, bool $withData = true): Flashmsg
    {
        return Flashmsg::newInstance(
            [],
            $session ?? $this->session,
            $this->input,
            $this->output,
            $withData ? $this->data : null,
        );
    }

    /* queueing */

    public function testMsgAndViewVariable(): void
    {
        $this->instance->msg('Saved.');

        $this->assertSame('Saved.', $this->instance->getMessages()[0]['msg']);
        $this->assertSame('info', $this->instance->getMessages()[0]['type']);

        // mirrored into the data service for views
        $this->assertSame('Saved.', $this->data['flash_messages_array']['messages'][0]['msg']);
    }

    public function testLegacyColorAliasesResolveToSemanticTypes(): void
    {
        $this->instance->msg('Boom.', 'red');
        $this->instance->msg('Nice.', FlashMsgInterface::SUCCESS);

        $messages = $this->instance->getMessages();

        $this->assertSame('danger', $messages[0]['type']);
        $this->assertTrue($messages[0]['sticky']);
        $this->assertSame('success', $messages[1]['type']);
        $this->assertFalse($messages[1]['sticky']);
    }

    public function testStickyFollowsSemanticType(): void
    {
        $this->instance->msg('Careful.', 'warning');
        $this->instance->msg('FYI.', 'info');

        $this->assertTrue($this->instance->getMessages()[0]['sticky']);
        $this->assertFalse($this->instance->getMessages()[1]['sticky']);
    }

    public function testMsgsListForm(): void
    {
        $this->instance->msgs(['One.', 'Two.'], 'success');

        $messages = $this->instance->getMessages();

        $this->assertCount(2, $messages);
        $this->assertSame('success', $messages[0]['type']);
        $this->assertSame('success', $messages[1]['type']);
    }

    public function testMsgsPairForm(): void
    {
        $this->instance->msgs(['One.' => 'red', 'Two.' => 'info']);

        $messages = $this->instance->getMessages();

        $this->assertSame('danger', $messages[0]['type']);
        $this->assertSame('info', $messages[1]['type']);
    }

    public function testDuplicateMessagesAreIdempotent(): void
    {
        $this->instance->msg('Same.', 'info');
        $this->instance->msg('Same.', 'info');

        $this->assertCount(1, $this->instance);
    }

    public function testCountHasMessagesClear(): void
    {
        $this->assertFalse($this->instance->hasMessages());
        $this->assertCount(0, $this->instance);

        $this->instance->msg('One.');

        $this->assertTrue($this->instance->hasMessages());
        $this->assertCount(1, $this->instance);

        $this->instance->clear();

        $this->assertFalse($this->instance->hasMessages());
        $this->assertSame([], $this->data['flash_messages_array']['messages']);
    }

    /* SPA / JSON delivery */

    public function testJsonSerializeEmitsTheDetailedShape(): void
    {
        $this->instance->msg('Saved.', 'success');

        $decoded = json_decode(json_encode($this->instance), true);

        $this->assertSame('Saved.', $decoded['messages'][0]['msg']);
        $this->assertSame(1, $decoded['count']);
        $this->assertArrayHasKey('initial_pause', $decoded);
        $this->assertArrayHasKey('pause_for_each', $decoded);
    }

    public function testPullReturnsAndClears(): void
    {
        $this->instance->msg('Once only.', 'success');

        $pulled = $this->instance->pull();

        $this->assertSame('Once only.', $pulled['messages'][0]['msg']);
        $this->assertFalse($this->instance->hasMessages());

        // a second pull is empty - the batch rides exactly one response
        $this->assertSame([], $this->instance->pull()['messages']);
    }

    /* session round-trip (traditional) */

    public function testKeepPersistsToSession(): void
    {
        $this->instance->msg('For the next page.', 'success')->keep();

        $stored = $this->session->data[self::SESSION_KEY];

        $this->assertSame('For the next page.', array_values($stored)[0]['msg']);
    }

    public function testConstructionDrainsTheSession(): void
    {
        $this->instance->msg('Across the redirect.', 'success')->keep();

        // "next request": a fresh instance over the same session
        $next = $this->make();

        $this->assertSame('Across the redirect.', $next->getMessages()[0]['msg']);

        // and the session copy was consumed
        $this->assertArrayNotHasKey(self::SESSION_KEY, $this->session->data);
    }

    public function testKeepWithoutSessionThrows(): void
    {
        $flash = Flashmsg::newInstance([], null, $this->input, $this->output);

        $flash->msg('No session here.');

        $this->expectException(InvalidValue::class);

        $flash->keep();
    }

    public function testStatelessConstructionWorks(): void
    {
        // no session, no data, no event - the SPA-minimum wiring
        $flash = Flashmsg::newInstance([], null, $this->input, $this->output);

        $flash->msg('Stateless.', 'success');

        $this->assertSame('Stateless.', $flash->pull()['messages'][0]['msg']);
    }

    /* redirects */

    public function testRedirectUrl(): void
    {
        $this->instance->msg('Problem!', 'danger')->redirect('/go/here');

        $this->assertContains('Location: /go/here', $this->output->getHeaders());

        // messages went to the session for the next request
        $this->assertNotEmpty($this->session->data[self::SESSION_KEY]);
    }

    public function testRedirectRefererToken(): void
    {
        $this->instance->msg('Problem!', 'danger')->redirect('@');

        $this->assertContains('Location: ' . self::REFERER, $this->output->getHeaders());
    }

    public function testRedirectDefaultsToReferer(): void
    {
        $this->instance->msg('Problem!', 'danger')->redirect();

        $this->assertContains('Location: ' . self::REFERER, $this->output->getHeaders());
    }

    /* events */

    public function testEventFiresPerMessage(): void
    {
        $captured = [];

        $event = Event::newInstance([]);
        $event->register('flash.msg', function ($msg, $type, $sticky) use (&$captured) {
            $captured[] = [$msg, $type, $sticky];
        });

        $flash = Flashmsg::newInstance([], $this->session, $this->input, $this->output, null, $event);

        $flash->msg('Heads up.', 'red');

        $this->assertSame([['Heads up.', 'danger', true]], $captured);
    }
}
