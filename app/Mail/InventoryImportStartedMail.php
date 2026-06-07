<?php

namespace App\Mail;

use App\Models\InventoryImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InventoryImportStartedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly InventoryImport $import,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Grekita — Importación iniciada #'.$this->import->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.inventory-import-started',
        );
    }
}
