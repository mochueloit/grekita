<?php

namespace App\Services\Inventory;

use App\Mail\InventoryImportCompletedMail;
use App\Mail\InventoryImportStartedMail;
use App\Models\InventoryImport;
use Illuminate\Support\Facades\Mail;

class InventoryImportNotifier
{
    public function notifyStarted(InventoryImport $import): void
    {
        if (! $this->isEnabled() || ! $this->shouldNotifyStart()) {
            return;
        }

        if ($this->alreadySent($import, 'start')) {
            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(new InventoryImportStartedMail($import->fresh() ?? $import));

        $this->markSent($import, 'start');

        (new InventoryImportProgress($import->id))->log(
            'Aviso por correo enviado: inicio de importación.',
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function notifyCompleted(InventoryImport $import, array $summary = []): void
    {
        if (! $this->isEnabled() || ! $this->shouldNotifyComplete()) {
            return;
        }

        if ($this->alreadySent($import, 'complete')) {
            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        $fresh = $import->fresh() ?? $import;

        Mail::to($recipients)->queue(new InventoryImportCompletedMail($fresh, $summary));

        $this->markSent($import, 'complete');

        (new InventoryImportProgress($import->id))->log(
            'Aviso por correo enviado: proceso completado.',
        );
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $raw = (string) config('inventory.notify_emails', '');

        return array_values(array_filter(array_map(
            static fn (string $email): string => trim($email),
            explode(',', $raw),
        )));
    }

    public function isEnabled(): bool
    {
        return (bool) config('inventory.notify_enabled', false)
            && $this->recipients() !== [];
    }

    private function shouldNotifyStart(): bool
    {
        return (bool) config('inventory.notify_on_start', true);
    }

    private function shouldNotifyComplete(): bool
    {
        return (bool) config('inventory.notify_on_complete', true);
    }

    private function alreadySent(InventoryImport $import, string $type): bool
    {
        $notifications = ($import->checkpoint ?? [])['notifications'] ?? [];

        return isset($notifications[$type.'_sent_at']);
    }

    private function markSent(InventoryImport $import, string $type): void
    {
        $checkpoint = $import->checkpoint ?? [];
        $checkpoint['notifications'] = array_merge($checkpoint['notifications'] ?? [], [
            $type.'_sent_at' => now()->toIso8601String(),
        ]);

        $import->update(['checkpoint' => $checkpoint]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCompletionSummary(InventoryImport $import): array
    {
        $stats = $import->stats ?? $import->partial_stats ?? [];
        $pipeline = ($import->checkpoint ?? [])['wp_pipeline'] ?? [];

        $startedAt = $import->started_at;
        $finishedAt = $pipeline['finished_at'] ?? $import->completed_at?->toIso8601String();
        $duration = null;

        if ($startedAt !== null && $finishedAt !== null) {
            $duration = $startedAt->diffForHumans(
                \Illuminate\Support\Carbon::parse($finishedAt),
                true,
            );
        }

        return [
            'stats' => $stats,
            'wp_last_message' => $pipeline['last_message'] ?? null,
            'wp_finished' => (bool) ($pipeline['finished'] ?? false),
            'duration' => $duration,
            'finished_at' => $finishedAt,
        ];
    }
}
