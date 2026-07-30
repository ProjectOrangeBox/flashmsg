<?php

declare(strict_types=1);

namespace orange\flashmsg;

use Countable;
use JsonSerializable;
use orange\framework\base\Singleton;
use orange\session\SessionInterface;
use orange\framework\exceptions\InvalidValue;
use orange\framework\interfaces\DataInterface;
use orange\framework\interfaces\EventInterface;
use orange\framework\traits\ConfigurationTrait;
use orange\framework\interfaces\InputInterface;
use orange\framework\interfaces\OutputInterface;

/**
 * One-request flash messages (success/error/info banners) with two delivery
 * modes sharing one queue:
 *
 * - traditional (PRG): msg() ... redirect() persists the queue in the session;
 *   the next request's construction drains it back out and exposes it to the
 *   view via the data service.
 * - SPA / JSON API: msg() ... then embed pull() (or the object itself - it is
 *   JsonSerializable) in the response body; pull() clears the queue so the
 *   next response doesn't repeat it. Messages left in the session by a
 *   redirect into the SPA are drained on construction and ride out on the
 *   first response the same way.
 *
 * The session service is optional - a stateless JSON API can run without
 * one; only redirect()/keep() (cross-request delivery) require it.
 */
class Flashmsg extends Singleton implements FlashMsgInterface, JsonSerializable, Countable
{
    use ConfigurationTrait;

    protected array $config = [];
    /** @var array<string, array{type: string, msg: string, sticky: bool}> keyed by a content hash, so re-adding is idempotent */
    protected array $messages = [];

    protected string $sessionMsgKey;
    protected string $defaultType;
    /** @var list<string> types that survive a page view */
    protected array $stickyTypes;
    /** @var array<string, string> alias => canonical type */
    protected array $typeAliases;
    protected string $httpReferer;

    /**
     * @param array $config Configuration overrides (merged over src/config/flashmsg.php)
     * @param ?SessionInterface $session Session service - required only for redirect()/keep()
     * @param InputInterface $input Input service - supplies the HTTP referer
     * @param OutputInterface $output Output service - performs redirect()
     * @param ?DataInterface $data Data service - when present, the queue mirrors into the configured view variable
     * @param ?EventInterface $event Event service - when present, 'flash.msg' fires per added message
     */
    /**
     * @param array<string, mixed> $config
     */
    protected function __construct(array $config, protected ?SessionInterface $session, protected InputInterface $input, protected OutputInterface $output, protected ?DataInterface $data = null, protected ?EventInterface $event = null)
    {
        $this->config = $this->mergeConfigWith($config);

        $this->sessionMsgKey = $this->config['session msg key'];
        $this->defaultType = $this->config['default type'];
        $this->stickyTypes = $this->config['sticky types'];
        $this->typeAliases = $this->config['type aliases'] ?? [];

        // used by redirect('@'); the input service normalizes HTTP_REFERER
        // to the key "referer" - the config key is the fallback/override
        $this->httpReferer = (string)$this->input->server('referer', $this->config['http referer'] ?? '');

        /* are there any messages in cold storage? */
        $previousMessages = $this->session?->get($this->sessionMsgKey);

        if (is_array($previousMessages)) {
            $this->messages = $previousMessages;
            $this->session->remove($this->sessionMsgKey);
        }

        /* set the view variable for this page */
        $this->refreshViewDataVariable();
    }

    /**
     * add a flash msg
     *
     * legacy color types (red, yellow, ...) resolve through the configured
     * 'type aliases' map to their semantic names (danger, warning, ...) so
     * the stored type is always canonical
     */
    public function msg(string $msg, ?string $type = null): self
    {
        $type ??= $this->defaultType;

        /* resolve legacy color names to semantic types */
        $type = $this->typeAliases[$type] ?? $type;

        /* is this type sticky? */
        $sticky = in_array($type, $this->stickyTypes, true);

        /* trigger an event in case they need to do something */
        $this->event?->trigger('flash.msg', $msg, $type, $sticky);

        // keyed by content hash - adding the same message twice is idempotent
        $this->messages[sha1($type . $msg)] = [
            'type' => $type,
            'msg' => trim((string) $msg),
            'sticky' => $sticky,
        ];

        /* put in view variable in case they want to use it on this page */
        return $this->refreshViewDataVariable();
    }

