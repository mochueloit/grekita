@if ($imports->isNotEmpty())
    <div class="mt-6">
        <p class="text-sm font-semibold text-slate-800">{{ $historyTitle ?? 'Importaciones recientes' }}</p>
        <div class="mt-3 overflow-hidden rounded-lg border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">Archivo</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">Estado</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">Fecha</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">Archivos</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($imports as $import)
                        @php
                            $viewRoute = $import->isStockPriceMode()
                                ? route('inventory.import.stock-price.show', ['import' => $import->id])
                                : route('inventory.import.show', ['import' => $import->id]);
                        @endphp
                        <tr>
                            <td class="px-4 py-3 text-slate-700">{{ $import->original_filename }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full border px-2.5 py-1 text-xs font-medium',
                                    'border-slate-300 bg-slate-100 text-slate-700' => in_array($import->status, ['pending', 'processing']),
                                    'border-slate-900 bg-slate-900 text-white' => $import->status === 'completed',
                                    'border-slate-400 bg-slate-200 text-slate-900' => $import->status === 'failed',
                                ])>{{ $import->statusLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-xs">
                                <a href="{{ $viewRoute }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900">Ver</a>
                                @if ($import->image_download_log_path)
                                    · <a href="{{ route('inventory.import.images.log.download', $import) }}" class="text-slate-600 hover:text-slate-900">Log imagenes</a>
                                @endif
                                @if ($import->wp_sync_log_path)
                                    · <a href="{{ route('inventory.import.wp.log.download', $import) }}" class="text-slate-600 hover:text-slate-900">Log WordPress</a>
                                @endif
                                @if ($import->skipped_rows_csv_path)
                                    · <a href="{{ route('inventory.import.skipped.download', $import) }}" class="text-slate-600 hover:text-slate-900">CSV omitidas</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
