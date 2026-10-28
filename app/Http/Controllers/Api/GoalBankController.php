<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoalBankItem;
use App\Models\User;
use App\Services\Goals\GoalBankService;
use Illuminate\Http\Request;

class GoalBankController extends Controller
{
    public function me(Request $request, GoalBankService $goals)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');

        return response()->json([
            'goal_bank' => $goals->featureState($user, $role),
        ]);
    }

    public function index(Request $request, GoalBankService $goals)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:available,in_progress,completed,failed'],
            'scope' => ['nullable', 'string', 'in:all,mine,available,in_progress,history'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $page = $goals->listItems(
            $user,
            $role,
            $data['status'] ?? null,
            $data['search'] ?? null,
            $data['scope'] ?? 'all',
            (int) ($data['per_page'] ?? 20),
            isset($data['user_id']) ? (int) $data['user_id'] : null,
        );

        return response()->json([
            'goal_bank' => $goals->featureState($user, $role),
            'data' => $page->getCollection()->map(fn (GoalBankItem $item) => $goals->serialize($item))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request, GoalBankService $goals)
    {
        $data = $this->validatedItem($request);
        $item = $goals->create($request->user(), $data);

        return response()->json(['item' => $goals->serialize($item)], 201);
    }

    public function importTemplate(Request $request, GoalBankService $goals)
    {
        return $goals->downloadImportTemplate($request->user());
    }

    public function import(Request $request, GoalBankService $goals)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $result = $goals->importFromUpload($request->user(), $data['file']);

        return response()->json([
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
            'message' => $result['imported'] > 0
                ? number_format($result['imported']).' پارت با موفقیت وارد شد.'
                : 'ردیفی وارد نشد؛ فایل و ستون‌ها را بررسی کنید.',
        ]);
    }

    public function update(Request $request, GoalBankItem $item, GoalBankService $goals)
    {
        $data = $this->validatedItem($request);
        $item = $goals->update($request->user(), $item, $data);

        return response()->json(['item' => $goals->serialize($item)]);
    }

    public function destroy(Request $request, GoalBankItem $item, GoalBankService $goals)
    {
        $goals->delete($request->user(), $item);

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, GoalBankService $goals)
    {
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        $items = $goals->assignBatch($user, $role);

        return response()->json([
            'items' => array_map(fn (GoalBankItem $item) => $goals->serialize($item), $items),
            'assigned_count' => count($items),
            'goal_bank' => $goals->featureState($user, $role),
            'message' => count($items).' پارت به‌صورت خودکار به شما تخصیص داده شد.',
        ]);
    }

    public function claim(Request $request, GoalBankItem $item, GoalBankService $goals)
    {
        // سازگاری مسیر قدیمی — همیشه به تخصیص خودکار هدایت می‌شود
        return $this->assign($request, $goals);
    }

    public function complete(Request $request, GoalBankItem $item, GoalBankService $goals)
    {
        $data = $request->validate([
            'work_report' => ['required', 'string', 'min:3', 'max:5000'],
        ]);
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        $item = $goals->complete($user, $role, $item, $data['work_report']);

        return response()->json([
            'item' => $goals->serialize($item),
            'goal_bank' => $goals->featureState($user, $role),
        ]);
    }

    public function fail(Request $request, GoalBankItem $item, GoalBankService $goals)
    {
        $data = $request->validate([
            'failure_reason' => ['required', 'string', 'min:3', 'max:5000'],
        ]);
        $user = $request->user();
        $role = $request->attributes->get('active_role');
        $item = $goals->fail($user, $role, $item, $data['failure_reason']);

        return response()->json([
            'item' => $goals->serialize($item),
            'goal_bank' => $goals->featureState($user, $role),
        ]);
    }

    public function accessIndex(Request $request, GoalBankService $goals)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $goals->listAccess(
            $request->user(),
            $data['search'] ?? null,
            (int) ($data['per_page'] ?? 20),
            (int) ($data['page'] ?? 1),
        );

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function accessStore(Request $request, GoalBankService $goals)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'enabled' => ['required', 'boolean'],
            'max_concurrent' => ['required', 'integer', 'min:1', 'max:500'],
            'province' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $touchLocation = array_key_exists('province', $data) || array_key_exists('city', $data);
        $enabled = $request->boolean('enabled');

        $row = $goals->setAccess(
            $request->user(),
            (int) $data['user_id'],
            $enabled,
            (int) $data['max_concurrent'],
            isset($data['role_id']) ? (int) $data['role_id'] : null,
            $touchLocation,
            $data['province'] ?? null,
            $data['city'] ?? null,
        );

        $target = User::query()->find($row->user_id);

        $parts = ['تنظیمات بانک اهداف ذخیره شد.'];
        $parts[] = $row->enabled
            ? 'دسترسی روشن است (مشترک همهٔ نقش‌ها) — هر نوبت '.$row->max_concurrent.' پارت.'
            : 'دسترسی خاموش است.';
        if ($touchLocation) {
            $loc = trim(implode(' / ', array_filter([$target?->province, $target?->city])));
            $parts[] = $loc !== '' ? 'موقعیت: '.$loc : 'موقعیت مکانی پاک شد.';
        }

        return response()->json([
            'access' => [
                'user_id' => $row->user_id,
                'role_id' => $row->role_id,
                'enabled' => (bool) $row->enabled,
                'max_concurrent' => $row->max_concurrent,
                'province' => $target?->province,
                'city' => $target?->city,
            ],
            'message' => implode(' ', $parts),
        ]);
    }

    public function userStats(Request $request, GoalBankService $goals)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return response()->json([
            'stats' => $goals->userPartStats($request->user(), (int) $data['user_id']),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:120'],
            'platform_url' => ['nullable', 'string', 'max:500'],
            'audience_count' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'province' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'mobile' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
