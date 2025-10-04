<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

use function spl_object_id;

/**
 * @internal
 */
final class MessageProfilerListener implements EventSubscriberInterface, ResetInterface
{
    private const STOPWATCH_EVENT = 'messenger.message';

    /**
     * @var array<int, array{section: string, transport: string, retry: bool, ack: bool|null}>
     */
    private array $messages = [];

    /**
     * @var array<string, array{
     *     section: string,
     *     transport: string,
     *     retry: bool,
     *     ack: bool,
     *     message_hash: int,
     *     duration?: int|float|null,
     *     memory?: int|null,
     *     origin?: int|float|null,
     *     start_time?: int|float|null,
     *     end_time?: int|float|null,
     *     events?: array<int, array{
     *         name: string,
     *         category: string,
     *         origin: int|float,
     *         start_time: int|float,
     *         end_time: int|float,
     *         duration: int|float,
     *         memory: int,
     *     }>,
     * }>
     */
    private array $profiles = [];

    public function __construct(
        private readonly Stopwatch $stopwatch,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['onMessageReceived', -2048],
            WorkerMessageHandledEvent::class => ['onMessageHandled', -2048],
            WorkerMessageFailedEvent::class => ['onMessageFailed', -2048],
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$event->shouldHandle()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        $messageHash = spl_object_id($message);

        if (isset($this->messages[$messageHash])) {
            return;
        }

        $sectionId = $this->createSectionId($messageHash);

        $this->stopwatch->openSection($sectionId);
        $this->stopwatch->start(self::STOPWATCH_EVENT, 'messenger');

        $this->messages[$messageHash] = [
            'section' => $sectionId,
            'transport' => $event->getReceiverName(),
            'retry' => false,
            'ack' => null,
        ];
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->complete($event->getEnvelope()->getMessage(), true, false);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->complete($event->getEnvelope()->getMessage(), false, $event->willRetry());
    }

    public function getSectionId(object $message): ?string
    {
        return $this->messages[spl_object_id($message)]['section'] ?? null;
    }

    /**
     * @return array<string, array{section: string, transport: string, retry: bool, ack: bool, message_hash: int}>
     */
    public function getProfiles(): array
    {
        return $this->profiles;
    }

    public function getProfile(string $sectionId): ?array
    {
        return $this->profiles[$sectionId] ?? null;
    }

    public function reset(): void
    {
        foreach ($this->messages as $messageHash => $message) {
            if ($this->stopwatch->isStarted(self::STOPWATCH_EVENT)) {
                try {
                    $this->stopwatch->stop(self::STOPWATCH_EVENT);
                } catch (\LogicException) {
                    // ignore if the event was not started
                }
            }

            try {
                $this->stopwatch->stopSection($message['section']);
            } catch (\LogicException) {
                // ignore if the section was already stopped
            }
        }

        $this->messages = [];
        $this->profiles = [];
    }

    private function complete(object $message, bool $ack, bool $retry): void
    {
        $messageHash = spl_object_id($message);

        if (!isset($this->messages[$messageHash])) {
            return;
        }

        $current = $this->messages[$messageHash];
        unset($this->messages[$messageHash]);

        $event = null;
        if ($this->stopwatch->isStarted(self::STOPWATCH_EVENT)) {
            try {
                $event = $this->stopwatch->stop(self::STOPWATCH_EVENT);
            } catch (\LogicException) {
                // ignore if the event was already stopped
            }
        }

        try {
            $this->stopwatch->stopSection($current['section']);
        } catch (\LogicException) {
            // ignore if the section was already stopped
        }

        $sectionEvents = $this->stopwatch->getSectionEvents($current['section']);
        $primaryEvent = $sectionEvents[self::STOPWATCH_EVENT] ?? $event;

        $events = [];
        foreach ($sectionEvents as $name => $sectionEvent) {
            if ('section' === $sectionEvent->getCategory()) {
                continue;
            }

            $events[] = [
                'name' => $name,
                'category' => $sectionEvent->getCategory(),
                'origin' => $sectionEvent->getOrigin(),
                'start_time' => $sectionEvent->getStartTime(),
                'end_time' => $sectionEvent->getEndTime(),
                'duration' => $sectionEvent->getDuration(),
                'memory' => $sectionEvent->getMemory(),
            ];
        }

        $this->profiles[$current['section']] = [
            'section' => $current['section'],
            'transport' => $current['transport'],
            'retry' => $retry,
            'ack' => $ack,
            'message_hash' => $messageHash,
            'duration' => $primaryEvent?->getDuration(),
            'memory' => $primaryEvent?->getMemory(),
            'origin' => $primaryEvent?->getOrigin(),
            'start_time' => $primaryEvent?->getStartTime(),
            'end_time' => $primaryEvent?->getEndTime(),
            'events' => $events,
        ];
    }

    private function createSectionId(int $messageHash): string
    {
        return 'messenger.'.$messageHash;
    }
}
