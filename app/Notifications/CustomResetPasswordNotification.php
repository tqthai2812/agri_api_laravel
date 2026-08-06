<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomResetPasswordNotification extends Notification
{
    use Queueable;

    public string $token;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url(config('app.frontend_url') . '/reset-password/' . $this->token . '?email=' . $notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Yêu cầu khôi phục mật khẩu tài khoản') // Tiêu đề Email
            ->greeting('Xin chào ' . $notifiable->name . '!')
            ->line('Chúng tôi nhận được yêu cầu khôi phục mật khẩu từ bạn.')
            ->action('Đổi mật khẩu mới', $url) // Nút bấm
            ->line('Đường dẫn này sẽ hết hạn trong vòng 60 phút.')
            ->line('Nếu bạn không yêu cầu điều này, vui lòng bỏ qua email này.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
