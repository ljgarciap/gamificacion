<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Código de ingreso de la muestra (SPEC-006A). Texto plano, sin enlaces y sin datos de la persona: solo el código y su vigencia. */
final class DemoLoginCodeMail extends Mailable
{
    public function __construct(public readonly string $code, public readonly int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código para ingresar a la muestra');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.demo-login-code', with: ['code' => $this->code, 'minutes' => $this->minutes]);
    }
}
