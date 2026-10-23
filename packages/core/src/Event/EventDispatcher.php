<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Event;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SWBackupIt\Core\Exception\ConfigurationException;
use Throwable;

/**
 * Synchronous dispatcher with explicit failure policy per listener.
 *
 * - Isolated listeners can never break a backup: exceptions are logged and swallowed.
 * - Blocking listeners are only accepted for vetoable (before_*) events; their exception
 *   is converted into a ListenerVetoException which the engine treats as a clean abort
 *   *before* any state was changed.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, list<array{callable, FailurePolicy}>> */
    private array $listeners = [];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public function listen(string $event, callable $listener, FailurePolicy $policy = FailurePolicy::Isolated): void
    {
        if ($policy === FailurePolicy::Blocking && !in_array($event, Events::VETOABLE, true)) {
            throw new ConfigurationException('Only before_* events may use the blocking failure policy.', ['event' => $event]);
        }
        $this->listeners[$event][] = [$listener, $policy];
    }

    public function dispatch(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as [$listener, $policy]) {
            try {
                $listener($payload);
            } catch (Throwable $throwable) {
                if ($policy === FailurePolicy::Blocking) {
                    throw new ListenerVetoException('A listener vetoed the operation.', ['event' => $event], false, $throwable);
                }
                $this->logger->warning('Event listener failed and was ignored.', [
                    'event' => $event,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }
    }
}
