<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectionActivity;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    /** Loans in arrears with ageing bucket, overdue amount and last contact. */
    public function arrears(Request $request): JsonResponse
    {
        $loans = Loan::query()
            ->where('status', 'in_arrears')
            ->with([
                'customer:id,display_name,customer_number,phone',
                'branch:id,name',
                'loanOfficer:id,name',
                'installments',
                'collectionActivities' => fn ($q) => $q->limit(1),
            ])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('bucket'), function ($q) use ($request): void {
                [$min, $max] = match ($request->input('bucket')) {
                    '1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], default => [91, 100000],
                };
                $q->whereBetween('days_in_arrears', [$min, $max]);
            })
            ->orderByDesc('days_in_arrears')
            ->paginate($this->perPage($request));

        $loans->getCollection()->transform(function (Loan $loan) {
            $overdue = $loan->installments->filter(fn ($i) => $i->status !== 'paid' && $i->due_date->isPast());
            $loan->setAttribute('overdue_minor', $overdue->sum(fn ($i) => $i->balance_minor));
            $loan->setAttribute('outstanding_minor', $loan->outstandingBalance());
            $loan->setAttribute('bucket', match (true) {
                $loan->days_in_arrears <= 30 => '1-30',
                $loan->days_in_arrears <= 60 => '31-60',
                $loan->days_in_arrears <= 90 => '61-90',
                default => '90+',
            });
            $loan->setAttribute('last_activity', $loan->collectionActivities->first());
            $loan->unsetRelation('installments')->unsetRelation('collectionActivities');

            return $loan;
        });

        return response()->json($loans);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $data = $this->minor($request->validate([
            'activity_type' => ['required', Rule::in(CollectionActivity::TYPES)],
            'outcome' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'promise_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:activity_type,promise_to_pay'],
            'promise_amount' => ['nullable', 'numeric', 'min:0', 'required_if:activity_type,promise_to_pay'],
            'next_action_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]), ['promise_amount' => 'promise_amount_minor']);

        $activity = new CollectionActivity($data);
        $activity->loan()->associate($loan);
        $activity->user_id = $request->user()->id;
        $activity->save();

        return response()->json($activity->load('user:id,name'), 201);
    }
}
