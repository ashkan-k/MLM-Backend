<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\FraSoftSyncLog;
use App\Models\GatewaySale;
use App\Models\OrganizationNode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditService;
use App\Services\Integration\FraSoft\FraSoftSyncService;
use App\Services\Integration\FraSoft\FraSoftWebhookHandler;
use App\Services\Organization\OrganizationTreeService;
use App\Services\Report\SuperuserReportService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuperuserController extends Controller
{
    public function stats()
    {
        return response()->json([
            'users' => User::query()->count(),
            'active_users' => User::query()->where('is_active', true)->count(),
            'sales' => GatewaySale::query()->count(),
            'commission_total' => Commission::query()->sum('commission_amount'),
            'wallets' => Wallet::query()->count(),
            'wallet_balance' => Wallet::query()->sum('balance'),
            'labels' => [
                'users' => 'کاربران',
                'active_users' => 'کاربران فعال',
                'sales' => 'فروش درگاه',
                'commission_total' => 'مجموع پورسانت',
                'wallets' => 'تعداد کیف پول',
                'wallet_balance' => 'مانده کل کیف پول‌ها',
            ],
        ]);
    }

    public function reports(Request $request, SuperuserReportService $reports)
    {
        return response()->json($reports->build($request->all()));
    }

    public function exportReports(Request $request, SuperuserReportService $reports)
    {
        $sheet = $request->string('sheet', 'operations')->toString();
        $data = $reports->build($request->all());
        $rows = match ($sheet) {
            'users' => array_map(fn ($row) => [
                'شناسه' => $row['id'],
                'نام' => $row['name'],
                'موبایل' => $row['mobile'],
                'نقش‌ها' => implode('، ', $row['roles']),
                'تعداد زیرمجموعه' => $row['descendant_count'],
            ], $data['organization']),
            'commissions' => array_map(fn ($row) => [
                'شناسه' => $row['id'],
                'کاربر' => $row['user'],
                'موبایل' => $row['mobile'],
                'نقش' => $row['role'],
                'درصد' => $row['percent'],
                'مبلغ (تومان)' => $row['amount'],
            ], $data['recent_commissions']),
            default => array_map(fn ($row) => [
                'عملیات' => $row['label'],
                'تعداد' => $row['count'],
                'مبلغ (تومان)' => $row['amount'],
            ], $data['operations']),
        };

        $bom = "\xEF\xBB\xBF";
        $csv = $bom;
        if ($rows !== []) {
            $csv .= implode(',', array_keys($rows[0]))."\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row))."\n";
            }
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="finopal-'.$sheet.'.csv"',
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query()->with('roles')->latest();
        if ($search = $request->string('q')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min(200, max(10, $request->integer('per_page', 30)));

        return response()->json($query->paginate($perPage));
    }

    public function bulkUsers(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete,activate,deactivate'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);

        $actorId = $request->user()?->id;
        $done = 0;
        foreach (User::query()->whereIn('id', $data['ids'])->get() as $user) {
            if ($user->id === $actorId && in_array($data['action'], ['delete', 'deactivate'], true)) {
                continue;
            }

            if ($data['action'] === 'delete') {
                $this->destroyUser($request, $user, $audit);
                $done++;
                continue;
            }

            $old = $user->only(['name', 'mobile', 'email', 'is_active']);
            $user->update(['is_active' => $data['action'] === 'activate']);
            $audit->record($request->user(), 'user.updated', $user, $old, $user->only(['name', 'mobile', 'email', 'is_active']));
            $done++;
        }

        return response()->json(['ok' => true, 'count' => $done]);
    }

    public function storeUser(Request $request, WalletService $wallets, OrganizationTreeService $tree, AuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'mobile' => ['required', 'unique:users,mobile'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
            'role_slugs' => ['required', 'array', 'min:1'],
            'avatar' => ['nullable', 'image', 'max:4096'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->storeAvatar($request, $user);

        $this->syncRoles($user, $data['role_slugs'], $wallets, $tree);
        $audit->record($request->user(), 'user.created', $user, null, $user->only(['name', 'mobile', 'email', 'is_active']));

        return response()->json($user->load('roles'), 201);
    }

    public function updateUser(Request $request, User $user, WalletService $wallets, OrganizationTreeService $tree, AuditService $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'mobile' => ['required', Rule::unique('users', 'mobile')->ignore($user->id)],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
            'role_slugs' => ['nullable', 'array', 'min:1'],
            'avatar' => ['nullable', 'image', 'max:4096'],
        ]);

        $old = $user->only(['name', 'mobile', 'email', 'is_active']);
        $user->fill([
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $this->storeAvatar($request, $user);

        if (isset($data['role_slugs'])) {
            $this->syncRoles($user, $data['role_slugs'], $wallets, $tree);
        }

        $audit->record($request->user(), 'user.updated', $user, $old, $user->only(['name', 'mobile', 'email', 'is_active']));

        return response()->json($user->fresh('roles'));
    }

    public function destroyUser(Request $request, User $user, AuditService $audit)
    {
        if ($user->id === $request->user()?->id) {
            return response()->json(['message' => 'حذف حساب فعلی مجاز نیست.'], 422);
        }

        if ($user->isSuperuser() && User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'superuser'))->count() <= 1) {
            return response()->json(['message' => 'آخرین مدیر سامانه را نمی‌توان حذف کرد.'], 422);
        }

        $hasLedger = Commission::query()->where('user_id', $user->id)->exists()
            || WalletTransaction::query()->whereHas('wallet', fn ($q) => $q->where('user_id', $user->id))->exists();

        $audit->record($request->user(), 'user.deleted', $user, $user->only(['name', 'mobile', 'is_active']), [
            'soft' => $hasLedger,
        ]);

        $user->tokens()->delete();

        if ($hasLedger) {
            $user->update(['is_active' => false]);
            UserRole::query()->where('user_id', $user->id)->update(['is_active' => false, 'effective_to' => now()->toDateString()]);

            return response()->json([
                'ok' => true,
                'soft_deleted' => true,
                'message' => 'به‌خاطر سابقه مالی، حساب غیرفعال شد و تاریخچه حفظ گردید.',
            ]);
        }

        $user->delete();

        return response()->json(['ok' => true, 'soft_deleted' => false]);
    }

    public function assignRole(Request $request, User $user, WalletService $wallets, AuditService $audit)
    {
        $data = $request->validate(['role_slug' => ['required', 'string']]);
        $role = Role::query()->where('slug', $data['role_slug'])->firstOrFail();
        UserRole::query()->firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id],
            ['effective_from' => now()->toDateString(), 'is_active' => true]
        );
        if ($role->is_organizational) {
            $wallets->walletFor($user, $role);
        }
        $audit->record($request->user(), 'role.assigned', $user, null, ['role' => $role->slug]);

        return response()->json($user->fresh('roles'));
    }

    public function roles()
    {
        return response()->json(Role::query()->with('permissions')->orderBy('hierarchy_level')->get());
    }

    public function organizationalRoles()
    {
        return response()->json(Role::query()->where('is_organizational', true)->orderBy('hierarchy_level')->get());
    }

    public function permissions()
    {
        return response()->json(Permission::query()->orderBy('panel')->orderBy('slug')->get());
    }

    public function assignPermission(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'role_id' => ['nullable', 'exists:roles,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'permission_id' => ['required', 'exists:permissions,id'],
            'allowed' => ['required', 'boolean'],
        ]);

        if (! empty($data['role_id'])) {
            $role = Role::query()->findOrFail($data['role_id']);
            if ($data['allowed']) {
                $role->permissions()->syncWithoutDetaching([
                    $data['permission_id'] => ['allowed' => true],
                ]);
            } else {
                $role->permissions()->detach($data['permission_id']);
            }
            $audit->record($request->user(), $data['allowed'] ? 'permission.role_assigned' : 'permission.role_revoked', $role, null, $data);
        }

        if (! empty($data['user_id'])) {
            $user = User::query()->findOrFail($data['user_id']);
            if ($data['allowed']) {
                DB::table('user_permissions')->updateOrInsert(
                    ['user_id' => $user->id, 'permission_id' => $data['permission_id']],
                    ['allowed' => true]
                );
            } else {
                DB::table('user_permissions')->where([
                    'user_id' => $user->id,
                    'permission_id' => $data['permission_id'],
                ])->delete();
            }
            $audit->record($request->user(), $data['allowed'] ? 'permission.user_override' : 'permission.user_revoked', $user, null, $data);
        }

        return response()->json([
            'ok' => true,
            'allowed' => (bool) $data['allowed'],
            'roles' => Role::query()->with('permissions')->get(),
        ]);
    }

    public function settings()
    {
        return response()->json([
            'items' => SystemSetting::query()->get(),
            'schema' => $this->settingsSchema(),
        ]);
    }

    public function updateSetting(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'value' => ['required'],
            'value_type' => ['nullable', 'string'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        $setting = SystemSetting::query()->where('key', $data['key'])->first();
        $old = $setting?->value;
        $value = is_array($data['value']) ? $this->normalizeSettingValue($data['key'], $data['value']) : $data['value'];

        $setting = SystemSetting::query()->updateOrCreate(
            ['key' => $data['key']],
            [
                'value' => $value,
                'value_type' => $data['value_type'] ?? 'json',
                'is_public' => $data['is_public'] ?? $setting?->is_public ?? false,
            ]
        );
        $audit->record($request->user(), 'setting.updated', $setting, ['value' => $old], ['value' => $setting->value]);

        return response()->json($setting);
    }

    public function commissionRules()
    {
        return response()->json(CommissionRule::query()->with('versions')->get());
    }

    public function updateRule(Request $request, CommissionRule $rule, AuditService $audit)
    {
        $data = $request->validate([
            'percent' => ['required', 'numeric'],
            'qualified_percent' => ['nullable', 'numeric'],
            'conditions' => ['nullable', 'array'],
        ]);

        $percent = round((float) $data['percent'], 1);
        $qualified = isset($data['qualified_percent']) ? round((float) $data['qualified_percent'], 1) : null;

        $version = $rule->versions()->create([
            'version' => ((int) $rule->versions()->max('version')) + 1,
            'percent' => $percent,
            'qualified_percent' => $qualified,
            'conditions' => $data['conditions'] ?? null,
            'effective_from' => now(),
        ]);
        $audit->record($request->user(), 'commission_rule.versioned', $rule, null, $version->toArray());

        return response()->json($rule->fresh('versions'));
    }

    public function courses(Request $request)
    {
        if ($request->isMethod('post')) {
            return $this->storeCourse($request);
        }

        return response()->json(Course::query()->with(['levels', 'roles'])->get());
    }

    public function storeCourse(Request $request)
    {
        $data = $this->validatedCourse($request);
        $course = Course::query()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_required_for_promotion' => $data['is_required_for_promotion'] ?? false,
        ]);
        $course->roles()->sync($data['role_ids'] ?? []);
        foreach ($data['levels'] ?? [] as $index => $level) {
            $course->levels()->create($this->levelPayload($level, $index));
        }

        return response()->json($course->load(['levels', 'roles']), 201);
    }

    public function updateCourse(Request $request, Course $course, AuditService $audit)
    {
        $data = $this->validatedCourse($request, false);
        $old = $course->only(['title', 'description', 'is_required_for_promotion', 'is_active']);
        $course->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? $course->is_active,
            'is_required_for_promotion' => $data['is_required_for_promotion'] ?? false,
        ]);
        $course->roles()->sync($data['role_ids'] ?? []);

        $keep = [];
        foreach ($data['levels'] ?? [] as $index => $level) {
            $payload = $this->levelPayload($level, $index);
            if (! empty($level['id'])) {
                $row = $course->levels()->where('id', $level['id'])->first();
                if ($row) {
                    $row->update($payload);
                    $keep[] = $row->id;
                    continue;
                }
            }
            $keep[] = $course->levels()->create($payload)->id;
        }
        $course->levels()->whereNotIn('id', $keep ?: [0])->delete();
        $audit->record($request->user(), 'course.updated', $course, $old, $course->only(['title', 'description', 'is_required_for_promotion']));

        return response()->json($course->fresh(['levels', 'roles']));
    }

    public function destroyCourse(Request $request, Course $course, AuditService $audit)
    {
        $audit->record($request->user(), 'course.deleted', $course, $course->only(['title']), null);
        $course->roles()->detach();
        $course->levels()->delete();
        $course->delete();

        return response()->json(['ok' => true]);
    }

    public function bulkCourses(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:courses,id'],
        ]);

        $done = 0;
        foreach (Course::query()->whereIn('id', $data['ids'])->get() as $course) {
            $this->destroyCourse($request, $course, $audit);
            $done++;
        }

        return response()->json(['ok' => true, 'count' => $done]);
    }

    public function uploadLevelFile(Request $request, CourseLevel $level)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,mp4,webm,mp3'],
        ]);

        $file = $request->file('file');
        if ($level->attachment_path) {
            Storage::disk('public')->delete($level->attachment_path);
        }
        $path = $file->store('course-content/'.$level->course_id, 'public');
        $mime = (string) $file->getMimeType();
        $type = str_starts_with($mime, 'video/') ? 'video' : (str_contains($mime, 'pdf') ? 'pdf' : 'file');
        $level->update([
            'attachment_path' => $path,
            'attachment_name' => $file->getClientOriginalName(),
            'content_type' => in_array($level->content_type, ['text', 'html'], true) ? $type : $level->content_type,
        ]);

        return response()->json($level->fresh());
    }

    public function audits(Request $request)
    {
        $page = $this->auditQuery($request)->paginate(40);
        $page->getCollection()->transform(fn (AuditLog $log) => $this->presentAudit($log));

        return response()->json($page);
    }

    public function exportAudits(Request $request)
    {
        $rows = $this->auditQuery($request)->limit(3000)->get()->map(fn (AuditLog $log) => $this->presentAudit($log));

        return response()->json(['data' => $rows, 'count' => $rows->count()]);
    }

    public function showAudit(AuditLog $audit)
    {
        $audit->load('actor:id,name,mobile,email');
        $related = null;
        if ($audit->auditable_type && $audit->auditable_id && class_exists($audit->auditable_type)) {
            $related = $audit->auditable_type::query()->find($audit->auditable_id);
        }

        $history = AuditLog::query()
            ->with('actor:id,name,mobile')
            ->when($audit->auditable_type, fn ($q) => $q->where('auditable_type', $audit->auditable_type))
            ->when($audit->auditable_id, fn ($q) => $q->where('auditable_id', $audit->auditable_id))
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $log) => $this->presentAudit($log));

        return response()->json(array_merge($this->presentAudit($audit)->toArray(), [
            'related' => $related,
            'history' => $history,
        ]));
    }

    public function frasoftLogs()
    {
        return response()->json(FraSoftSyncLog::query()->latest()->paginate(30));
    }

    public function frasoftSync(Request $request, FraSoftSyncService $sync)
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        return response()->json($sync->inbound($data['event'], $data['payload'], $data['idempotency_key'] ?? uniqid('fs-', true)));
    }

    public function frasoftWebhook(Request $request, FraSoftWebhookHandler $handler)
    {
        return response()->json($handler->handle($request->all()));
    }

    private function syncRoles(User $user, array $slugs, WalletService $wallets, OrganizationTreeService $tree): void
    {
        $roles = Role::query()->whereIn('slug', $slugs)->get();
        $keepIds = $roles->pluck('id');
        $parent = OrganizationNode::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', 'senior_manager'))
            ->first();

        foreach ($roles as $role) {
            UserRole::query()->updateOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['effective_from' => now()->toDateString(), 'is_active' => true, 'effective_to' => null]
            );
            if ($role->is_organizational) {
                $wallets->walletFor($user, $role);
                $hasNode = OrganizationNode::query()
                    ->where('user_id', $user->id)
                    ->where('role_id', $role->id)
                    ->where('is_active', true)
                    ->exists();
                if (! $hasNode) {
                    $tree->attach($user, $role, $parent, now()->toDateString());
                }
            }
        }

        UserRole::query()
            ->where('user_id', $user->id)
            ->whereNotIn('role_id', $keepIds)
            ->update(['is_active' => false, 'effective_to' => now()->toDateString()]);
    }

    private function validatedCourse(Request $request, bool $requireLevels = true): array
    {
        return $request->validate([
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_required_for_promotion' => ['boolean'],
            'is_active' => ['boolean'],
            'role_ids' => ['array'],
            'levels' => [$requireLevels ? 'required' : 'array', 'array'],
            'levels.*.id' => ['nullable', 'integer'],
            'levels.*.title' => ['required', 'string'],
            'levels.*.sort_order' => ['nullable', 'integer'],
            'levels.*.passing_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'levels.*.content_type' => ['nullable', 'in:text,html,video,pdf,file'],
            'levels.*.content_body' => ['nullable', 'string'],
            'levels.*.content_url' => ['nullable', 'string'],
        ]);
    }

    private function levelPayload(array $level, int $index): array
    {
        return [
            'title' => $level['title'],
            'sort_order' => $level['sort_order'] ?? ($index + 1),
            'passing_score' => $level['passing_score'],
            'content_type' => $level['content_type'] ?? 'text',
            'content_body' => $level['content_body'] ?? null,
            'content_url' => $level['content_url'] ?? null,
            'is_active' => true,
        ];
    }

    private function settingsSchema(): array
    {
        return [
            'qualification_thresholds' => [
                'label' => 'آستانه‌های پاداش ماهانه',
                'hint' => 'اگر فروش نقش به این حد برسد، درصد بالاتر (درصد از پاداش) اعمال می‌شود.',
                'fields' => [
                    ['key' => 'representative_points', 'label' => 'حداقل امتیاز نماینده', 'hint' => 'امتیاز فروش شخصی نماینده در ماه', 'type' => 'number'],
                    ['key' => 'sales_manager_gateways', 'label' => 'حداقل درگاه مدیر فروش', 'hint' => 'تعداد درگاه فعال تیم مدیر فروش', 'type' => 'number'],
                    ['key' => 'development_manager_gateways', 'label' => 'حداقل درگاه مدیر توسعه', 'hint' => 'تعداد درگاه فعال شبکه مدیر توسعه', 'type' => 'number'],
                ],
            ],
            'promotion_criteria' => [
                'label' => 'معیارهای ارتقاء سازمانی',
                'hint' => 'حد نصاب‌هایی که برای ارسال و تایید ارتقاء نقش بررسی می‌شوند.',
                'fields' => [
                    ['key' => 'sm_personal_points', 'label' => 'امتیاز فروش شخصی برای مدیر فروش', 'hint' => 'حداقل امتیاز فروش خود نماینده', 'type' => 'number'],
                    ['key' => 'sm_new_reps', 'label' => 'تعداد نمایندگان جدید برای مدیر فروش', 'hint' => 'نمایندگانی که باید معرفی شده باشند', 'type' => 'number'],
                    ['key' => 'sm_strong_reps', 'label' => 'نمایندگان قوی برای مدیر فروش', 'hint' => 'نمایندگان با امتیاز بالا', 'type' => 'number'],
                    ['key' => 'sm_rep_points', 'label' => 'امتیاز نمایندگان زیرمجموعه مدیر فروش', 'hint' => 'مجموع امتیاز فروش نمایندگان معرفی‌شده', 'type' => 'number'],
                    ['key' => 'dm_years', 'label' => 'سابقه لازم برای مدیر توسعه (سال)', 'hint' => 'حداقل سابقه به‌عنوان مدیر فروش', 'type' => 'number'],
                    ['key' => 'dm_new_reps', 'label' => 'نمایندگان ثبت‌شده برای مدیر توسعه', 'hint' => 'مجموع نمایندگان شبکه', 'type' => 'number'],
                    ['key' => 'dm_strong_reps', 'label' => 'نمایندگان قوی برای مدیر توسعه', 'hint' => 'نمایندگان با امتیاز بالا در شبکه', 'type' => 'number'],
                    ['key' => 'dm_rep_points', 'label' => 'امتیاز نمایندگان برای مدیر توسعه', 'hint' => 'مجموع امتیاز فروش شبکه', 'type' => 'number'],
                    ['key' => 'dm_eligible_sms', 'label' => 'مدیران فروش واجد شرایط', 'hint' => 'تعداد مدیران فروشی که خودشان آماده ارتقاء هستند', 'type' => 'number'],
                ],
            ],
        ];
    }

    private function normalizeSettingValue(string $key, array $value): array
    {
        $schema = $this->settingsSchema()[$key]['fields'] ?? [];
        foreach ($schema as $field) {
            if (array_key_exists($field['key'], $value) && $field['type'] === 'number') {
                $value[$field['key']] = is_numeric($value[$field['key']]) ? 0 + $value[$field['key']] : 0;
            }
        }

        return $value;
    }

    private function auditQuery(Request $request)
    {
        $query = AuditLog::query()->with('actor:id,name,mobile,email')->latest();
        if ($action = $request->string('action')->toString()) {
            $query->where('action', 'like', "%{$action}%");
        }
        if ($actorId = $request->integer('actor_id')) {
            $query->where('actor_user_id', $actorId);
        }
        if ($from = $request->string('from')->toString()) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->toString()) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }

    private function storeAvatar(Request $request, User $user): void
    {
        if (! $request->hasFile('avatar')) {
            return;
        }

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->update(['avatar' => $request->file('avatar')->store('avatars', 'public')]);
    }

    private function presentAudit(AuditLog $log): AuditLog
    {
        $log->setAttribute('entity_label', $this->entityLabel($log->auditable_type));
        $log->setAttribute('action_label', $this->actionLabel($log->action));

        return $log;
    }

    private function entityLabel(?string $type): string
    {
        return match ($type) {
            User::class, 'App\\Models\\User' => 'کاربر',
            Role::class, 'App\\Models\\Role' => 'نقش',
            Course::class, 'App\\Models\\Course' => 'دوره آموزشی',
            SystemSetting::class, 'App\\Models\\SystemSetting' => 'تنظیمات',
            CommissionRule::class, 'App\\Models\\CommissionRule' => 'قاعده پورسانت',
            default => class_basename((string) $type) ?: 'سامانه',
        };
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'user.created' => 'ایجاد کاربر',
            'user.updated' => 'ویرایش کاربر',
            'user.deleted' => 'حذف یا غیرفعال‌سازی کاربر',
            'role.assigned' => 'اختصاص نقش',
            'permission.role_assigned' => 'اعطای دسترسی به نقش',
            'permission.role_revoked' => 'سلب دسترسی از نقش',
            'permission.user_override' => 'اعطای دسترسی به کاربر',
            'permission.user_revoked' => 'سلب دسترسی کاربر',
            'setting.updated' => 'تغییر تنظیمات',
            'commission_rule.versioned' => 'ذخیره قاعده پورسانت',
            'course.updated' => 'ویرایش دوره',
            'course.deleted' => 'حذف دوره',
            'withdrawal.requested' => 'ثبت درخواست برداشت',
            'withdrawal.approved' => 'تایید برداشت',
            'withdrawal.rejected' => 'رد برداشت',
            'promotion.approved' => 'تایید ارتقاء',
            'promotion.rejected' => 'رد ارتقاء',
            'benefit_transfer.all' => 'انتقال کامل مزایا',
            'benefit_transfer.share' => 'انتقال سهم درگاه',
            default => $action,
        };
    }
}
