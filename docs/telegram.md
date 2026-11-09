# Telegram setup (M4 foundation)

No bot is created by this repository. The owner creates one with BotFather and keeps its token outside source control.

1. Create a private-chat bot with BotFather. Set its commands to `/start`, `/help`, `/today`, `/backlog`, and `/unlink`.
2. Set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`, and `TELEGRAM_WEBHOOK_ROUTE_KEY` in the deployment environment. Generate the secret and route key independently; use only letters, digits, underscores, or hyphens for Telegram's webhook secret.
3. Register `https://<host>/webhooks/telegram/<route-key>` with Telegram `setWebhook`, passing `secret_token` equal to `TELEGRAM_WEBHOOK_SECRET`. Use HTTPS in production. Telegram sends the value in `X-Telegram-Bot-Api-Secret-Token` on every update.
4. In Settings → Telegram, generate a pairing code, open the deep link in a **private** chat, and return to the browser to confirm the Telegram user ID. A code is valid for ten minutes and is stored only as a hash. `/start` stages the chat; it does not activate a link without confirmation by the signed-in account owner.
5. For local tests, fake the Telegram HTTP client and call the webhook with a test secret. A local tunnel may be used for a manually authorized integration test; never commit its URL or token.

The webhook rejects a missing or wrong secret, oversized payloads, and non-private chats; it deduplicates `update_id`. Telegram API failures are reported by status without logging the token-bearing request URL. Completion prompts include one-time Yes/No buttons; `/today` and `/backlog` show read-only summaries. Run the scheduler every minute and a queue worker continuously. Under normal load the target is delivery within two minutes of a block ending. A provider timeout after accepting a message can cause a second physical prompt on retry, but one-time callback actions prevent duplicate task transitions.
