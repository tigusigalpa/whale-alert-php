<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\WebSocket;

use Tigusigalpa\WhaleAlert\Exceptions\WebSocketConnectionException;
use Tigusigalpa\WhaleAlert\Exceptions\WebSocketSubscriptionException;

/**
 * WebSocket client for the Whale Alert alerts API.
 *
 * Uses PHP stream sockets and implements RFC 6455 framing directly.
 */
class Client
{
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_PING_INTERVAL = 30;
    private const READ_TIMEOUT = 60;
    private const WRITE_TIMEOUT = 10;
    private const MAX_HANDSHAKE_SIZE = 16384;
    private const MAX_MESSAGE_SIZE = 16777216;

    private string $url;
    private int $timeout;
    private int $maxReconnects;
    private int $reconnectDelayMs;
    private int $reconnectMaxDelayMs;
    private int $pingInterval;

    private mixed $socket = null;
    private bool $connected = false;
    private ?string $subscriptionId = null;
    private ?AlertSubscription $lastAlertSub = null;
    private ?SocialSubscription $lastSocialSub = null;
    private int $reconnectAttempts = 0;
    private ?float $lastActivityAt = null;
    private ?float $lastPingAt = null;

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
        int $pingInterval = self::DEFAULT_PING_INTERVAL,
    ) {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('WebSocket timeout must be positive.');
        }
        if ($maxReconnects < 0) {
            throw new \InvalidArgumentException('Max reconnects must be zero or positive.');
        }
        if ($reconnectDelayMs <= 0 || $reconnectMaxDelayMs <= 0) {
            throw new \InvalidArgumentException('Reconnect delays must be positive.');
        }
        if ($pingInterval < 0) {
            throw new \InvalidArgumentException('Ping interval must be zero or positive.');
        }

        $this->url = $url;
        $this->timeout = $timeout;
        $this->maxReconnects = $maxReconnects;
        $this->reconnectDelayMs = $reconnectDelayMs;
        $this->reconnectMaxDelayMs = $reconnectMaxDelayMs;
        $this->pingInterval = $pingInterval;
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
        if (
            $parsed === false
            || !isset($parsed['scheme'], $parsed['host'])
            || isset($parsed['user'])
            || isset($parsed['pass'])
        ) {
            throw new WebSocketConnectionException('Invalid WebSocket URL.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['ws', 'wss'], true)) {
            throw new WebSocketConnectionException('WebSocket URL must use ws or wss.');
        }

        $host = $parsed['host'];
        $port = (int) ($parsed['port'] ?? ($scheme === 'wss' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new WebSocketConnectionException('WebSocket URL has an invalid port.');
        }

        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $socketHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $defaultPort = $scheme === 'wss' ? 443 : 80;
        $hostHeader = $socketHost . ($port !== $defaultPort ? ':' . $port : '');
        $useTls = $scheme === 'wss';
        $remote = ($useTls ? 'ssl://' : '') . $socketHost . ':' . $port;
        $context = stream_context_create($useTls ? [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
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

        try {
            stream_set_timeout($socket, $this->timeout);
            $this->performHandshake($socket, $hostHeader, $path);
            stream_set_blocking($socket, false);
        } catch (\Throwable $e) {
            fclose($socket);
            throw $e;
        }

        $this->socket = $socket;
        $this->connected = true;
        $this->markActivity();
    }

    /**
     * Performs and validates the RFC 6455 handshake.
     *
     * @throws WebSocketConnectionException
     */
    private function performHandshake(mixed $socket, string $hostHeader, string $path): void
    {
        $key = base64_encode(random_bytes(16));
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$hostHeader}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: whale-alert-php/1.0.0\r\n"
            . "\r\n";

        $this->writeAll($socket, $request);

        $response = '';
        while (!feof($socket) && !str_contains($response, "\r\n\r\n")) {
            $chunk = fgets($socket, 4096);
            if ($chunk === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new WebSocketConnectionException('WebSocket handshake timed out.');
                }
                break;
            }
            $response .= $chunk;
            if (strlen($response) > self::MAX_HANDSHAKE_SIZE) {
                throw new WebSocketConnectionException('WebSocket handshake response is too large.');
            }
        }

        [$statusLine, $headers] = $this->parseHandshakeResponse($response);
        if (!preg_match('/^HTTP\/\d\.\d\s+101(?:\s|$)/', $statusLine)) {
            throw new WebSocketConnectionException(
                'WebSocket handshake failed: ' . substr($statusLine, 0, 200)
            );
        }

        $expectedAccept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $accept = $headers['sec-websocket-accept'] ?? '';
        if (
            !hash_equals($expectedAccept, $accept)
            || !$this->headerHasToken($headers['upgrade'] ?? '', 'websocket')
            || !$this->headerHasToken($headers['connection'] ?? '', 'upgrade')
        ) {
            throw new WebSocketConnectionException('WebSocket handshake response is invalid.');
        }
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function parseHandshakeResponse(string $response): array
    {
        $parts = explode("\r\n\r\n", $response, 2);
        $lines = explode("\r\n", $parts[0] ?? '');
        $statusLine = array_shift($lines) ?? '';
        $headers = [];

        foreach ($lines as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ',' . $value : $value;
        }

        return [$statusLine, $headers];
    }

    private function headerHasToken(string $value, string $token): bool
    {
        return in_array(
            strtolower($token),
            array_map(static fn(string $part): string => strtolower(trim($part)), explode(',', $value)),
            true,
        );
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
        $this->sendText($subscription->toJson());
    }

    /**
     * Subscribes to socials.
     */
    public function subscribeSocials(SocialSubscription $subscription): void
    {
        $this->subscriptionId = $subscription->getId();
        $this->lastSocialSub = $subscription;
        $this->sendText($subscription->toJson());
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
                $this->fireError($e);
                $this->disconnect();
                if ($this->reconnect()) {
                    continue;
                }
                break;
            }

            if ($data === null) {
                $this->disconnect();
                if ($this->reconnect()) {
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
     * Receives one complete text message, handling control and fragmented frames.
     */
    private function receive(): ?string
    {
        if ($this->socket === null) {
            return null;
        }

        $messageOpcode = null;
        $fragments = '';

        while (true) {
            $frame = $this->readFrame();
            if ($frame === null) {
                return null;
            }

            [$fin, $opcode, $payload] = $frame;
            if ($opcode === 0x8) {
                try {
                    $this->writeFrame($payload, 0x8);
                } catch (WebSocketConnectionException) {
                    // The peer has already closed the transport.
                }
                return null;
            }
            if ($opcode === 0x9) {
                $this->markActivity();
                $this->writeFrame($payload, 0xA);
                continue;
            }
            if ($opcode === 0xA) {
                $this->markActivity();
                continue;
            }
            if ($opcode === 0x2) {
                throw new WebSocketConnectionException('Unexpected binary WebSocket message.');
            }
            if ($opcode === 0x1) {
                if ($messageOpcode !== null) {
                    throw new WebSocketConnectionException('Unexpected WebSocket data frame.');
                }
                if ($fin) {
                    $this->markActivity();
                    return $payload;
                }
                $messageOpcode = $opcode;
                $fragments = $payload;
                continue;
            }
            if ($opcode === 0x0) {
                if ($messageOpcode === null) {
                    throw new WebSocketConnectionException('Unexpected WebSocket continuation frame.');
                }
                $fragments .= $payload;
                if (strlen($fragments) > self::MAX_MESSAGE_SIZE) {
                    throw new WebSocketConnectionException('WebSocket message exceeds the maximum size.');
                }
                if ($fin) {
                    $this->markActivity();
                    return $fragments;
                }
                continue;
            }

            throw new WebSocketConnectionException('Unsupported WebSocket opcode.');
        }
    }

    /**
     * @return array{bool, int, string}|null
     */
    private function readFrame(): ?array
    {
        $header = $this->readBytes(2);
        if ($header === null) {
            return null;
        }

        $firstByte = ord($header[0]);
        $secondByte = ord($header[1]);
        $fin = ($firstByte & 0x80) !== 0;
        $opcode = $firstByte & 0x0F;
        $payloadLength = $secondByte & 0x7F;

        if (($firstByte & 0x70) !== 0) {
            throw new WebSocketConnectionException('WebSocket extensions are not supported.');
        }
        if (($secondByte & 0x80) !== 0) {
            throw new WebSocketConnectionException('Server WebSocket frames must not be masked.');
        }

        if ($payloadLength === 126) {
            $extended = $this->readBytes(2);
            if ($extended === null) {
                return null;
            }
            $payloadLength = unpack('n', $extended)[1];
        } elseif ($payloadLength === 127) {
            $extended = $this->readBytes(8);
            if ($extended === null) {
                return null;
            }
            $length = unpack('Nhigh/Nlow', $extended);
            if ($length['high'] !== 0 || $length['low'] > self::MAX_MESSAGE_SIZE) {
                throw new WebSocketConnectionException('WebSocket message exceeds the maximum size.');
            }
            $payloadLength = $length['low'];
        }

        if ($payloadLength > self::MAX_MESSAGE_SIZE) {
            throw new WebSocketConnectionException('WebSocket message exceeds the maximum size.');
        }
        if (($opcode & 0x08) !== 0 && (!$fin || $payloadLength > 125)) {
            throw new WebSocketConnectionException('Invalid WebSocket control frame.');
        }

        $payload = $this->readBytes($payloadLength);
        if ($payload === null) {
            return null;
        }

        return [$fin, $opcode, $payload];
    }

    /**
     * Reads exactly $length bytes from the socket while maintaining the heartbeat.
     */
    private function readBytes(int $length): ?string
    {
        if ($length === 0) {
            return '';
        }
        if ($this->socket === null) {
            return null;
        }

        $data = '';
        while (strlen($data) < $length) {
            $this->waitForSocketRead();
            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) {
                    return null;
                }
                if ($chunk === false) {
                    throw new WebSocketConnectionException('Failed to read WebSocket frame.');
                }
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function waitForSocketRead(): void
    {
        if ($this->socket === null) {
            throw new WebSocketConnectionException('Not connected.');
        }

        while (true) {
            $now = microtime(true);
            if ($this->lastPingAt !== null && $now - $this->lastPingAt >= self::READ_TIMEOUT) {
                throw new WebSocketConnectionException('WebSocket pong timed out.');
            }

            if (
                $this->pingInterval > 0
                && $this->lastPingAt === null
                && $this->lastActivityAt !== null
                && $now - $this->lastActivityAt >= $this->pingInterval
            ) {
                $this->writeFrame('', 0x9);
                $this->lastPingAt = microtime(true);
                continue;
            }

            $wakeAt = $this->lastPingAt !== null
                ? $this->lastPingAt + self::READ_TIMEOUT
                : ($this->lastActivityAt ?? $now) + ($this->pingInterval > 0 ? $this->pingInterval : self::READ_TIMEOUT);
            $wait = max(0.001, $wakeAt - $now);
            $seconds = (int) floor($wait);
            $microseconds = (int) floor(($wait - $seconds) * 1000000);
            $read = [$this->socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($ready === false) {
                throw new WebSocketConnectionException('Failed while waiting for WebSocket data.');
            }
            if ($ready > 0) {
                return;
            }
        }
    }

    /**
     * Sends a text frame over the WebSocket.
     */
    private function sendText(string $data): void
    {
        $this->writeFrame($data, 0x1);
    }

    private function writeFrame(string $payload, int $opcode): void
    {
        if (!$this->connected || $this->socket === null) {
            throw new WebSocketConnectionException('Not connected.');
        }

        $this->writeAll($this->socket, $this->encodeFrame($payload, $opcode));
    }

    private function writeAll(mixed $socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false) {
                throw new WebSocketConnectionException('Failed to send WebSocket frame.');
            }
            if ($written === 0) {
                $this->waitForSocketWrite($socket);
                continue;
            }
            $offset += $written;
        }
    }

    private function waitForSocketWrite(mixed $socket): void
    {
        $write = [$socket];
        $read = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, self::WRITE_TIMEOUT);
        if ($ready !== 1) {
            throw new WebSocketConnectionException('WebSocket write timed out.');
        }
    }

    /**
     * Encodes a masked client-to-server frame.
     */
    private function encodeFrame(string $payload, int $opcode): string
    {
        $payloadLength = strlen($payload);
        $mask = random_bytes(4);
        $frame = chr(0x80 | $opcode);

        if ($payloadLength < 126) {
            $frame .= chr(0x80 | $payloadLength);
        } elseif ($payloadLength < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $payloadLength);
        } else {
            $frame .= chr(0x80 | 127) . pack('N2', 0, $payloadLength);
        }

        $frame .= $mask;
        $masked = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        return $frame . $masked;
    }

    private function markActivity(): void
    {
        $this->lastActivityAt = microtime(true);
        $this->lastPingAt = null;
    }

    private function shouldReconnect(): bool
    {
        return $this->maxReconnects > 0 && $this->reconnectAttempts < $this->maxReconnects;
    }

    private function reconnect(): bool
    {
        while ($this->shouldReconnect()) {
            $this->reconnectAttempts++;
            usleep($this->backoff($this->reconnectAttempts) * 1000);

            try {
                $this->connect();
                if ($this->lastAlertSub !== null) {
                    $this->sendText($this->lastAlertSub->toJson());
                }
                if ($this->lastSocialSub !== null) {
                    $this->sendText($this->lastSocialSub->toJson());
                }
                $this->reconnectAttempts = 0;
                return true;
            } catch (\Throwable $e) {
                $this->fireError($e);
                $this->disconnect();
            }
        }

        return false;
    }

    private function backoff(int $attempt): int
    {
        $delay = $this->reconnectDelayMs * (1 << ($attempt - 1));
        if ($delay > $this->reconnectMaxDelayMs || $delay <= 0) {
            return $this->reconnectMaxDelayMs;
        }

        return $delay;
    }

    private function disconnect(): void
    {
        $socket = $this->socket;
        $this->socket = null;
        $this->connected = false;
        $this->lastActivityAt = null;
        $this->lastPingAt = null;

        if (is_resource($socket)) {
            fclose($socket);
        }
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

        try {
            $this->writeFrame('', 0x8);
        } finally {
            $this->disconnect();
        }
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
