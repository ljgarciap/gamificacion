<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo de prueba que el superadmin pide desde el panel para comprobar el envío (SPEC-006A). */
final class DemoTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prueba de correo de la muestra');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.demo-test');
    }
}
