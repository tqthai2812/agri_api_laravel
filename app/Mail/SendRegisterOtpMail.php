<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendRegisterOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    // Khai báo public thì tự động file Blade view sẽ nhận được biến $code mà không cần ->with()
    public string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mã OTP đăng ký tài khoản', // Tiêu đề hiển thị trong hòm thư khách hàng
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.register-otp',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
