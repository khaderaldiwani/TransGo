<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $userName;

    public function __construct($code, $userName = null)
    {
        $this->code = $code;
        $this->userName = $userName;
    }

    public function build()
    {
        return $this->subject('رمز التحقق - شركة TransGo')
                    ->markdown('emails.otp')
                    ->with([
                        'code' => $this->code,
                        'userName' => $this->userName,
                    ]);
    }
}
