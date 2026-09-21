<?php
/** Authenticated SMTP delivery for transactional application email. */

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

class MailerException extends RuntimeException
{
}

final class Mailer
{
    public function __construct()
    {
        $autoload = APP_ROOT . '/vendor/autoload.php';
        if (!defined('MAIL_CONFIGURED') || !MAIL_CONFIGURED || !is_file($autoload)) {
            throw new MailerException('Transactional email is unavailable.');
        }

        require_once $autoload;
        if (!class_exists(PHPMailer::class)) {
            throw new MailerException('Transactional email is unavailable.');
        }
    }

    /**
     * Send a fixed verification message. Dynamic values are used only for the
     * recipient, greeting, and trusted APP_URL-based verification link.
     */
    public function sendVerificationEmail(string $email, string $name, string $verificationUrl): void
    {
        [$email, $name] = $this->normalizeRecipient($email, $name);
        if (!$this->urlIsTrusted($verificationUrl)) {
            throw new MailerException('The verification message is invalid.');
        }

        $safeUrl = $this->escape($verificationUrl);
        $expiryHours = max(1, (int) ceil(EMAIL_VERIFICATION_TTL / 3600));
        $expiryLabel = $expiryHours === 1 ? '1 hour' : $expiryHours . ' hours';
        $content = '<p style="margin:0 0 26px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'Confirm this address to protect your account and start learning. The secure link expires in '
            . $this->escape($expiryLabel) . '.</p>';
        $note = 'If you did not create this account, you can safely ignore this message. '
            . 'Do not forward this email or share the verification link.';

        $this->deliver(
            $email,
            $name,
            'Verify your email for ' . $this->subjectAppName(),
            $this->brandedHtml(
                'Confirm your email address to finish creating your account.',
                $name,
                'Verify your email address',
                $content,
                'Verify email address',
                $safeUrl,
                $note
            ),
            "Hello {$name},\n\n"
                . 'Verify your email address to finish creating your ' . $this->subjectAppName() . " account:\n"
                . $verificationUrl . "\n\n"
                . "This secure link expires in {$expiryLabel}. If you did not create this account, ignore this message.\n"
        );
    }

    /** Send a one-time, trusted-origin password reset link. */
    public function sendPasswordResetEmail(string $email, string $name, string $resetUrl): void
    {
        [$email, $name] = $this->normalizeRecipient($email, $name);
        if (!$this->urlIsTrusted($resetUrl)) {
            throw new MailerException('The password reset message is invalid.');
        }

        $safeUrl = $this->escape($resetUrl);
        $expiryMinutes = max(1, (int) ceil(PASSWORD_RESET_TTL / 60));
        $expiryHours = (int) ($expiryMinutes / 60);
        $expiryLabel = $expiryMinutes >= 60 && $expiryMinutes % 60 === 0
            ? ($expiryHours === 1 ? '1 hour' : $expiryHours . ' hours')
            : $expiryMinutes . ' minutes';
        $content = '<p style="margin:0 0 18px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'We received a request to reset the password for your learning account. Use the secure link below within '
            . $this->escape($expiryLabel) . '.</p>'
            . '<p style="margin:0 0 26px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'After the reset, sign in with your new password on every device.</p>';

        $this->deliver(
            $email,
            $name,
            'Reset your ' . $this->subjectAppName() . ' password',
            $this->brandedHtml(
                'A secure password reset was requested for your Umsad Tech account.',
                $name,
                'Reset your password',
                $content,
                'Choose a new password',
                $safeUrl,
                'If you did not request this change, ignore this email. Never share this link with anyone.'
            ),
            "Hello {$name},\n\nReset your password using this secure link:\n{$resetUrl}\n\n"
                . "The link expires in {$expiryLabel}. If you did not request it, ignore this email.\n"
        );
    }

