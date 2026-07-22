<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\WebSocket;

use Tigusigalpa\WhaleAlert\Exceptions\WebSocketConnectionException;
use Tigusigalpa\WhaleAlert\Exceptions\WebSocketSubscriptionException;

/**
 * WebSocket client for the Whale Alert alerts API.
 *
 * Uses PHP's stream socket API for WebSocket communication.
 * Requires the `ext-sockets` extension.
 */
class Client
{
    private const DEFAULT_TIMEOUT = 30;
    private const READ_TIMEOUT_SEC = 60;
    private const READ_TIMEOUT_USEC = 0;

    private string $url;
    private int $timeout;
    private int $maxReconnects;
    private int $reconnectDelayMs;
    private int $reconnectMaxDelayMs;

    private mixed $socket = null;
    private bool $connected = false;
    private ?string $subscriptionId = null;
    private ?AlertSubscription $lastAlertSub = null;
    private ?SocialSubscription $lastSocialSub = null;
    private int $reconnectAttempts = 0;

    /** @var callable|null */
    private $messageHandler = null;

    /** @var callable|null */
    private $errorHandler = null;

    public function __construct(
        string $url,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxReconnects = 0,
        int $reconnectDelayMs = 1000,
        int $reconnectMaxDelayMs = 30000,
    ) {
        $this->url = $url;
        $this->timeout = $timeout;
        $this->maxReconnects = $maxReconnects;
        $this->reconnectDelayMs = $reconnectDelayMs;
        $this->reconnectMaxDelayMs = $reconnectMaxDelayMs;
    }

    /**
     * Establishes a WebSocket connection.
     *
     * @throws WebSocketConnectionException
     */
    public function connect(): void
    {
        if ($this->connected) {
            throw new WebSocketConnectionException('Already connected.');
        }

        $parsed = parse_url($this->url);
        if ($parsed === false) {
            throw new WebSocketConnectionException('Invalid WebSocket URL.');
        }

        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $useTls = $scheme === 'wss';

        $remote = $useTls
            ? 'ssl://' . $host . ':' . $port
            : $host . ':' . $port;

        $context = stream_context_create($useTls ? [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ] : []);

        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new WebSocketConnectionException(
                'Failed to connect: ' . $errstr . ' (' . $errno . ')'
            );
        }

        stream_set_timeout($socket, self::READ_TIMEOUT_SEC, self::READ_TIMEOUT_USEC);

        $this->performHandshake($socket, $host, $port, $path);

