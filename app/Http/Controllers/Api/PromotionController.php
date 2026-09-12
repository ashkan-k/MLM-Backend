<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromotionRequest;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = PromotionRequest::query()->with(['user', 'fromRole', 'targetRole', 'criteria', 'feedback']);

        if (! $user->isSuperuser() && ! $user->hasRole('senior_manager')) {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->latest()->get());
    }

    public function eligibility(Request $request, PromotionService $service)
    {
        $target = $request->query('target', 'sales_manager');

        return response()->json($service->evaluate($request->user(), $target));
    }

    public function store(Request $request, PromotionService $service)
    {
        $data = $request->validate([
            'from_role' => ['required', 'string'],
            'target_role' => ['required', 'string'],
        ]);

        return response()->json($service->request($request->user(), $data['from_role'], $data['target_role']), 201);
    }

    public function decide(Request $request, PromotionRequest $promotion, PromotionService $service)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string'],
        ]);

        return response()->json($service->decide($request->user(), $promotion, $data['decision'], $data['note'] ?? ''));
    }
}
