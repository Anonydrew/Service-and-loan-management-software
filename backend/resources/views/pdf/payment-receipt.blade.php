@extends('pdf.layout')
@php $m = fn ($v) => 'GHS '.number_format(((int) $v) / 100, 2); @endphp
@section('title', 'Payment Receipt')
@section('subtitle', $payment->receipt_number)
@section('content')
<table class="kv">
    <tr><td class="muted">Customer</td><td>{{ $payment->customer->display_name }} ({{ $payment->customer->customer_number }})</td></tr>
    <tr><td class="muted">Invoice</td><td>{{ $payment->invoice->invoice_number }}</td></tr>
    <tr><td class="muted">Date</td><td>{{ $payment->paid_on->format('d M Y') }}</td></tr>
    <tr><td class="muted">Method</td><td>{{ ucwords(str_replace('_', ' ', $payment->payment_method)) }} {{ $payment->reference ? '· '.$payment->reference : '' }}</td></tr>
    <tr><td class="muted">Received by</td><td>{{ $payment->receivedBy?->name }}</td></tr>
</table>
<table class="grid" style="margin-top:12px">
    <tr class="total"><td>Amount received</td><td class="right">{{ $m($payment->amount_minor) }}</td></tr>
    @if($payment->refunded_minor > 0)<tr><td>Refunded</td><td class="right">-{{ $m($payment->refunded_minor) }}</td></tr>@endif
    <tr><td>Invoice balance after payment</td><td class="right">{{ $m($payment->invoice->balance_minor) }}</td></tr>
</table>
@endsection
