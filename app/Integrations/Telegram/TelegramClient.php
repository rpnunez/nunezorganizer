<?php

namespace App\Integrations\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** HTTP wrapper that never includes the bot token or full URL in exceptions. */
class TelegramClient
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function call(string $method, array $payload): array
    {
        $token = (string) config('services.telegram.token');
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        try {
            $response = Http::timeout(5)->post('https://api.telegram.org/bot'.$token.'/'.$method, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Telegram connection failed.');
        }

        if ($response->status() === 429) {
            throw new TelegramRateLimited(max(1, (int) $response->json('parameters.retry_after', 30)));
        }
        if (in_array($response->status(), [400, 401, 403, 404], true)) {
            throw new TelegramPermanentFailure($response->status() === 403);
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram API failed with HTTP '.$response->status().'.');
        }

        return (array) $response->json('result', []);
    }

    /** @param array<string, mixed> $extras */
    public function sendMessage(int $chatId, string $text, array $extras = []): ?string
    {
        $result = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, ...$extras]);

        return isset($result['message_id']) ? (string) $result['message_id'] : null;
    }

    public function answerCallback(string $callbackId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function editMessage(int $chatId, int $messageId, string $text): void
    {
        $this->call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }
}
