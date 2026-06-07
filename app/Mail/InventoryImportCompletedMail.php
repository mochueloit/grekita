<?php

namespace App\Mail;

use App\Models\InventoryImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InventoryImportCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly InventoryImport $import,
        public readonly array $summary = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Grekita — Proceso completado #'.$this->import->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.inventory-import-completed',
        );
    }
}
