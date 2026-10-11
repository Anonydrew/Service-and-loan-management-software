@extends('pdf.layout')
@php $m = fn ($v) => 'GHS '.number_format(((int) $v) / 100, 2); @endphp
@section('title', 'Repayment Receipt')
@section('subtitle', $repayment->receipt_number)
@section('content')
@if($repayment->status === 'reversed')
    <p><span class="badge" style="background:#fef2f2;color:#991b1b">REVERSED {{ $repayment->reversed_at?->format('d M Y') }}</span></p>
@endif
<table class="kv">
    <tr><td class="muted">Customer</td><td>{{ $repayment->customer->display_name }} ({{ $repayment->customer->customer_number }})</td></tr>
    <tr><td class="muted">Loan</td><td>{{ $repayment->loan->loan_number }}</td></tr>
    <tr><td class="muted">Date</td><td>{{ $repayment->paid_on->format('d M Y') }}</td></tr>
    <tr><td class="muted">Method</td><td>{{ ucwords(str_replace('_', ' ', $repayment->payment_method)) }} {{ $repayment->reference ? '· '.$repayment->reference : '' }}</td></tr>
    <tr><td class="muted">Branch</td><td>{{ $repayment->branch?->name }}</td></tr>
    <tr><td class="muted">Received by</td><td>{{ $repayment->receivedBy?->name }}</td></tr>
</table>
<table class="grid" style="margin-top:12px">
    <tr><th>Applied to</th><th class="right">Amount</th></tr>
    <tr><td>Penalties</td><td class="right">{{ $m($repayment->penalty_minor) }}</td></tr>
    <tr><td>Fees</td><td class="right">{{ $m($repayment->fees_minor) }}</td></tr>
    <tr><td>Interest</td><td class="right">{{ $m($repayment->interest_minor) }}</td></tr>
    <tr><td>Principal</td><td class="right">{{ $m($repayment->principal_minor) }}</td></tr>
    <tr class="total"><td>Total received</td><td class="right">{{ $m($repayment->amount_minor) }}</td></tr>
</table>
<p style="margin-top:12px">Outstanding loan balance after this payment: <strong>{{ $m($outstanding) }}</strong></p>
@endsection
