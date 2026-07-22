<?php

declare(strict_types=1);

namespace Tigusigalpa\WhaleAlert\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tigusigalpa\WhaleAlert\WebSocket\AlertSubscription;
use Tigusigalpa\WhaleAlert\WebSocket\SocialSubscription;

class WebSocketTest extends TestCase
{
    public function testAlertSubscriptionValid(): void
    {
        $sub = new AlertSubscription(
            id: 'test-id',
            blockchains: ['ethereum'],
            minValueUsd: 500000,
        );
        $sub->validate();
        $this->assertSame('test-id', $sub->getId());
    }

    public function testAlertSubscriptionMinValueTooLow(): void
    {
        $sub = new AlertSubscription(
            blockchains: ['ethereum'],
            minValueUsd: 50000,
        );
        $this->expectException(\InvalidArgumentException::class);
        $sub->validate();
    }

    public function testAlertSubscriptionNoFilters(): void
    {
        $sub = new AlertSubscription(
            minValueUsd: 100000,
        );
        $this->expectException(\InvalidArgumentException::class);
        $sub->validate();
    }

    public function testAlertSubscriptionToJson(): void
    {
        $sub = new AlertSubscription(
            id: 'test-id',
            blockchains: ['ethereum', 'bitcoin'],
            symbols: ['eth', 'btc'],
            txTypes: ['transfer'],
            minValueUsd: 500000,
        );
        $json = $sub->toJson();
        $data = json_decode($json, true);
        $this->assertSame('subscribe_alerts', $data['type']);
        $this->assertSame('test-id', $data['id']);
        $this->assertSame(['ethereum', 'bitcoin'], $data['blockchains']);
        $this->assertSame(['eth', 'btc'], $data['symbols']);
        $this->assertSame(['transfer'], $data['tx_types']);
        $this->assertEquals(500000, $data['min_value_usd']);
    }

    public function testSocialSubscriptionToJson(): void
    {
        $sub = new SocialSubscription(id: 'social-id');
        $json = $sub->toJson();
        $data = json_decode($json, true);
        $this->assertSame('subscribe_socials', $data['type']);
        $this->assertSame('social-id', $data['id']);
    }

    public function testDecodeSubscribedAlerts(): void
    {
        $raw = '{"id":"8QFdN74g","type":"subscribed_alerts","blockchains":["ethereum"],"symbols":["eth"],"tx_types":["transfer"],"min_value_usd":1000000}';
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode($raw);
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::SubscribedAlerts, $msg->type);
        $this->assertNotNull($msg->alertConfirm);
        $this->assertSame('8QFdN74g', $msg->alertConfirm['id']);
    }

    public function testDecodeSubscribedSocials(): void
    {
        $raw = '{"id":"8QFdN74g","type":"subscribed_socials"}';
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode($raw);
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::SubscribedSocials, $msg->type);
        $this->assertNotNull($msg->socialConfirm);
    }

    public function testDecodeAlert(): void
    {
        $raw = '{"channel_id":"xlLZ7tJq","timestamp":1687389431,"blockchain":"ethereum","transaction_type":"transfer","from":"unknown wallet","to":"unknown wallet","amounts":[{"symbol":"USDC","amount":20006425.31,"value_usd":20008425.95}],"text":"transferred"}';
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode($raw);
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::Alert, $msg->type);
        $this->assertNotNull($msg->alert);
        $this->assertSame('ethereum', $msg->alert['blockchain']);
    }

    public function testDecodeSocial(): void
    {
        $raw = '{"channel_id":"xlLZ7tJq","timestamp":1692724660,"blockchain":"tron","text":"1,200,000,000 USDT burned","urls":["https://twitter.com/whale_alert/status/1694036126422450598"]}';
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode($raw);
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::Social, $msg->type);
        $this->assertNotNull($msg->social);
        $this->assertSame('tron', $msg->social['blockchain']);
    }

    public function testDecodeError(): void
    {
        $raw = '{"error":"Invalid API key"}';
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode($raw);
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::Error, $msg->type);
        $this->assertSame('Invalid API key', $msg->error);
    }

    public function testDecodeUnknown(): void
    {
        $raw = '{"some_unknown_field": true}';
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode($raw);
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::Unknown, $msg->type);
    }

    public function testDecodeInvalidJson(): void
    {
        $msg = \Tigusigalpa\WhaleAlert\WebSocket\Message::decode('{invalid');
        $this->assertSame(\Tigusigalpa\WhaleAlert\WebSocket\EventType::Unknown, $msg->type);
    }
}
