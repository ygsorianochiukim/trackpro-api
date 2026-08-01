<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Storefront password-reset email. The link points at the Next.js frontend
 * (`FRONTEND_URL/reset-password`) — the API has no UI of its own — and carries
 * the token plus email that `POST /api/customer/password/reset` expects.
 */
class CustomerResetPassword extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
        // FRONTEND_URL may list several origins for CORS — the email needs one.
        $frontend = trim(explode(',', $frontend)[0]);

        $url = $frontend . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = Config::get('auth.passwords.customers.expire', 60);

        return (new MailMessage)
            ->subject('Reset your TrackPro password')
            ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
            ->line('We received a request to reset the password for your TrackPro account.')
            ->action('Reset Password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
