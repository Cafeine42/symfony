<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\MessageProfilerListener;
use Symfony\Component\Messenger\Tests\Fixtures\DummyMessage;
use Symfony\Component\Stopwatch\Stopwatch;

use function spl_object_id;

class MessageProfilerListenerTest extends TestCase
{
    public function testProfilesHandledMessage(): void
    {
        $stopwatch = new Stopwatch(true);
        $listener = new MessageProfilerListener($stopwatch);

        $message = new DummyMessage('body');
        $envelope = new Envelope($message);

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $sectionId = $listener->getSectionId($message);
        $this->assertNotNull($sectionId);

        $listener->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $profiles = $listener->getProfiles();
        $this->assertArrayHasKey($sectionId, $profiles);
        $profile = $profiles[$sectionId];
        $this->assertSame($sectionId, $profile['section']);
        $this->assertSame('async', $profile['transport']);
        $this->assertTrue($profile['ack']);
        $this->assertFalse($profile['retry']);
        $this->assertSame(spl_object_id($message), $profile['message_hash']);
        $this->assertIsArray($profile['events']);
        $this->assertNotEmpty($profile['events']);
        $this->assertArrayHasKey('duration', $profile);
        $this->assertArrayHasKey('memory', $profile);

        $events = $stopwatch->getSectionEvents($sectionId);
        $this->assertArrayHasKey('messenger.message', $events);
        $this->assertGreaterThanOrEqual(0, $events['messenger.message']->getDuration());
        $this->assertSame($events['messenger.message']->getDuration(), $profile['duration']);
    }

    public function testProfilesFailedMessageWithRetry(): void
    {
        $stopwatch = new Stopwatch(true);
        $listener = new MessageProfilerListener($stopwatch);

        $message = new DummyMessage('body');
        $envelope = new Envelope($message);

        $listener->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'failed'));

        $failedEvent = new WorkerMessageFailedEvent($envelope, 'failed', new \RuntimeException('Boom'));
        $failedEvent->setForRetry();

        $listener->onMessageFailed($failedEvent);

        $profiles = $listener->getProfiles();
        $this->assertCount(1, $profiles);
        $profile = reset($profiles);
        $this->assertFalse($profile['ack']);
        $this->assertTrue($profile['retry']);
        $this->assertSame('failed', $profile['transport']);
    }

    public function testDoesNotStartSectionWhenMessageShouldNotBeHandled(): void
    {
        $stopwatch = new Stopwatch(true);
        $listener = new MessageProfilerListener($stopwatch);

        $message = new DummyMessage('body');
        $envelope = new Envelope($message);
        $event = new WorkerMessageReceivedEvent($envelope, 'async');
        $event->shouldHandle(false);

        $listener->onMessageReceived($event);

        $this->assertNull($listener->getSectionId($message));
        $this->assertSame([], $listener->getProfiles());
    }
}
