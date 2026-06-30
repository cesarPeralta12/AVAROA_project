<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $otp;
    protected $name;

    public function __construct($otp, $name = null)
    {
        $this->otp = $otp;
        $this->name = $name;
    }

    public function build()
    {
        return $this->view('emails.sendOtp')
                ->with(['otp' => $this->otp, 'name' => $this->name])
                ->subject('Tu código de verificación — Delivery AVAROA');
    }
}
