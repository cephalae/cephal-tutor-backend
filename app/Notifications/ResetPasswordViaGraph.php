<?php

namespace App\Notifications;

use App\Notifications\Channels\MicrosoftGraphMailChannel;
use Illuminate\Notifications\Notification;

class ResetPasswordViaGraph extends Notification
{
    public function __construct(private readonly string $token) {}

    public function via($notifiable): array
    {
        return [MicrosoftGraphMailChannel::class];
    }

    public function toMicrosoftGraphMail($notifiable): array
    {
        $frontend = rtrim(config('app.frontend_url', env('FRONTEND_URL', '')), '/');
        $path = env('FRONTEND_RESET_PATH', '/reset-password');

        $email = urlencode($notifiable->getEmailForPasswordReset());
        $token = urlencode($this->token);

        $resetUrl = $frontend ? "{$frontend}{$path}?token={$token}&email={$email}" : null;

        $html = "
        <div style='font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e9e9e9; border-radius: 8px; padding: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>
          <h2 style='color: #2c3e50; margin-top: 0; font-weight: 400; font-size: 28px; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px;'>Password Reset</h2>
          <p style='color: #4a4a4a; font-size: 16px; line-height: 1.6; margin: 20px 0;'>We received a request to reset your password. Click the button below to proceed.</p>
          " . ($resetUrl ? "
          <p style='text-align: center; margin: 30px 0;'>
            <a href='{$resetUrl}' style='display: inline-block; background-color: #3498db; color: #ffffff; text-decoration: none; padding: 14px 30px; border-radius: 50px; font-weight: 500; font-size: 16px; box-shadow: 0 2px 5px rgba(52,152,219,0.3); transition: background-color 0.3s;'>Reset Password</a>
          </p>" : "") . "
          <p style='color: #7f8c8d; font-size: 14px; line-height: 1.5; margin: 20px 0 0; border-top: 1px solid #f0f0f0; padding-top: 20px;'>If you didn’t request this password reset, you can safely ignore this email. Your password will remain unchanged.</p>
        </div>
        ";

        return [
            'from' => env('MS_GRAPH_FROM'), // optional override
            'to' => $notifiable->email,
            'subject' => 'Reset your password',
            'html' => $html,
        ];
    }
}
