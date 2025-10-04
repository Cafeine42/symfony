<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Messenger;

use Symfony\Bundle\FrameworkBundle\EventListener\MessengerProfilerListener;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\MessageProfilerListener;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @internal
 */
final class ConsumeMessagesCommandProfiler
{
    public function __construct(
        private readonly MessageProfilerListener $messageProfiler,
        private readonly MessengerProfilerListener $messengerProfiler,
        private readonly Profiler $profiler,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    /**
     * @return array{worker: Worker, onStop?: callable}
     */
    public function __invoke(Worker $worker, EventDispatcherInterface $eventDispatcher, InputInterface $input, OutputInterface $output, SymfonyStyle $io): array
    {
        $this->profiler->enable();

        $eventDispatcher->addSubscriber($this->messageProfiler);
        $eventDispatcher->addSubscriber($this->messengerProfiler);

        $io->comment('Profiling enabled: a profiler entry will be created for each handled message.');

        $printProfiles = $this->createPrinter($output);

        $listener = static function () use ($printProfiles) {
            $printProfiles();
        };

        $eventDispatcher->addListener(WorkerMessageHandledEvent::class, $listener, -8192);
        $eventDispatcher->addListener(WorkerMessageFailedEvent::class, $listener, -8192);

        return [
            'worker' => $worker,
            'onStop' => static function () use ($printProfiles) {
                $printProfiles();
            },
        ];
    }

    private function createPrinter(OutputInterface $output): callable
    {
        $output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        return function () use ($output) {
            $profiles = $this->messengerProfiler->consumeProfiles();

            if (!$profiles) {
                return;
            }

            foreach ($profiles as $profile) {
                $token = $profile->getToken();

                if ($this->urlGenerator) {
                    $url = $this->urlGenerator->generate('_profiler', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
                    $output->writeln(sprintf('See messenger profile <href=%s>%s</>', $url, $token));
                } else {
                    $output->writeln(sprintf('See messenger profile %s', $token));
                }
            }
        };
    }
}
