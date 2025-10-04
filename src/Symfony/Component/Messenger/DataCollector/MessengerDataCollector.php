<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\VarDumper\Caster\ClassStub;

/**
 * @author Samuel Roze <samuel.roze@gmail.com>
 *
 * @final
 */
class MessengerDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private array $traceableBuses = [];
    /**
     * @var array<int, array{message: object, transport: ?string, ack: ?bool, retry: bool, throwable: ?\Throwable, profile: array<string, mixed>}> 
     */
    private array $processedMessages = [];

    public function registerBus(string $name, TraceableMessageBus $bus): void
    {
        $this->traceableBuses[$name] = $bus;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        if ('messenger' === $request->attributes->get('_virtual_type') && $request->attributes->has('messenger.message')) {
            $message = $request->attributes->get('messenger.message');

            if (!\is_object($message)) {
                return;
            }

            $this->processedMessages[] = [
                'message' => $message,
                'transport' => $request->attributes->get('messenger.transport'),
                'ack' => $request->attributes->get('messenger.ack'),
                'retry' => (bool) $request->attributes->get('messenger.retry'),
                'throwable' => $request->attributes->get('messenger.throwable'),
                'profile' => $request->attributes->get('messenger.profile') ?? [],
            ];
        }
    }

    public function lateCollect(): void
    {
        $processedMessages = array_map($this->createProcessedMessage(...), $this->processedMessages);

        $this->data = [
            'messages' => [],
            'buses' => array_keys($this->traceableBuses),
            'processed_messages' => $processedMessages,
        ];

        $messages = [];
        foreach ($this->traceableBuses as $busName => $bus) {
            foreach ($bus->getDispatchedMessages() as $message) {
                $debugRepresentation = $this->cloneVar($this->collectMessage($busName, $message));
                $messages[] = [$debugRepresentation, $message['callTime']];
            }
        }

        // Order by call time
        usort($messages, fn ($a, $b) => $a[1] <=> $b[1]);

        // Keep the messages clones only
        $this->data['messages'] = array_column($messages, 0);
    }

    public function getName(): string
    {
        return 'messenger';
    }

    public function reset(): void
    {
        $this->data = [];
        foreach ($this->traceableBuses as $traceableBus) {
            $traceableBus->reset();
        }

        $this->processedMessages = [];
    }

    protected function getCasters(): array
    {
        $casters = parent::getCasters();

        // Unset the default caster truncating collectors data.
        unset($casters['*']);

        return $casters;
    }

    private function collectMessage(string $busName, array $tracedMessage): array
    {
        $message = $tracedMessage['message'];

        $debugRepresentation = [
            'bus' => $busName,
            'stamps' => $tracedMessage['stamps'] ?? null,
            'stamps_after_dispatch' => $tracedMessage['stamps_after_dispatch'] ?? null,
            'message' => [
                'type' => new ClassStub($message::class),
                'value' => $message,
            ],
            'caller' => $tracedMessage['caller'],
        ];

        if (isset($tracedMessage['exception'])) {
            $exception = $tracedMessage['exception'];

            $debugRepresentation['exception'] = [
                'type' => $exception::class,
                'value' => $exception,
            ];
        }

        return $debugRepresentation;
    }

    public function getExceptionsCount(?string $bus = null): int
    {
        $count = 0;
        foreach ($this->getMessages($bus) as $message) {
            $count += (int) isset($message['exception']);
        }

        return $count;
    }

    public function getMessages(?string $bus = null): array
    {
        if (null === $bus) {
            return $this->data['messages'];
        }

        return array_filter($this->data['messages'], fn ($message) => $bus === $message['bus']);
    }

    public function getProcessedMessages(?bool $ack = null): array
    {
        $messages = $this->data['processed_messages'] ?? [];

        if (null === $ack) {
            return $messages;
        }

        return array_values(array_filter($messages, static function (array $message) use ($ack): bool {
            return (bool) $message['ack'] === $ack;
        }));
    }

    public function getBuses(): array
    {
        return $this->data['buses'];
    }

    private function createProcessedMessage(array $processed): array
    {
        $message = $processed['message'];
        $profile = $processed['profile'];

        $timeline = [];
        foreach ($profile['events'] ?? [] as $event) {
            $timeline[] = [
                'name' => $event['name'],
                'category' => $event['category'],
                'origin' => $event['origin'],
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'duration' => $event['duration'],
                'memory' => $event['memory'],
            ];
        }

        $data = [
            'message' => [
                'type' => new ClassStub($message::class),
                'value' => $this->cloneVar($message),
            ],
            'transport' => $processed['transport'],
            'ack' => $processed['ack'],
            'retry' => $processed['retry'],
            'duration' => $profile['duration'] ?? null,
            'memory' => $profile['memory'] ?? null,
            'origin' => $profile['origin'] ?? null,
            'start_time' => $profile['start_time'] ?? null,
            'end_time' => $profile['end_time'] ?? null,
            'section' => $profile['section'] ?? null,
            'message_hash' => $profile['message_hash'] ?? null,
            'timeline' => $timeline,
        ];

        if ($throwable = $processed['throwable']) {
            $data['exception'] = [
                'type' => $throwable::class,
                'value' => $this->cloneVar($throwable),
            ];
        }

        $data['status'] = $processed['ack'] ? 'ack' : ($processed['retry'] ? 'retry' : 'failed');

        return $data;
    }
}
