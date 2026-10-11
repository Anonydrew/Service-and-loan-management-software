<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 11px; color: #1f2937; margin: 0; }
    h1 { font-size: 18px; margin: 0 0 4px; color: #0f172a; }
    h2 { font-size: 13px; margin: 18px 0 6px; color: #0f172a; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
    .muted { color: #6b7280; }
    .header { border-bottom: 3px solid #0b6e4f; padding-bottom: 10px; margin-bottom: 14px; }
    .header table { width: 100%; }
    .company { font-size: 15px; font-weight: bold; color: #0b6e4f; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.grid th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 10px; text-transform: uppercase; color: #4b5563; }
    table.grid td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; }
    .right { text-align: right; }
    .kv td { padding: 2px 8px 2px 0; vertical-align: top; }
    .total td { font-weight: bold; border-top: 2px solid #111827; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #ecfdf5; color: #065f46; font-size: 10px; }
    .sign { margin-top: 36px; width: 100%; }
    .sign td { width: 50%; padding-top: 34px; border-top: 1px solid #9ca3af; font-size: 10px; }
    .footer { margin-top: 24px; font-size: 9px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
<div class="header">
    <table>
        <tr>
            <td>
                <div class="company">{{ $company->name }}</div>
                <div class="muted">{{ $company->address }} {{ $company->phone ? '· '.$company->phone : '' }} {{ $company->email ? '· '.$company->email : '' }}</div>
            </td>
            <td class="right"><h1>@yield('title')</h1><div class="muted">@yield('subtitle')</div></td>
        </tr>
    </table>
</div>
@yield('content')
<div class="footer">Generated {{ now()->format('d M Y H:i') }} · {{ $company->name }}</div>
</body>
</html>
