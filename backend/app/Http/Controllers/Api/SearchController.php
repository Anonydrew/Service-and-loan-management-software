<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Loan;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Global top-bar search. Each group only appears if the user may view it. */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $user = $request->user();
        $results = [];

        if ($user->hasPermission('customers.view')) {
            foreach (Customer::query()->where(fn ($w) => $w->where('display_name', 'like', $term)->orWhere('customer_number', 'like', $term)->orWhere('phone', 'like', $term))
                ->limit(6)->get(['id', 'display_name', 'customer_number', 'phone', 'type']) as $c) {
                $results[] = ['type' => 'customer', 'id' => $c->id, 'title' => $c->display_name, 'subtitle' => "{$c->customer_number} · {$c->phone}", 'link' => "/customers/{$c->id}"];
            }
        }

        if ($user->hasPermission('loans.view')) {
            foreach (Loan::query()->where('loan_number', 'like', $term)->with('customer:id,display_name')->limit(5)->get() as $l) {
                $results[] = ['type' => 'loan', 'id' => $l->id, 'title' => $l->loan_number, 'subtitle' => $l->customer?->display_name.' · '.str_replace('_', ' ', $l->status), 'link' => "/loans/{$l->id}"];
            }
        }

        if ($user->hasPermission('invoices.view')) {
            foreach (Invoice::query()->where('invoice_number', 'like', $term)->with('customer:id,display_name')->limit(5)->get() as $i) {
                $results[] = ['type' => 'invoice', 'id' => $i->id, 'title' => $i->invoice_number, 'subtitle' => $i->customer?->display_name.' · '.$i->status, 'link' => "/billing/invoices/{$i->id}"];
            }
        }

        if ($user->hasPermission('service_requests.view')) {
            foreach (ServiceRequest::query()->where('request_number', 'like', $term)->with('customer:id,display_name')->limit(5)->get() as $s) {
                $results[] = ['type' => 'service_request', 'id' => $s->id, 'title' => $s->request_number, 'subtitle' => $s->customer?->display_name.' · '.str_replace('_', ' ', $s->status), 'link' => "/services/requests/{$s->id}"];
            }
        }

        return response()->json(['results' => $results]);
    }
}
