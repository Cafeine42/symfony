<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Bundle\FrameworkBundle\EventListener\MessengerProfilerListener;
use Symfony\Bundle\FrameworkBundle\Messenger\ConsumeMessagesCommandProfiler;
use Symfony\Component\Messenger\DataCollector\MessengerDataCollector;
use Symfony\Component\Messenger\EventListener\MessageProfilerListener;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('data_collector.messenger', MessengerDataCollector::class)
            ->tag('data_collector', [
                'template' => '@WebProfiler/Collector/messenger.html.twig',
                'id' => 'messenger',
                'priority' => 100,
            ])
        ->set('messenger.listener.message_profiler', MessageProfilerListener::class)
            ->args([
                service('debug.stopwatch'),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])

        ->set('messenger.listener.messenger_profiler', MessengerProfilerListener::class)
            ->args([
                service('.lazy_profiler'),
                service('.virtual_request_stack'),
                service('messenger.listener.message_profiler'),
            ])

        ->set('messenger.command.consume_messages_profiler', ConsumeMessagesCommandProfiler::class)
            ->args([
                service('messenger.listener.message_profiler'),
                service('messenger.listener.messenger_profiler'),
                service('.lazy_profiler'),
                service('router')->nullOnInvalid(),
            ])
    ;
};
