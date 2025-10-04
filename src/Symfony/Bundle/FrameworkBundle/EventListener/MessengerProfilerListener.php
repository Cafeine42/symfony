<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Debug\VirtualRequestStack;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Messenger\Debug\MessageProcessingRequest;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\MessageProfilerListener;

use function spl_object_id;

/**
 * @internal
 */
final class MessengerProfilerListener implements EventSubscriberInterface
{
    /** @var array<int, MessageProcessingRequest> */
    private array $requests = [];
    /** @var \SplObjectStorage<Request, \Symfony\Component\HttpKernel\Profiler\Profile> */
    private \SplObjectStorage $profiles;
    /** @var \SplObjectStorage<Request, ?Request> */
    private \SplObjectStorage $parents;
    /** @var array<int, Profile> */
    private array $savedProfiles = [];

    public function __construct(
        private readonly Profiler $profiler,
        private readonly VirtualRequestStack $requestStack,
        private readonly MessageProfilerListener $messageProfiler,
    ) {
        $this->profiles = new \SplObjectStorage();
        $this->parents = new \SplObjectStorage();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => ['initialize', -4096],
            WorkerMessageHandledEvent::class => ['onMessageHandled', -4096],
            WorkerMessageFailedEvent::class => ['onMessageFailed', -4096],
        ];
    }

    public function initialize(WorkerMessageReceivedEvent $event): void
    {
        if (!$event->shouldHandle() || !$this->profiler->isEnabled()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        $hash = spl_object_id($message);

        if (isset($this->requests[$hash])) {
            return;
        }

        $parentRequest = $this->requestStack->getCurrentRequest();
        $request = new MessageProcessingRequest($event->getEnvelope(), $event->getReceiverName());

        if (null !== $sectionId = $this->messageProfiler->getSectionId($message)) {
            $request->attributes->set('_stopwatch_token', $sectionId);
        }

        $this->requests[$hash] = $request;
        $this->parents[$request] = $parentRequest;
        $this->requestStack->push($request);
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->collect($event->getEnvelope()->getMessage(), true, false, null);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->collect($event->getEnvelope()->getMessage(), false, $event->willRetry(), $event->getThrowable());
    }

    private function collect(object $message, bool $ack, bool $willRetry, ?\Throwable $error): void
    {
        $hash = spl_object_id($message);

        if (!isset($this->requests[$hash])) {
            return;
        }

        $request = $this->requests[$hash];
        unset($this->requests[$hash]);

        if ($this->requestStack->getCurrentRequest() === $request) {
            $this->requestStack->pop();
        }

        $sectionId = $request->attributes->get('_stopwatch_token');
        $messageProfile = null !== $sectionId ? $this->messageProfiler->getProfile($sectionId) : null;
        $request->complete($ack, $willRetry, $error, $messageProfile);

        if (!$this->profiler->isEnabled()) {
            return;
        }

        $profile = $this->profiler->collect($request, $request->getResponse(), $error);
        $this->profiles[$request] = $profile;

        if ($parentRequest = $this->parents[$request]) {
            return;
        }

        foreach ($this->profiles as $r) {
            if (null !== $parent = $this->parents[$r]) {
                if (isset($this->profiles[$parent])) {
                    $this->profiles[$parent]->addChild($this->profiles[$r]);
                }
            }
        }

        foreach ($this->profiles as $r) {
            $profile = $this->profiles[$r];
            $this->profiler->saveProfile($profile);
            $this->savedProfiles[] = $profile;
        }

        $this->profiles = new \SplObjectStorage();
        $this->parents = new \SplObjectStorage();
    }

    /**
     * @return Profile[]
     */
    public function consumeProfiles(): array
    {
        $profiles = $this->savedProfiles;
        $this->savedProfiles = [];

        return $profiles;
    }
}
