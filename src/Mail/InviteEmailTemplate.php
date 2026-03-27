<?php

declare(strict_types=1);

namespace App\Mail;

final class InviteEmailTemplate
{
    public static function render(string $inviteUrl, string $expiresAt): string
    {
        $inviteUrlEscaped = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
        $expiresAtEscaped = htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>You're Invited</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f7;">
  <table cellpadding="0" cellspacing="0" border="0" style="width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #ffffff;">
    <tbody>
      <tr>
        <td style="height: 60px;"></td>
      </tr>
      <tr>
        <td style="text-align: center; padding-bottom: 32px;">
          <div style="width: 64px; height: 64px; background-color: #007AFF; border-radius: 16px; margin: 0 auto; display: inline-block; line-height: 64px; text-align: center;">
            <span style="font-size: 32px;">&#128176;</span>
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding: 0 48px;">
          <h1 style="font-size: 28px; font-weight: 600; color: #1d1d1f; text-align: center; margin: 0 0 16px 0; line-height: 1.3;">
            You're Invited
          </h1>
          <p style="font-size: 17px; color: #86868b; text-align: center; margin: 0 0 40px 0; line-height: 1.5;">
            Welcome to budgetting app project :)
          </p>
          <table cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
            <tbody>
              <tr>
                <td style="text-align: center; padding-bottom: 40px;">
                  <a href="{$inviteUrlEscaped}" style="display: inline-block; background-color: #007AFF; color: #ffffff; font-size: 17px; font-weight: 500; text-decoration: none; padding: 14px 32px; border-radius: 12px;">
                    Accept Invitation
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
          <div style="height: 1px; background-color: #e5e5e7; margin: 0 0 32px 0;"></div>
          <table cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
            <tbody>
              <tr>
                <td style="padding-bottom: 24px;">
                  <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="padding-right: 12px; vertical-align: top;">
                        <span style="font-size: 20px;">&#128202;</span>
                      </td>
                      <td>
                        <h3 style="font-size: 15px; font-weight: 600; color: #1d1d1f; margin: 0 0 4px 0;">
                          Smart Insights
                        </h3>
                        <p style="font-size: 14px; color: #86868b; margin: 0; line-height: 1.4;">
                          Understand your spending patterns with intelligent analytics
                        </p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom: 24px;">
                  <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="padding-right: 12px; vertical-align: top;">
                        <span style="font-size: 20px;">&#128274;</span>
                      </td>
                      <td>
                        <h3 style="font-size: 15px; font-weight: 600; color: #1d1d1f; margin: 0 0 4px 0;">
                          Privacy First
                        </h3>
                        <p style="font-size: 14px; color: #86868b; margin: 0; line-height: 1.4;">
                          Your financial data stays private and secure
                        </p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom: 24px;">
                  <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="padding-right: 12px; vertical-align: top;">
                        <span style="font-size: 20px;">&#10024;</span>
                      </td>
                      <td>
                        <h3 style="font-size: 15px; font-weight: 600; color: #1d1d1f; margin: 0 0 4px 0;">
                          Effortless Tracking
                        </h3>
                        <p style="font-size: 14px; color: #86868b; margin: 0; line-height: 1.4;">
                          Budget management that's beautifully simple
                        </p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </tbody>
          </table>
        </td>
      </tr>
      <tr>
        <td style="padding: 12px 48px 8px 48px; text-align: center;">
          <p style="font-size: 12px; color: #86868b; margin: 0; line-height: 1.4;">
            Expires at (UTC): {$expiresAtEscaped}
          </p>
        </td>
      </tr>
      <tr>
        <td style="padding: 20px 48px 40px 48px; text-align: center;">
          <p style="font-size: 13px; color: #86868b; margin: 0; line-height: 1.5;">
            This invitation was sent to you because I love you girlfriend &lt;3.
          </p>
        </td>
      </tr>
      <tr>
        <td style="height: 40px;"></td>
      </tr>
    </tbody>
  </table>
</body>
</html>
HTML;
    }
}
