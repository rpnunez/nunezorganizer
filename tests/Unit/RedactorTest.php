<?php

namespace Tests\Unit;

use App\Domain\Audit\Redactor;
use App\Logging\RedactSecretsProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    private const string FAKE_BOT_TOKEN = '123456789:AAFakeTokenForTestsOnly_abcdefghijklmnop';

    public function test_sensitive_keys_are_masked_recursively(): void
    {
        $redacted = Redactor::redact([
            'password' => 'hunter2',
            'nested' => ['api_token' => 'abc', 'Authorization' => 'Bearer xyz', 'safe' => 'kept'],
            'pairing_code' => '123456',
            'count' => 3,
        ]);

        $this->assertSame([
            'password' => Redactor::MASK,
            'nested' => ['api_token' => Redactor::MASK, 'Authorization' => Redactor::MASK, 'safe' => 'kept'],
            'pairing_code' => Redactor::MASK,
            'count' => 3,
        ], $redacted);
    }

    public function test_telegram_tokens_and_bearer_credentials_are_masked_inside_strings(): void
    {
        $message = 'POST https://api.telegram.org/bot'.self::FAKE_BOT_TOKEN.'/sendMessage failed; token '.self::FAKE_BOT_TOKEN.'; header Bearer abcdefghijklmnop123';

        $redacted = Redactor::redactString($message);

        $this->assertStringNotContainsString(self::FAKE_BOT_TOKEN, $redacted);
        $this->assertStringNotContainsString('abcdefghijklmnop123', $redacted);
        $this->assertStringContainsString('api.telegram.org/bot[REDACTED]', $redacted);
        $this->assertStringContainsString('Bearer [REDACTED]', $redacted);
    }

    public function test_log_processor_redacts_message_and_context(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Error,
            message: 'Telegram call failed for token '.self::FAKE_BOT_TOKEN,
            context: ['webhook_secret' => 's3cr3t', 'user_id' => 7],
        );

        $processed = (new RedactSecretsProcessor)($record);

        $this->assertStringNotContainsString(self::FAKE_BOT_TOKEN, $processed->message);
        $this->assertSame(Redactor::MASK, $processed->context['webhook_secret']);
        $this->assertSame(7, $processed->context['user_id']);
    }
}
