@if ($imports->isNotEmpty())
    <div class="mt-8">
        <p class="text-sm font-semibold text-slate-700">{{ $historyTitle ?? 'Importaciones recientes' }}</p>
        <div class="mt-3 overflow-hidden rounded-xl border border-slate-200">
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
                            $linkClass = $import->isStockPriceMode()
                                ? 'font-medium text-emerald-600 hover:text-emerald-800'
                                : 'font-medium text-indigo-600 hover:text-indigo-800';
                        @endphp
                        <tr>
                            <td class="px-4 py-3 text-slate-700">{{ $import->original_filename }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-amber-100 text-amber-800' => in_array($import->status, ['pending', 'processing']),
                                    'bg-emerald-100 text-emerald-800' => $import->status === 'completed',
                                    'bg-red-100 text-red-800' => $import->status === 'failed',
                                ])>{{ $import->statusLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-xs">
                                <a href="{{ $viewRoute }}" class="{{ $linkClass }}">Ver</a>
                                @if ($import->image_download_log_path)
                                    · <a href="{{ route('inventory.import.images.log.download', $import) }}" class="text-slate-600 hover:text-slate-900">Log imágenes</a>
                                @endif
                                @if ($import->wp_sync_log_path)
                                    · <a href="{{ route('inventory.import.wp.log.download', $import) }}" class="text-violet-700 hover:text-violet-900">Log WordPress</a>
                                @endif
                                @if ($import->skipped_rows_csv_path)
                                    · <a href="{{ route('inventory.import.skipped.download', $import) }}" class="text-amber-700 hover:text-amber-900">CSV omitidas</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