    /**
     * add multiple flash msgs
     *
     * two forms: a list of messages (one shared type), or message => type
     * pairs. Note the pair form cannot carry a purely numeric message -
     * PHP casts the array key to int and it reads as the list form.
     */
    /**
     * @param array<array-key, string> $array
     */
    public function msgs(array $array, ?string $type = null): self
    {
        $type ??= $this->defaultType;

        foreach ($array as $a => $b) {
            if (is_numeric($a)) {
                $this->msg($b, $type);
            } else {
                $this->msg($a, $b);
            }
        }

        return $this;
    }

    /**
     * traditional (PRG) delivery: persist the queue in the session and
     * redirect - the next request's construction drains it back out.
     *
     * '@' (or null) redirects to the HTTP referer.
     */
    public function redirect(?string $redirect = null): void
    {
        // store the queue for the request we are redirecting to
        $this->keep();

        $redirect ??= '@';

        if ($redirect === '@') {
            $redirect = $this->httpReferer;
        }

        $this->output->redirect($redirect);
    }

    /**
     * persist the queue in the session for the NEXT request without
     * redirecting - for handlers that respond normally but want delivery
     * to happen on the following page load / API call.
     */
    public function keep(): self
    {
        if ($this->session === null) {
            throw new InvalidValue('Flashmsg requires a session service to carry messages across requests - construct it with one to use keep()/redirect().');
        }

        $this->session->set($this->sessionMsgKey, $this->messages);

        return $this;
    }

    /**
     * return all of the messages
     *
     * detailed adds the display metadata (count and pause timings) the JS
     * layer uses; plain is just the message list
     */
    /**
     * @return list<array{type: string, msg: string, sticky: bool}>|array{messages: list<array{type: string, msg: string, sticky: bool}>, count: int, initial_pause: mixed, pause_for_each: mixed}
     */
    public function getMessages(bool $detailed = false): array
    {
        $messages = array_values($this->messages);

        return ($detailed) ? [
            'messages' => $messages,
            'count' => count($this->messages),
            'initial_pause' => $this->config['initial pause'],
            'pause_for_each' => $this->config['pause for each'],
        ] : $messages;
    }

    /**
     * SPA / JSON delivery: return the messages AND clear the queue, so the
     * batch rides exactly one response
     */
    /**
     * @return list<array{type: string, msg: string, sticky: bool}>|array{messages: list<array{type: string, msg: string, sticky: bool}>, count: int, initial_pause: mixed, pause_for_each: mixed}
     */
    public function pull(bool $detailed = true): array
    {
        $messages = $this->getMessages($detailed);

        $this->clear();

        return $messages;
    }

    /**
     * empty the queue (and the mirrored view variable)
     */
    public function clear(): self
    {
        $this->messages = [];

        return $this->refreshViewDataVariable();
    }

    /**
     * are there messages queued?
     */
    public function hasMessages(): bool
    {
        return $this->messages !== [];
    }

    /**
     * number of queued messages (Countable)
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * json_encode() of the service (or embedding it in a response payload)
     * emits the detailed shape the JS layer consumes
     */
    /**
     * @return list<array{type: string, msg: string, sticky: bool}>|array{messages: list<array{type: string, msg: string, sticky: bool}>, count: int, initial_pause: mixed, pause_for_each: mixed}
     */
    public function jsonSerialize(): array
    {
        return $this->getMessages(true);
    }

    /* mirror the queue into the data service (for views) when one was provided */
    protected function refreshViewDataVariable(): self
    {
        if ($this->data) {
            $this->data[$this->config['view variable']] = $this->getMessages(true);
        }

        return $this;
    }

    public function __debugInfo(): array
    {
        return [
            'config' => $this->config,
            'messages' => $this->messages,
        ];
    }
}
