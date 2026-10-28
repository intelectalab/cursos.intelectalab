<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $organization }}</title>
    <style>
        @page { margin: 22px 26px 34px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1e293b; }
        .head { width: 100%; border-bottom: 3px solid {{ $brand }}; padding-bottom: 8px; margin-bottom: 10px; }
        .head td { vertical-align: middle; }
        .logo { height: 34px; max-width: 120px; margin-right: 10px; vertical-align: middle; }
        .organization { font-size: 15px; font-weight: bold; color: {{ $brand }}; }
        .meta { font-size: 8px; color: #64748b; text-align: right; }
        h2 { font-size: 12px; margin: 14px 0 6px; color: #0f172a; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: {{ $brand }}; color: #ffffff; text-align: left; padding: 5px 4px; font-size: 8.5px; }
        table.data td { padding: 4px; border-bottom: 1px solid #e2e8f0; }
        table.data tr.alt td { background: {{ $light }}; }
        table.data .num { text-align: right; white-space: nowrap; }
        table.data tr.total td { font-weight: bold; border-top: 2px solid {{ $brand }}; border-bottom: 0; }
        .empty { color: #64748b; font-style: italic; }
        .foot { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 7.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="">
                @endif
                <span class="organization">{{ $organization }}</span>
            </td>
            <td class="meta">
                Generado el {{ $generated }}
                @if ($filters)
                    <br>{{ $filters }}
                @endif
            </td>
        </tr>
    </table>

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @if ($section['rows'] === [])
            <p class="empty">Sin datos con estos filtros.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($section['headers'] as $index => $header)
                            <th class="{{ in_array($index, $section['money'], true) ? 'num' : '' }}">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $position => $row)
                        <tr class="{{ $position % 2 === 1 ? 'alt' : '' }}">
                            @foreach ($section['headers'] as $index => $header)
                                @php($value = $row[$index] ?? null)
                                @if (in_array($index, $section['money'], true))
                                    <td class="num">{{ is_numeric($value) ? number_format((float) $value, 2, '.', ',') : $value }}</td>
                                @else
                                    <td>{{ $value }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($section['totals'] !== [])
                        <tr class="total">
                            @foreach ($section['headers'] as $index => $header)
                                @if (array_key_exists($index, $section['totals']))
                                    <td class="num">{{ number_format($section['totals'][$index], 2, '.', ',') }}</td>
                                @else
                                    <td>{{ $loop->first ? 'Total' : '' }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="foot">{{ $organization }} · {{ $generated }}</div>
</body>
</html>