    public function sendSignupWelcome(string $email, string $name, string $eventKey): void
    {
        [$email, $name] = $this->normalizeRecipient($email, $name);
        $this->assertEventKey($eventKey);
        $continueRawUrl = $this->trustedAppUrl('/login.php');
        $continueUrl = $this->escape($continueRawUrl);
        $content = '<p style="margin:0 0 16px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'Your Umsad Tech learning account has been created. You now have one place to discover courses, '
            . 'join live training, and keep track of your progress.</p>'
            . '<p style="margin:0 0 26px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'Please verify your email address before signing in. If the verification message has not arrived, '
            . 'you can request a fresh link from the sign-in page.</p>';

        $this->deliver(
            $email,
            $name,
            'Welcome to ' . $this->subjectAppName(),
            $this->brandedHtml(
                'Welcome to your Umsad Tech learning account.',
                $name,
                'Welcome to Umsad Tech',
                $content,
                'Go to sign in',
                $continueUrl,
                'This message was sent because an account was created with this email address.'
            ),
            "Hello {$name},\n\n"
                . "Welcome to Umsad Tech. Your learning account has been created.\n"
                . "Please verify your email address before signing in.\n\n"
                . 'Sign in: ' . $continueRawUrl . "\n",
            $eventKey
        );
    }

    public function sendCourseEnrollmentConfirmation(
        string $email,
        string $name,
        string $courseTitle,
        int $courseId,
        string $eventKey
    ): void {
        [$email, $name] = $this->normalizeRecipient($email, $name);
        $courseTitle = $this->normalizeCourseTitle($courseTitle);
        if ($courseId <= 0) {
            throw new MailerException('The enrollment message is invalid.');
        }
        $this->assertEventKey($eventKey);

        $safeTitle = $this->escape($courseTitle);
        $courseRawUrl = $this->trustedAppUrl('/student/course-lessons.php?id=' . $courseId);
        $courseUrl = $this->escape($courseRawUrl);
        $content = '<p style="margin:0 0 16px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'Your registration is received. Please wait for admin approval before learning. Course:</p>'
            . '<div style="margin:0 0 26px;padding:18px 20px;border-radius:12px;background:#f5f6fb;'
            . 'border-left:4px solid #f7931e;color:#171a55;font-size:18px;font-weight:700">'
            . $safeTitle . '</div>'
            . '<p style="margin:0 0 26px;color:#5d6078;font-size:16px;line-height:1.7">'
            . 'Open the course space to review lessons and prepare for your training.</p>';

        $this->deliver(
            $email,
            $name,
            'Your course enrollment is confirmed',
            $this->brandedHtml(
                'Your Umsad Tech course enrollment is confirmed.',
                $name,
                'You are enrolled',
                $content,
                'Open course',
                $courseUrl,
                'Keep this email for your enrollment records.'
            ),
            "Hello {$name},\n\n"
                . "Your registration for {$courseTitle} is received. Please wait for admin approval before learning.\n"
                . 'Open course: ' . $courseRawUrl . "\n",
            $eventKey
        );
    }

