<?php
/**
 * TheHUB Email Helper
 * Simple email sending using PHP's mail() function
 * Compatible with Hostinger shared hosting
 */

/**
 * Send an email using PHP mail()
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email body (HTML supported)
 * @param array $options Optional settings (from_name, from_email, reply_to)
 * @return bool True if mail was accepted for delivery
 */
function hub_send_email(string $to, string $subject, string $body, array $options = []): bool {
    // Default sender info
    $fromName = $options['from_name'] ?? 'TheHUB';
    $fromEmail = $options['from_email'] ?? 'noreply@thehub.gravityseries.se';
    $replyTo = $options['reply_to'] ?? $fromEmail;

    // Build headers
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = "From: {$fromName} <{$fromEmail}>";
    $headers[] = "Reply-To: {$replyTo}";
    $headers[] = 'X-Mailer: TheHUB/3.5';

    // Log the email attempt
    error_log("TheHUB Mail: Sending to {$to} - Subject: {$subject}");

    // Send email
    $result = @mail($to, $subject, $body, implode("\r\n", $headers));

    if (!$result) {
        error_log("TheHUB Mail: Failed to send email to {$to}");
    }

    return $result;
}

/**
 * Send password reset email
 *
 * @param string $email Recipient email
 * @param string $name Recipient name
 * @param string $resetLink Full reset URL with token
 * @return bool True if email was sent
 */
function hub_send_password_reset_email(string $email, string $name, string $resetLink): bool {
    $subject = 'Återställ ditt lösenord - TheHUB';

    $body = hub_email_template('password_reset', [
        'name' => $name,
        'reset_link' => $resetLink,
        'expires' => '1 timme'
    ]);

    return hub_send_email($email, $subject, $body);
}

/**
 * Get email template with variables replaced
 *
 * @param string $template Template name
 * @param array $vars Variables to replace
 * @return string Rendered HTML email
 */
function hub_email_template(string $template, array $vars = []): string {
    // Base styles for email
    $styles = '
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 24px; }
        .logo { font-size: 24px; font-weight: bold; color: #f59e0b; }
        h1 { font-size: 24px; margin: 0 0 16px 0; color: #111; }
        p { margin: 0 0 16px 0; }
        .btn { display: inline-block; background: #f59e0b; color: #ffffff !important; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; margin: 16px 0; }
        .btn:hover { background: #d97706; }
        .link { word-break: break-all; color: #666; font-size: 14px; background: #f5f5f5; padding: 12px; border-radius: 6px; margin: 16px 0; }
        .footer { text-align: center; margin-top: 24px; padding-top: 24px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
        .note { font-size: 14px; color: #666; }
    ';

    // Templates
    $templates = [
        'password_reset' => '
            <div class="header">
                <div class="logo">🏆 TheHUB</div>
            </div>
            <h1>Återställ ditt lösenord</h1>
            <p>Hej {{name}},</p>
            <p>Vi har fått en förfrågan om att återställa lösenordet för ditt konto på TheHUB.</p>
            <p>Klicka på knappen nedan för att välja ett nytt lösenord:</p>
            <p style="text-align: center;">
                <a href="{{reset_link}}" class="btn">Återställ lösenord</a>
            </p>
            <p class="note">Eller kopiera och klistra in denna länk i din webbläsare:</p>
            <div class="link">{{reset_link}}</div>
            <p class="note">Länken är giltig i {{expires}}. Om du inte begärde denna återställning kan du ignorera detta mail.</p>
        ',

        'welcome' => '
            <div class="header">
                <div class="logo">🏆 TheHUB</div>
            </div>
            <h1>Välkommen till TheHUB!</h1>
            <p>Hej {{name}},</p>
            <p>Ditt konto har skapats på TheHUB - plattformen för gravity racing i Sverige.</p>
            <p>Du kan nu logga in och se dina resultat, anmäla dig till tävlingar och mer.</p>
            <p style="text-align: center;">
                <a href="{{login_link}}" class="btn">Logga in</a>
            </p>
        '
    ];

    // Get template content
    $content = $templates[$template] ?? '<p>Email template not found.</p>';

    // Replace variables
    foreach ($vars as $key => $value) {
        $content = str_replace('{{' . $key . '}}', htmlspecialchars($value), $content);
    }

    // Wrap in full HTML email structure
    $html = '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TheHUB</title>
    <style>' . $styles . '</style>
</head>
<body>
    <div class="container">
        <div class="card">
            ' . $content . '
        </div>
        <div class="footer">
            <p>TheHUB - Gravity Racing Sverige</p>
            <p><a href="https://thehub.gravityseries.se">thehub.gravityseries.se</a></p>
        </div>
    </div>
</body>
</html>';

    return $html;
}
