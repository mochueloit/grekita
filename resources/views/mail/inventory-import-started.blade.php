<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importación iniciada</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.5;">
    <h2 style="color: #4f46e5;">Grekita — Importación iniciada</h2>

    <p>Se inició el procesamiento de un archivo de inventario.</p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
        <tr><td><strong>ID importación</strong></td><td>#{{ $import->id }}</td></tr>
        <tr><td><strong>Archivo</strong></td><td>{{ $import->original_filename }}</td></tr>
        <tr><td><strong>Filas detectadas</strong></td><td>{{ $import->total_rows ?? '—' }}</td></tr>
        <tr><td><strong>Inicio</strong></td><td>{{ $import->started_at?->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s') }}</td></tr>
    </table>

    <p style="margin-top: 20px;">
        <a href="{{ url('/inventory/import?import='.$import->id) }}" style="color: #4f46e5;">Ver progreso en el panel</a>
    </p>

    <p style="font-size: 12px; color: #64748b;">Mensaje automático de Grekita Inventario.</p>
</body>
</html>