    public function sendPaymentConfirmation(
        string $email,
        string $name,
        string $courseTitle,
        int $courseId,
        string $amount,
        string $currency,
        string $reference,
        bool $requiresReview,
        string $eventKey
    ): void {
        [$email, $name] = $this->normalizeRecipient($email, $name);
        $courseTitle = $this->normalizeCourseTitle($courseTitle);
        $currency = strtoupper(trim($currency));
        $reference = trim($reference);
        if ($courseId <= 0
            || preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/', $amount) !== 1
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || $reference === ''
            || strlen($reference) > 100
            || preg_match('/^[A-Za-z0-9._=-]+$/', $reference) !== 1) {
            throw new MailerException('The payment message is invalid.');
        }
        $this->assertEventKey($eventKey);

        $formattedAmount = $this->formatMoney($amount, $currency);
        $safeTitle = $this->escape($courseTitle);
        $safeAmount = $this->escape($formattedAmount);
        $safeReference = $this->escape($reference);
        $courseRawUrl = $this->trustedAppUrl('/student/course-lessons.php?id=' . $courseId);
        $courseUrl = $this->escape($courseRawUrl);
        $reviewCopy = $requiresReview
            ? '<p style="margin:0 0 26px;padding:15px 17px;border-radius:10px;background:#fff7e8;color:#6b4815;'
                . 'font-size:14px;line-height:1.6">Your course access already existed when this payment completed. '
                . 'The payment is recorded, and our team will review it. Keep the reference below when contacting support.</p>'
            : '<p style="margin:0 0 26px;color:#5d6078;font-size:16px;line-height:1.7">'
                . 'Your payment was confirmed. Learning access requires admin approval.</p>';
        $content = '<div style="margin:0 0 22px;padding:18px 20px;border-radius:12px;background:#f5f6fb;color:#252640">'
            . '<div style="margin-bottom:10px"><span style="color:#777a91">Course:</span> <strong>' . $safeTitle . '</strong></div>'
            . '<div style="margin-bottom:10px"><span style="color:#777a91">Amount:</span> <strong>' . $safeAmount . '</strong></div>'
            . '<div><span style="color:#777a91">Reference:</span> <strong>' . $safeReference . '</strong></div>'
            . '</div>' . $reviewCopy;

        $plainReview = $requiresReview
            ? "Your course access already existed when this payment completed. Our team will review the recorded payment.\n"
            : "Your payment was confirmed. Learning access requires admin approval.\n";
        $this->deliver(
            $email,
            $name,
            'Your payment was successful',
            $this->brandedHtml(
                'Your Umsad Tech payment was confirmed.',
                $name,
                'Payment confirmed',
                $content,
                'Open course',
                $courseUrl,
                'For help, reply to this message or contact Umsad Tech support with your payment reference.'
            ),
            "Hello {$name},\n\n"
                . "Payment confirmed for {$courseTitle}.\n"
                . "Amount: {$formattedAmount}\nReference: {$reference}\n"
                . $plainReview
                . 'Open course: ' . $courseRawUrl . "\n",
            $eventKey
        );
    }

    /** @return array{0:string,1:string} */
    private function normalizeRecipient(string $email, string $name): array
    {
        $email = strtolower(trim($email));
        $name = trim((string) preg_replace('/[\r\n\x00]+/', ' ', $name));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 255
            || preg_match('/[\r\n\x00]/', $email)
            || $name === ''
            || mb_strlen($name) > 255) {
            throw new MailerException('The message recipient is invalid.');
        }

