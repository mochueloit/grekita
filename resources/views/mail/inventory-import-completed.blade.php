<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proceso completado</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.5;">
    <h2 style="color: #059669;">Grekita — Proceso completado</h2>

    <p>El flujo de importación finalizó (inventario, imágenes, XML y WordPress si aplica).</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
        <tr><td><strong>ID importación</strong></td><td>#{{ $import->id }}</td></tr>
        <tr><td><strong>Archivo</strong></td><td>{{ $import->original_filename }}</td></tr>
        <tr><td><strong>Inicio</strong></td><td>{{ $import->started_at?->format('d/m/Y H:i:s') ?? '—' }}</td></tr>
        <tr><td><strong>Fin</strong></td><td>{{ isset($summary['finished_at']) ? \Illuminate\Support\Carbon::parse($summary['finished_at'])->format('d/m/Y H:i:s') : ($import->completed_at?->format('d/m/Y H:i:s') ?? '—') }}</td></tr>
        @if (! empty($summary['duration']))
            <tr><td><strong>Duración</strong></td><td>{{ $summary['duration'] }}</td></tr>
        @endif
        <tr><td><strong>Productos creados</strong></td><td>{{ $summary['stats']['created'] ?? ($import->stats['created'] ?? 0) }}</td></tr>
        <tr><td><strong>Productos actualizados</strong></td><td>{{ $summary['stats']['updated'] ?? ($import->stats['updated'] ?? 0) }}</td></tr>
        <tr><td><strong>Imágenes en cola</strong></td><td>{{ $summary['stats']['images_queued'] ?? ($import->stats['images_queued'] ?? 0) }}</td></tr>
        @if (! empty($summary['wp_last_message']))
            <tr><td><strong>Último mensaje WordPress</strong></td><td>{{ $summary['wp_last_message'] }}</td></tr>
        @endif
    </table>

    <p style="margin-top: 20px;">
        <a href="{{ url('/inventory/import?import='.$import->id) }}" style="color: #4f46e5;">Ver detalle en el panel</a>
    </p>

    <p style="font-size: 12px; color: #64748b;">Mensaje automático de Grekita Inventario.</p>
</body>
</html>
