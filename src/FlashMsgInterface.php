<?php

declare(strict_types=1);

namespace orange\flashmsg;

interface FlashMsgInterface
{
    // semantic types are canonical; legacy color names (red, yellow, blue,
    // green) resolve through the configured 'type aliases' map
    public const DANGER = 'danger';
    public const WARN = 'warning';
    public const INFO = 'info';
    public const SUCCESS = 'success';

    public function msg(string $msg, ?string $type = null): self;
    public function msgs(array $array, ?string $type = null): self;

    /* traditional (PRG) delivery */
    public function redirect(?string $redirect = null): void;
    public function keep(): self;

    /* SPA / JSON delivery */
    public function pull(bool $detailed = true): array;

    /* inspection */
    public function getMessages(bool $detailed = false): array;
    public function hasMessages(): bool;
    public function count(): int;
    public function clear(): self;

    public function __debugInfo(): array;
}
