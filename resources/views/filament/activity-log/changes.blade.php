@if ($rows === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada rincian kolom.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pe-4 font-medium">Kolom</th>
                    <th class="py-2 pe-4 font-medium">Sebelum</th>
                    <th class="py-2 font-medium">Sesudah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-gray-100 align-top dark:border-white/5">
                        <td class="py-1.5 pe-4 font-mono text-xs">{{ $row['field'] }}</td>
                        <td class="py-1.5 pe-4 text-danger-600 dark:text-danger-400">{{ $row['old'] }}</td>
                        <td class="py-1.5 text-success-600 dark:text-success-400">{{ $row['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
