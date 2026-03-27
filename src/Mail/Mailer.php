<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Http\HttpException;

final class Mailer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Invalid email recipient');
        }

        $transport = strtolower(trim((string) $this->config->get('MAIL_TRANSPORT', 'log')));

        if ($transport === 'resend') {
            $this->sendViaResend($to, $subject, $textBody, $htmlBody);
            return;
        }

        $this->logEmail($to, $subject, $textBody, $htmlBody);
    }

    private function sendViaResend(string $to, string $subject, string $textBody, ?string $htmlBody): void
    {
        $apiKey = trim((string) $this->config->get('RESEND_API_KEY', ''));
        $fromEmail = trim((string) $this->config->get('MAIL_FROM_EMAIL', ''));
        $fromName = trim((string) $this->config->get('MAIL_FROM_NAME', 'Budget App'));

        if ($apiKey === '' || $fromEmail === '') {
            throw new HttpException(500, 'INTERNAL_ERROR', 'MAIL_TRANSPORT=resend requires RESEND_API_KEY and MAIL_FROM_EMAIL');
        }

        $from = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;

        $payload = [
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'text' => $textBody,
        ];

        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $payload['html'] = $htmlBody;
        }

        $ch = curl_init('https://api.resend.com/emails');
        if ($ch === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Could not initialize mail client');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($raw === false) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Email send failed: ' . $curlError);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(500, 'INTERNAL_ERROR', 'Email provider returned invalid response');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['message'] ?? 'Email provider rejected request');
            throw new HttpException(500, 'INTERNAL_ERROR', 'Email send failed: ' . $message);
        }
    }

    private function logEmail(string $to, string $subject, string $textBody, ?string $htmlBody): void
    {
        $path = (string) $this->config->get('MAIL_LOG_PATH', 'storage/mail.log');
        $root = dirname(__DIR__, 2);
        $target = str_starts_with($path, '/') ? $path : $root . '/' . $path;

        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entry = [
            'sent_at' => gmdate('c'),
            'to' => $to,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
        ];

        file_put_contents($target, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}
