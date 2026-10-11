<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            AuditLog::query()
                ->where('company_id', Tenant::id())
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->input('action').'%'))
                ->when($request->filled('subject'), fn ($q) => $q->where('auditable_type', $request->input('subject')))
                ->when($request->filled('subject_id'), fn ($q) => $q->where('auditable_id', $request->integer('subject_id')))
                ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->input('from').' 00:00:00'))
                ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->input('to').' 23:59:59'))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', '%'.$request->input('q').'%')
                    ->orWhere('actor_label', 'like', '%'.$request->input('q').'%')))
                ->latest('id')
                ->paginate($this->perPage($request))
        );
    }
}
