<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\WebSocket;

/**
 * Represents a decoded WebSocket message.
 */
class Message
{
    public readonly EventType $type;
    public readonly string $raw;
    public readonly ?array $alert;
    public readonly ?array $social;
    public readonly ?array $alertConfirm;
    public readonly ?array $socialConfirm;
    public readonly ?string $error;

    public function __construct(
        EventType $type,
        string $raw,
        ?array $alert = null,
        ?array $social = null,
        ?array $alertConfirm = null,
        ?array $socialConfirm = null,
        ?string $error = null,
    ) {
        $this->type = $type;
        $this->raw = $raw;
        $this->alert = $alert;
        $this->social = $social;
        $this->alertConfirm = $alertConfirm;
        $this->socialConfirm = $socialConfirm;
        $this->error = $error;
    }

    /**
     * Decodes a raw JSON string into a typed Message.
     */
    public static function decode(string $data): self
    {
        $parsed = json_decode($data, true);
        if (!is_array($parsed)) {
            return new self(EventType::Unknown, $data);
        }

        if (!empty($parsed['error'])) {
            return new self(EventType::Error, $data, error: $parsed['error']);
        }

        $type = $parsed['type'] ?? '';

        if ($type === 'subscribed_alerts') {
            return new self(EventType::SubscribedAlerts, $data, alertConfirm: $parsed);
        }
        if ($type === 'subscribed_socials') {
            return new self(EventType::SubscribedSocials, $data, socialConfirm: $parsed);
        }

        // Alert and social events don't have a "type" field.
        // Identify by content: alerts have amounts, socials have urls.
        $channelId = $parsed['channel_id'] ?? '';
        if ($channelId !== '') {
            if (isset($parsed['amounts'])) {
                return new self(EventType::Alert, $data, alert: $parsed);
            }
            if (isset($parsed['urls'])) {
                return new self(EventType::Social, $data, social: $parsed);
            }
            if (isset($parsed['blockchain'])) {
                return new self(EventType::Alert, $data, alert: $parsed);
            }
        }

        return new self(EventType::Unknown, $data);
    }
}
