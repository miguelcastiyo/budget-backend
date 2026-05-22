<?php

declare(strict_types=1);

namespace App\Mail;

final class InviteEmailTemplate
{
    public static function render(string $inviteUrl, string $expiresAt, string $inviteeName, string $body): string
    {
        $inviteUrlEscaped = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
        $expiresAtEscaped = htmlspecialchars(self::formatPacificExpiry($expiresAt), ENT_QUOTES, 'UTF-8');
        $bodyEscaped = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>You're Invited</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f7;">
  <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; background-color: #f5f5f7;">
    <tbody>
      <tr>
        <td style="padding: 40px 16px;">
          <table cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #ffffff; border-radius: 24px; overflow: hidden;">
            <tbody>
              <tr>
                <td style="height: 48px;"></td>
              </tr>
              <tr>
                <td style="text-align: center; padding-bottom: 24px;">
                  <div style="width: 64px; height: 64px; background-color: #111827; border-radius: 18px; margin: 0 auto; display: inline-block; line-height: 64px; text-align: center;">
                    <span style="font-size: 32px;">&#128176;</span>
                  </div>
                </td>
              </tr>
              <tr>
                <td style="padding: 0 48px;">
                  <h1 style="font-size: 28px; font-weight: 700; color: #111827; text-align: center; margin: 0 0 12px 0; line-height: 1.25;">
                    You're invited to Budget
                  </h1>
                </td>
              </tr>
              <tr>
                <td style="padding: 0 48px 32px 48px;">
                  <div style="font-size: 15px; color: #374151; line-height: 1.6; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px;">
                    {$bodyEscaped}
                  </div>
                </td>
              </tr>
              <tr>
                <td style="text-align: center; padding: 0 48px 32px 48px;">
                  <a href="{$inviteUrlEscaped}" style="display: inline-block; background-color: #111827; color: #ffffff; font-size: 16px; font-weight: 600; text-decoration: none; padding: 14px 28px; border-radius: 14px;">
                    Accept invitation
                  </a>
                </td>
              </tr>
              <tr>
                <td style="padding: 0 48px 20px 48px; text-align: center;">
                  <p style="font-size: 12px; color: #6b7280; margin: 0; line-height: 1.4;">
                    Expires {$expiresAtEscaped}
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding: 0 48px 40px 48px; text-align: center;">
                  <p style="font-size: 13px; color: #9ca3af; margin: 0; line-height: 1.5;">
                    If you were not expecting this invitation, you can ignore this email.
                  </p>
                </td>
              </tr>
            </tbody>
          </table>
        </td>
      </tr>
    </tbody>
  </table>
</body>
</html>
HTML;
    }

    public static function formatPacificExpiry(string $expiresAt): string
    {
        try {
            $utc = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
            $pacific = $utc->setTimezone(new \DateTimeZone('America/Los_Angeles'));
            return $pacific->format('F j, Y \a\t g:i A T');
        } catch (\Exception) {
            return $expiresAt;
        }
    }
}
