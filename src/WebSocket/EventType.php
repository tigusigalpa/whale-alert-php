<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\WebSocket;

/**
 * Event type constants for WebSocket messages.
 */
enum EventType: string
{
    case SubscribedAlerts = 'subscribed_alerts';
    case SubscribedSocials = 'subscribed_socials';
    case Alert = 'alert';
    case Social = 'social';
    case Error = 'error';
    case Unknown = 'unknown';
}
