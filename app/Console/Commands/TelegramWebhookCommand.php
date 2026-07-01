<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Operação do webhook do Telegram (doc 06 §3). Registra a URL pública do webhook
 * passando o segredo (devolvido pelo Telegram no header e validado pelo
 * middleware), consulta o estado (--info) ou remove (--delete). Uso típico em dev:
 * apontar para o túnel HTTPS (cloudflared/ngrok) que expõe o app local.
 */
final class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook
        {url? : URL pública HTTPS do webhook (ex.: https://SEU-TUNEL/telegram/webhook)}
        {--info : Mostra o estado atual do webhook}
        {--delete : Remove o webhook}';

    protected $description = 'Registra, inspeciona ou remove o webhook do bot na Bot API do Telegram';

    public function handle(): int
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN não configurado. Crie o bot no @BotFather e defina o token no .env.');

            return self::FAILURE;
        }

        return match (true) {
            (bool) $this->option('info') => $this->info_(),
            (bool) $this->option('delete') => $this->remover(),
            $this->argument('url') !== null => $this->registrar((string) $this->argument('url')),
            default => $this->orientar(),
        };
    }

    private function registrar(string $url): int
    {
        $segredo = (string) config('services.telegram.webhook_secret');

        if ($segredo === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET não configurado — necessário para validar a origem.');

            return self::FAILURE;
        }

        $this->api()->post('setWebhook', [
            'url' => $url,
            'secret_token' => $segredo,
            'allowed_updates' => ['message'],
        ])->throw();

        $this->info("Webhook registrado em: {$url}");

        return self::SUCCESS;
    }

    private function info_(): int
    {
        $info = $this->api()->get('getWebhookInfo')->throw()->json('result', []);

        $this->line(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }

    private function remover(): int
    {
        $this->api()->post('deleteWebhook')->throw();

        $this->info('Webhook removido.');

        return self::SUCCESS;
    }

    private function orientar(): int
    {
        $this->error('Informe a URL do webhook, ou use --info / --delete.');

        return self::FAILURE;
    }

    private function api(): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token');

        return Http::asJson()->baseUrl("https://api.telegram.org/bot{$token}");
    }
}
