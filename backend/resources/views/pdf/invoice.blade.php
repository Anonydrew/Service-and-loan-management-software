@extends('pdf.layout')
@php $m = fn ($v) => 'GHS '.number_format(((int) $v) / 100, 2); @endphp
@section('title', 'Invoice')
@section('subtitle', $invoice->invoice_number.' · '.strtoupper(str_replace('_', ' ', $invoice->status)))
@section('content')
<table style="width:100%">
    <tr>
        <td style="vertical-align:top">
            <div class="muted">Bill to</div>
            <strong>{{ $invoice->customer->display_name }}</strong><br>
            {{ $invoice->customer->customer_number }}<br>
            {{ $invoice->customer->address }}<br>{{ $invoice->customer->phone }}
        </td>
        <td class="right" style="vertical-align:top">
            <table class="kv" style="float:right">
                <tr><td class="muted">Issue date</td><td>{{ $invoice->issue_date->format('d M Y') }}</td></tr>
                <tr><td class="muted">Due date</td><td>{{ $invoice->due_date->format('d M Y') }}</td></tr>
                <tr><td class="muted">Branch</td><td>{{ $invoice->branch?->name }}</td></tr>
                @if($company->tin)<tr><td class="muted">TIN</td><td>{{ $company->tin }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>
<table class="grid" style="margin-top:14px">
    <tr><th>Description</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Discount</th><th class="right">Tax</th><th class="right">Amount</th></tr>
    @foreach($invoice->items as $item)
        <tr>
            <td>{{ $item->description }}</td><td class="right">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
            <td class="right">{{ $m($item->unit_price_minor) }}</td><td class="right">{{ $m($item->discount_minor) }}</td>
            <td class="right">{{ $m($item->tax_minor) }}</td><td class="right">{{ $m($item->line_total_minor) }}</td>
        </tr>
    @endforeach
</table>
<table class="grid" style="width:45%; float:right; margin-top:10px">
    <tr><td>Subtotal</td><td class="right">{{ $m($invoice->subtotal_minor) }}</td></tr>
    <tr><td>Discount</td><td class="right">-{{ $m($invoice->discount_minor) }}</td></tr>
    <tr><td>Tax</td><td class="right">{{ $m($invoice->tax_minor) }}</td></tr>
    <tr class="total"><td>Total</td><td class="right">{{ $m($invoice->total_minor) }}</td></tr>
    <tr><td>Paid</td><td class="right">{{ $m($invoice->amount_paid_minor) }}</td></tr>
    <tr class="total"><td>Balance due</td><td class="right">{{ $m($invoice->balance_minor) }}</td></tr>
</table>
<div style="clear:both"></div>
@if($invoice->notes)<p class="muted">{{ $invoice->notes }}</p>@endif
@endsection
