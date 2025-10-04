<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Debug;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use function spl_object_id;

/**
 * @internal
 */
final class MessageProcessingRequest extends Request
{
    private ?bool $ack = null;
    private bool $willRetry = false;
    private ?\Throwable $throwable = null;
    /** @var array<string, mixed>|null */
    private ?array $messageProfile = null;

    public function __construct(
        public readonly Envelope $envelope,
        public readonly string $transport,
    ) {
        $message = $envelope->getMessage();
        $messageHash = spl_object_id($message);

        parent::__construct(
            attributes: [
                '_controller' => $message::class,
                '_virtual_type' => 'messenger',
                'messenger.transport' => $transport,
                'messenger.envelope' => $envelope,
                'messenger.message' => $message,
                'messenger.message_hash' => $messageHash,
            ],
            server: $_SERVER,
        );
    }

    public function complete(bool $ack, bool $willRetry, ?\Throwable $throwable = null, ?array $messageProfile = null): void
    {
        $this->ack = $ack;
        $this->willRetry = $willRetry;
        $this->throwable = $throwable;
        $this->messageProfile = $messageProfile;

        $this->attributes->set('messenger.ack', $ack);
        $this->attributes->set('messenger.retry', $willRetry);
        $this->attributes->set('messenger.throwable', $throwable);

        if ($messageProfile) {
            $this->attributes->set('messenger.profile', $messageProfile);

            foreach ($messageProfile as $key => $value) {
                $this->attributes->set('messenger.profile.'.$key, $value);
            }
        }
    }

    public function getUri(): string
    {
        return sprintf('%s via %s', $this->envelope->getMessage()::class, $this->transport);
    }

    public function getMethod(): string
    {
        return 'MESSAGE';
    }

    public function getResponse(): Response
    {
        $ack = $this->ack;
        $willRetry = $this->willRetry;

        return new class($ack, $willRetry) extends Response {
            public function __construct(private readonly ?bool $ack, private readonly bool $willRetry)
            {
                parent::__construct();
            }

            public function getStatusCode(): int
            {
                if (null === $this->ack) {
                    return Response::HTTP_PROCESSING;
                }

                if ($this->ack) {
                    return Response::HTTP_OK;
                }

                if ($this->willRetry) {
                    return Response::HTTP_SERVICE_UNAVAILABLE;
                }

                return Response::HTTP_INTERNAL_SERVER_ERROR;
            }
        };
    }

    public function getClientIp(): string
    {
        return 'messenger://'.$this->transport;
    }

    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessageProfile(): ?array
    {
        return $this->messageProfile;
    }
}
