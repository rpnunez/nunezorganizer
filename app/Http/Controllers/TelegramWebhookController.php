<?php

namespace App\Http\Controllers;

use App\Integrations\Telegram\HandleTelegramCallback;
use App\Integrations\Telegram\StagePairing;
use App\Integrations\Telegram\TelegramClient;
use App\Integrations\Telegram\TelegramCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Authenticates and deduplicates Telegram updates before dispatching handlers. */
class TelegramWebhookController
{
    public function __invoke(Request $request, string $route_key, StagePairing $pairing, TelegramClient $client, HandleTelegramCallback $callback, TelegramCommands $commands): JsonResponse
    {
        $expectedKey = (string) config('services.telegram.route_key');
        $expectedSecret = (string) config('services.telegram.webhook_secret');
        abort_unless($expectedKey !== '' && hash_equals($expectedKey, $route_key), 404);
        abort_unless($expectedSecret !== '' && hash_equals($expectedSecret,
            (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);

        $data = $request->validate([
            'update_id' => ['required', 'integer', 'min:0'],
            'message' => ['sometimes', 'array'],
            'callback_query' => ['sometimes', 'array'],
        ]);

        $inserted = DB::table('telegram_webhook_updates')->insertOrIgnore([
            'update_id' => $data['update_id'], 'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return response()->json(['ok' => true]);
        }

        try {
            if (isset($data['callback_query'])) {
                $callback->handle($data['callback_query']);

                return response()->json(['ok' => true]);
            }

            if (($data['message']['chat']['type'] ?? null) !== 'private') {
                return response()->json(['ok' => true]);
            }

            $message = $data['message'];
            $text = (string) ($message['text'] ?? '');
            $fromId = (int) ($message['from']['id'] ?? 0);
            $chatId = (int) ($message['chat']['id'] ?? 0);

            if (preg_match('/^\/start ([A-Za-z0-9]{32})$/', $text, $match)) {
                $accepted = $pairing->handle($match[1], $fromId, $chatId);
                if ($accepted) {
                    $client->sendMessage($chatId, __('Return to NunezOrganizer and confirm this Telegram chat.'));
                }
            } elseif ($text === '/help') {
                $client->sendMessage($chatId, __('Open NunezOrganizer Settings to connect or disconnect this private chat.'));
            } elseif (in_array($text, ['/today', '/backlog', '/unlink'], true)) {
                $commands->handle($text, $fromId, $chatId);
            }

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            DB::table('telegram_webhook_updates')->where('update_id', $data['update_id'])->delete();
            throw $e;
        }
    }
}