        $this->socket = $socket;
        $this->connected = true;
    }

    /**
     * Performs the WebSocket handshake (RFC 6455).
     *
     * @throws WebSocketConnectionException
     */
    private function performHandshake(mixed $socket, string $host, int $port, string $path): void
    {
        $key = base64_encode(random_bytes(16));

        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}" . ($port != 80 && $port != 443 ? ":{$port}" : '') . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: whale-alert-php/1.0.0\r\n"
            . "\r\n";

        fwrite($socket, $request);

        $response = '';
        while (!feof($socket) && !str_contains($response, "\r\n\r\n")) {
            $chunk = fgets($socket, 1024);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }

        if (!str_contains($response, '101')) {
            throw new WebSocketConnectionException(
                'WebSocket handshake failed: ' . substr($response, 0, 200)
            );
        }
    }

    /**
     * Subscribes to alerts.
     *
     * @throws WebSocketSubscriptionException
     */
    public function subscribeAlerts(AlertSubscription $subscription): void
    {
        $subscription->validate();
        $this->subscriptionId = $subscription->getId();
        $this->lastAlertSub = $subscription;
        $this->send($subscription->toJson());
    }

    /**
     * Subscribes to socials.
     */
    public function subscribeSocials(SocialSubscription $subscription): void
    {
        $this->subscriptionId = $subscription->getId();
        $this->lastSocialSub = $subscription;
        $this->send($subscription->toJson());
    }

    /**
     * Registers a message handler callback.
     *
     * @param callable(Message): void $handler
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * Registers an error handler callback.
     *
     * @param callable(\Throwable): void $handler
     */
    public function onError(callable $handler): void
    {
        $this->errorHandler = $handler;
    }

    /**
     * Starts the read loop. Blocks until the connection is closed.
     */
    public function listen(): void
    {
        while ($this->connected) {
            try {
                $data = $this->receive();
            } catch (WebSocketConnectionException $e) {
                $data = null;
                $this->fireError($e);
            }

            if ($data === null) {
                $this->connected = false;
                if ($this->shouldReconnect()) {
                    $this->reconnect();
                    continue;
                }
                break;
            }

            $message = Message::decode($data);

            if ($message->type === EventType::Error && $message->error !== null) {
                $this->fireError(new WebSocketSubscriptionException($message->error));
                continue;
            }

            $this->fireMessage($message);
        }
    }

    /**
     * Sends a text frame over the WebSocket.
     *
     * @throws WebSocketConnectionException
     */
    private function send(string $data): void
    {
        if (!$this->connected || $this->socket === null) {
            throw new WebSocketConnectionException('Not connected.');
        }

        $frame = $this->encodeFrame($data, 0x1);
        $written = fwrite($this->socket, $frame);
        if ($written === false || $written !== strlen($frame)) {
            throw new WebSocketConnectionException('Failed to send WebSocket frame.');
        }
    }

    /**
     * Receives a text message from the WebSocket.
     */
    private function receive(): ?string
    {
        if ($this->socket === null) {
            return null;
        }

        $data = $this->readFrame();
        return $data;
    }

    /**
     * Reads and decodes a single WebSocket frame.
     */
    private function readFrame(): ?string
    {
        $header = $this->readBytes(2);
        if ($header === null) {
            return null;
        }

        $opcode = ord($header[0]) & 0x0F;
        $masked = (ord($header[1]) & 0x80) !== 0;
        $payloadLen = ord($header[1]) & 0x7F;

        if ($payloadLen === 126) {
            $ext = $this->readBytes(2);
            if ($ext === null) {
                return null;
            }
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = $this->readBytes(8);
            if ($ext === null) {
                return null;
            }
            $payloadLen = unpack('J', $ext)[1];
        }

        $maskKey = '';
        if ($masked) {
            $mask = $this->readBytes(4);
            if ($mask === null) {
                return null;
            }
            $maskKey = $mask;
        }

        $payload = $this->readBytes($payloadLen);
        if ($payload === null) {
            return null;
        }

        if ($masked) {
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
        }

        // Handle close frame
        if ($opcode === 0x8) {
            return null;
        }

        // Handle ping frame — send pong
        if ($opcode === 0x9) {
            $this->send($this->encodeFrame($payload, 0xA));
            return $this->readFrame();
        }

        return $payload;
    }

    /**
     * Reads exactly $length bytes from the socket.
     */
    private function readBytes(int $length): ?string
    {
        if ($length === 0) {
            return '';
        }

        $data = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) {
                    return null;
                }
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new WebSocketConnectionException('WebSocket read timed out.');
                }
                continue;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    /**
     * Encodes a WebSocket text frame (client-to-server, masked).
     */
    private function encodeFrame(string $payload, int $opcode): string
    {
        $payloadLen = strlen($payload);
        $mask = random_bytes(4);

        $frame = chr(0x80 | $opcode);

        if ($payloadLen < 126) {
            $frame .= chr(0x80 | $payloadLen);
        } elseif ($payloadLen < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $payloadLen);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $payloadLen);
        }

        $frame .= $mask;

        $masked = '';
        for ($i = 0; $i < $payloadLen; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        $frame .= $masked;

        return $frame;
    }

    private function shouldReconnect(): bool
    {
        return $this->maxReconnects > 0 && $this->reconnectAttempts < $this->maxReconnects;
    }

    private function reconnect(): void
    {
        $this->reconnectAttempts++;
        $delay = $this->backoff($this->reconnectAttempts);
        usleep($delay * 1000);

        try {
            $this->connected = false;
            $this->socket = null;
            $this->connect();

            if ($this->lastAlertSub !== null) {
                $this->send($this->lastAlertSub->toJson());
            }
            if ($this->lastSocialSub !== null) {
                $this->send($this->lastSocialSub->toJson());
            }
        } catch (\Throwable $e) {
            $this->fireError($e);
            if ($this->shouldReconnect()) {
                $this->reconnect();
            }
        }
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->reconnectDelayMs * (1 << ($attempt - 1));
        if ($delay > $this->reconnectMaxDelayMs || $delay <= 0) {
            return $this->reconnectMaxDelayMs;
        }
        return $delay;
    }

    private function fireMessage(Message $message): void
    {
        if ($this->messageHandler !== null) {
            ($this->messageHandler)($message);
        }
    }

    private function fireError(\Throwable $error): void
    {
        if ($this->errorHandler !== null) {
            ($this->errorHandler)($error);
        }
    }

    /**
     * Closes the WebSocket connection gracefully.
     */
    public function close(): void
    {
        if (!$this->connected || $this->socket === null) {
            return;
        }

        $closeFrame = $this->encodeFrame('', 0x8);
        fwrite($this->socket, $closeFrame);
        fclose($this->socket);
        $this->socket = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getSubscriptionId(): ?string
    {
        return $this->subscriptionId;
    }
}
