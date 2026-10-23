<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Event;

interface EventDispatcherInterface
{
    /**
     * @param callable(array<string, mixed>): void $listener
     */
    public function listen(string $event, callable $listener, FailurePolicy $policy = FailurePolicy::Isolated): void;

    /**
     * Dispatches an event.
     *
     * @param array<string, mixed> $payload Read-only data; listeners cannot alter backup state through it.
     * @throws ListenerVetoException when a Blocking listener of a vetoable event threw.
     */
    public function dispatch(string $event, array $payload = []): void;
}
