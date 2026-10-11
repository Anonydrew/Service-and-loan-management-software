@extends('pdf.layout')
@php
    $m = fn ($v) => 'GHS '.number_format(((int) $v) / 100, 2);
    $c = $loan->customer;
@endphp
@section('title', 'Loan Agreement')
@section('subtitle', $loan->loan_number)
@section('content')
<h2>Parties</h2>
<table class="kv">
    <tr><td class="muted">Lender</td><td>{{ $company->legal_name ?: $company->name }}, {{ $loan->branch?->name }} branch</td></tr>
    <tr><td class="muted">Borrower</td><td>{{ $c->display_name }} ({{ $c->customer_number }})</td></tr>
    <tr><td class="muted">Address</td><td>{{ $c->address }} {{ $c->digital_address }}</td></tr>
    <tr><td class="muted">Phone</td><td>{{ $c->phone }}</td></tr>
    @if($c->isBusiness())
        <tr><td class="muted">Registration no.</td><td>{{ $c->registration_number }}</td></tr>
        <tr><td class="muted">Signatories</td><td>{{ $c->representatives->where('is_signatory', true)->pluck('full_name')->implode(', ') }}</td></tr>
    @endif
</table>

<h2>Loan terms</h2>
<table class="kv">
    <tr><td class="muted">Product</td><td>{{ $loan->product?->name }}</td></tr>
    <tr><td class="muted">Principal</td><td>{{ $m($loan->principal_minor) }}</td></tr>
    <tr><td class="muted">Interest</td><td>{{ rtrim(rtrim($loan->interest_rate, '0'), '.') }}% {{ str_replace('_', ' ', $loan->rate_period) }} ({{ str_replace('_', ' ', $loan->interest_method) }})</td></tr>
    <tr><td class="muted">Processing fee</td><td>{{ $m($loan->fee_minor) }} ({{ $loan->fee_collection === 'deducted' ? 'deducted from the amount disbursed' : 'added to repayments' }})</td></tr>
    <tr><td class="muted">Amount to be paid out</td><td>{{ $m($loan->net_disbursed_minor) }}</td></tr>
    <tr><td class="muted">Repayment</td><td>{{ $loan->term }} {{ $loan->repayment_frequency }} installments</td></tr>
    <tr><td class="muted">Total interest</td><td>{{ $m($loan->total_interest_minor) }}</td></tr>
    <tr><td class="muted">Total repayable</td><td><strong>{{ $m($loan->total_payable_minor) }}</strong></td></tr>
    <tr><td class="muted">Late payment</td><td>
        @if($loan->penalty_type === 'fixed') GHS {{ number_format((float) $loan->penalty_value, 2) }} @else {{ rtrim(rtrim($loan->penalty_value, '0'), '.') }}% of the overdue amount @endif
        per installment unpaid {{ $loan->grace_period_days }} day(s) after its due date.
    </td></tr>
</table>

@if($loan->installments->isNotEmpty())
<h2>Repayment schedule</h2>
<table class="grid">
    <tr><th>#</th><th>Due date</th><th class="right">Principal</th><th class="right">Interest</th><th class="right">Fees</th><th class="right">Installment</th></tr>
    @foreach($loan->installments as $i)
        <tr>
            <td>{{ $i->installment_number }}</td><td>{{ $i->due_date->format('d M Y') }}</td>
            <td class="right">{{ $m($i->principal_due_minor) }}</td><td class="right">{{ $m($i->interest_due_minor) }}</td>
            <td class="right">{{ $m($i->fees_due_minor) }}</td><td class="right">{{ $m($i->principal_due_minor + $i->interest_due_minor + $i->fees_due_minor) }}</td>
        </tr>
    @endforeach
</table>
@else
<p class="muted">The repayment schedule is generated on the disbursement date and will be provided with the disbursement confirmation.</p>
@endif

<h2>Declarations</h2>
<p>The Borrower confirms that the information provided in the application is true, agrees to repay the loan in accordance with the schedule above, and accepts the late payment charges stated. Repayments are applied first to penalties, then fees, then interest, then principal. The Borrower may repay early at any time.</p>

<table class="sign">
    <tr>
        <td>Borrower / authorised signatory<br>Name, signature and date</td>
        <td style="padding-left:20px">For {{ $company->name }}<br>Name, signature and date</td>
    </tr>
</table>
@endsection