        return [$email, $name];
    }

    private function normalizeCourseTitle(string $courseTitle): string
    {
        $courseTitle = trim((string) preg_replace('/[\r\n\x00]+/', ' ', $courseTitle));
        if ($courseTitle === '' || mb_strlen($courseTitle) > 255) {
            throw new MailerException('The course message is invalid.');
        }

        return $courseTitle;
    }

    private function assertEventKey(string $eventKey): void
    {
        if (strlen($eventKey) > 191
            || preg_match('/^(?:signup\.welcome|enrollment\.confirmed|payment\.completed):[1-9][0-9]*$/', $eventKey) !== 1) {
            throw new MailerException('The notification identifier is invalid.');
        }
    }

    private function configuredMessage(string $email, string $name, string $eventKey = ''): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->Host = MAIL_HOST;
        $mail->Port = MAIL_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION === 'smtps'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = MAIL_TIMEOUT;
        $mail->getSMTPInstance()->Timelimit = MAIL_TIMEOUT;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->WordWrap = 78;
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        if (MAIL_REPLY_TO_ADDRESS !== '') {
            $mail->addReplyTo(MAIL_REPLY_TO_ADDRESS, MAIL_FROM_NAME);
        }
        $mail->addAddress($email, $name);
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');

        if ($eventKey !== '') {
            $fromDomain = strtolower((string) substr(strrchr(MAIL_FROM_ADDRESS, '@') ?: '@localhost', 1));
            if (filter_var($fromDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                $fromDomain = 'localhost';
            }
            $mail->MessageID = '<' . hash('sha256', $eventKey) . '@' . $fromDomain . '>';
            $mail->addCustomHeader('X-Umsad-Notification', hash('sha256', $eventKey));
        }

        return $mail;
    }

    private function deliver(
        string $email,
        string $name,
        string $subject,
        string $htmlBody,
        string $plainBody,
        string $eventKey = ''
    ): void {
        if ($subject === '' || strlen($subject) > 180 || preg_match('/[\r\n\x00]/', $subject)) {
            throw new MailerException('The message subject is invalid.');
        }

        try {
            $mail = $this->configuredMessage($email, $name, $eventKey);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $plainBody;
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new MailerException('The message could not be delivered.', 0, $exception);
        } catch (Throwable $exception) {
            if ($exception instanceof MailerException) {
                throw $exception;
            }
            throw new MailerException('The message could not be delivered.', 0, $exception);
        }
    }

    private function brandedHtml(
        string $preheader,
        string $name,
        string $headline,
        string $contentHtml,
        string $buttonLabel,
        string $safeButtonUrl,
        string $note
    ): string {
        $safePreheader = $this->escape($preheader);
        $safeName = $this->escape($name);
        $safeHeadline = $this->escape($headline);
        $safeButtonLabel = $this->escape($buttonLabel);
        $safeNote = $this->escape($note);
        $safeAppName = $this->escape($this->subjectAppName());
        $safeLogoUrl = $this->escape($this->trustedAppUrl('/assets/images/umsad-tech-logo.png'));

        return <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f5f6fb;color:#252640;font-family:Arial,sans-serif">
  <div style="display:none;max-height:0;overflow:hidden">{$safePreheader}</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f6fb;padding:32px 16px">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e5e6f2;border-radius:20px;overflow:hidden">
        <tr><td style="padding:22px 38px;background:#07145f;color:#ffffff">
          <table role="presentation" cellspacing="0" cellpadding="0"><tr>
            <td style="padding-right:13px;vertical-align:middle">
              <img src="{$safeLogoUrl}" width="87" height="58" alt="Umsad Tech logo" style="display:block;width:87px;height:58px;border:0;border-radius:9px;background:#020713;object-fit:contain">
            </td>
            <td style="vertical-align:middle;font-size:22px;font-weight:700;letter-spacing:.04em;color:#ffffff">{$safeAppName}</td>
          </tr></table>
        </td></tr>
        <tr><td style="padding:38px">
          <p style="margin:0 0 14px;font-size:16px">Hello {$safeName},</p>
          <h1 style="margin:0 0 16px;color:#171a55;font-size:30px;line-height:1.2">{$safeHeadline}</h1>
          {$contentHtml}
          <a href="{$safeButtonUrl}" style="display:inline-block;padding:15px 24px;border-radius:11px;background:#f7931e;color:#07145f;font-weight:700;text-decoration:none">{$safeButtonLabel}</a>
          <p style="margin:28px 0 0;color:#85879b;font-size:13px;line-height:1.6">{$safeNote}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function formatMoney(string $amount, string $currency): string
    {
        [$whole, $fraction] = explode('.', $amount, 2);
        $formatted = number_format((int) $whole) . '.' . $fraction;
        return $currency === 'NGN' ? '₦' . $formatted : $currency . ' ' . $formatted;
    }

    private function subjectAppName(): string
    {
        $name = trim((string) preg_replace('/[\r\n\x00]+/', ' ', APP_NAME));
        return $name === '' ? 'Umsad Tech' : mb_substr($name, 0, 120);
    }

    private function trustedAppUrl(string $path): string
    {
        $url = appUrl($path);
        if (!$this->urlIsTrusted($url)) {
            throw new MailerException('The application link is invalid.');
        }
        return $url;
    }

    private function urlIsTrusted(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\r\n\x00]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        $appParts = parse_url(APP_URL);
        if (!is_array($parts)
            || !is_array($appParts)
            || !isset($parts['scheme'], $parts['host'], $appParts['scheme'], $appParts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $appScheme = strtolower((string) $appParts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $appPort = (int) ($appParts['port'] ?? ($appScheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http', 'https'], true)
            || (APP_ENV === 'production' && $scheme !== 'https')
            || !hash_equals($appScheme, $scheme)
            || !hash_equals(strtolower((string) $appParts['host']), strtolower((string) $parts['host']))
            || $port !== $appPort) {
            return false;
        }

        $basePath = APP_BASE_PATH === '' ? '' : rtrim(APP_BASE_PATH, '/');
        $targetPath = (string) ($parts['path'] ?? '');
        return $basePath === ''
            ? str_starts_with($targetPath, '/')
            : ($targetPath === $basePath || str_starts_with($targetPath, $basePath . '/'));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
