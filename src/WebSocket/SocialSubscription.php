<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\WebSocket;

/**
 * Builds a subscribe_socials message.
 */
class SocialSubscription
{
    public const TYPE = 'subscribe_socials';

    private ?string $id;

    public function __construct(?string $id = null)
    {
        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Returns the JSON-encoded subscription message.
     */
    public function toJson(): string
    {
        $payload = ['type' => self::TYPE];
        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
