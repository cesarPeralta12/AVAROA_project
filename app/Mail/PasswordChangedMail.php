<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $name;

    public function __construct($name = null)
    {
        $this->name = $name;
    }

    public function build()
    {
        return $this->view('emails.password_changed')
                ->with(['name' => $this->name])
                ->subject('Tu contraseña fue actualizada — Delivery AVAROA');
    }
}
