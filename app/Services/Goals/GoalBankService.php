<?php

namespace App\Services\Goals;

use App\Models\Customer;
use App\Models\GoalBankItem;
use App\Models\OrganizationNode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGoalBankAccess;
use App\Services\Organization\OrganizationTreeService;
use App\Support\GoalBankGeo;
use App\Support\SimpleSpreadsheet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GoalBankService
{
    public const DEFAULT_MAX_CONCURRENT = 10;

    public function __construct(
        private readonly OrganizationTreeService $tree,
    ) {}

    public function isManager(User $user): bool
    {
        return $user->isSuperuser() || $user->hasRole('senior_manager');
    }

    public function assertManager(User $user): void
    {
        if (! $this->isManager($user)) {
            abort(403, 'فقط مدیر ارشد و سوپریوزر به این بخش دسترسی دارند.');
        }
    }

    /** دسترسی واحد کاربر — مستقل از نقش/پنل فعال */
    public function accessFor(User $user, ?Role $role = null): ?UserGoalBankAccess
    {
        return UserGoalBankAccess::query()
            ->where('user_id', $user->id)
            ->orderByDesc('enabled')
            ->orderByDesc('max_concurrent')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{
     *   enabled: bool,
     *   max_concurrent: int,
     *   in_progress: int,
     *   can_claim_more: bool,
     *   can_receive_batch: bool,
     *   remaining_slots: int,
     *   batch_size: int,
     *   is_manager: bool,
     *   province: ?string,
     *   city: ?string
     * }
     */
    public function featureState(User $user, ?Role $role): array
    {
        $location = $this->resolveUserLocation($user);
        $inProgress = $this->inProgressCount($user->id);
        $access = $this->accessFor($user);
        $max = (int) ($access?->max_concurrent ?? self::DEFAULT_MAX_CONCURRENT);

        if ($this->isManager($user)) {
            $canReceive = $inProgress === 0;

            return [
                'enabled' => true,
                'max_concurrent' => $max,
                'in_progress' => $inProgress,
                'can_claim_more' => $canReceive,
                'can_receive_batch' => $canReceive,
                'remaining_slots' => $canReceive ? $max : 0,
                'batch_size' => $max,
                'is_manager' => true,
                'province' => $location['province'],
                'city' => $location['city'],
            ];
        }

        $enabled = (bool) ($access?->enabled);
        $canReceive = $enabled && $inProgress === 0;

        return [
            'enabled' => $enabled,
            'max_concurrent' => $max,
            'in_progress' => $enabled ? $inProgress : 0,
            'can_claim_more' => $canReceive,
            'can_receive_batch' => $canReceive,
            'remaining_slots' => $canReceive ? $max : 0,
            'batch_size' => $max,
            'is_manager' => false,
            'province' => $location['province'],
            'city' => $location['city'],
        ];
    }

    public function assertCanUse(User $user, ?Role $role): void
    {
        if ($this->isManager($user)) {
            return;
        }
        $state = $this->featureState($user, $role);
        if (! $state['enabled']) {
            abort(403, 'دسترسی بانک اهداف برای شما فعال نشده است.');
        }
    }

    /** شمار پارت در جریان کاربر — همه نقش‌ها با هم */
    public function inProgressCount(int $userId, ?int $roleId = null): int
    {
        return GoalBankItem::query()
            ->where('claimed_by_user_id', $userId)
            ->where('status', GoalBankItem::STATUS_IN_PROGRESS)
            ->count();
    }

    /**
     * @return array{province: ?string, city: ?string}
     */
    public function resolveUserLocation(User $user): array
    {
        $province = GoalBankGeo::normalize($user->province ?? null);
        $city = GoalBankGeo::normalize($user->city ?? null);
        if ($province !== null || $city !== null) {
            return ['province' => $province, 'city' => $city];
        }

        $customer = Customer::query()
            ->where('mobile', $user->mobile)
            ->where(function ($q) {
                $q->whereNotNull('province')->orWhereNotNull('city');
            })
            ->orderByDesc('id')
            ->first(['province', 'city']);

        return [
            'province' => GoalBankGeo::normalize($customer?->province),
            'city' => GoalBankGeo::normalize($customer?->city),
        ];
    }

    public function listAccess(User $viewer, ?string $search = null, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        $this->assertManager($viewer);
        $perPage = max(1, min(50, $perPage));
        $needle = $search !== null ? trim($search) : '';

        $query = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('is_organizational', true)->where('slug', '!=', 'superuser'))
            ->with(['roles' => fn ($q) => $q->where('is_organizational', true)->where('slug', '!=', 'superuser')]);

        if (! $viewer->isSuperuser()) {
            $nodes = OrganizationNode::query()
                ->where('user_id', $viewer->id)
                ->where('is_active', true)
                ->get(['id', 'path']);

            $query->where(function ($outer) use ($viewer, $nodes) {
                $outer->where('users.id', $viewer->id);
                if ($nodes->isEmpty()) {
                    return;
                }
                $outer->orWhereIn('users.id', function ($sub) use ($nodes) {
                    $sub->select('organization_nodes.user_id')
                        ->from('organization_nodes')
                        ->where('organization_nodes.is_active', true)
                        ->where(function ($q) use ($nodes) {
                            foreach ($nodes as $node) {
                                $path = $node->path ?: '/'.$node->id.'/';
                                $q->orWhere(function ($inner) use ($path, $node) {
                                    $inner->where('organization_nodes.path', 'like', $path.'%')
                                        ->where('organization_nodes.id', '!=', $node->id);
                                });
                            }
                        });
                });
            });
        }

        if ($needle !== '') {
            $term = '%'.$needle.'%';
            $digits = preg_replace('/\D+/', '', $needle) ?: $needle;
            $query->where(function ($q) use ($term, $digits, $needle) {
                $q->where('users.name', 'like', $term)
                    ->orWhere('users.mobile', 'like', $term)
                    ->orWhere('users.national_id', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('users.province', 'like', $term)
                    ->orWhere('users.city', 'like', $term);
                if ($digits !== '' && $digits !== $needle) {
                    $q->orWhere('users.mobile', 'like', '%'.$digits.'%')
                        ->orWhere('users.national_id', 'like', '%'.$digits.'%');
                }
            });
        }

        $paginator = $query->orderBy('users.name')->paginate($perPage, ['*'], 'page', max(1, $page));
        $userIds = $paginator->getCollection()->pluck('id')->all();

        $accessRows = $userIds === []
            ? collect()
            : UserGoalBankAccess::query()
                ->whereIn('user_id', $userIds)
                ->get()
                ->keyBy('user_id');

        $inProgressMap = $userIds === []
            ? collect()
            : GoalBankItem::query()
                ->selectRaw('claimed_by_user_id, COUNT(*) as cnt')
                ->whereIn('claimed_by_user_id', $userIds)
                ->where('status', GoalBankItem::STATUS_IN_PROGRESS)
                ->groupBy('claimed_by_user_id')
                ->get()
                ->keyBy('claimed_by_user_id');

        $paginator->setCollection(
            $paginator->getCollection()->map(function (User $user) use ($accessRows, $inProgressMap) {
                $access = $accessRows->get($user->id);
                $roles = [];
                foreach ($user->roles as $role) {
                    $roles[] = [
                        'role_id' => $role->id,
                        'role_slug' => $role->slug,
                        'role_name' => $role->name,
                    ];
                }
                $location = $this->resolveUserLocation($user);

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'mobile' => $user->mobile,
                    'national_id' => $user->national_id,
                    'enabled' => (bool) ($access?->enabled),
                    'max_concurrent' => (int) ($access?->max_concurrent ?? self::DEFAULT_MAX_CONCURRENT),
                    'in_progress' => (int) ($inProgressMap->get($user->id)?->cnt ?? 0),
                    'province' => $location['province'],
                    'city' => $location['city'],
                    'roles' => $roles,
                ];
            })
        );

        return $paginator;
    }

    public function setAccess(
        User $actor,
        int $userId,
        bool $enabled,
        int $maxConcurrent,
        ?int $roleId = null,
        bool $touchLocation = false,
        ?string $province = null,
        ?string $city = null,
    ): UserGoalBankAccess {
        $this->assertManager($actor);

        if ($maxConcurrent < 1 || $maxConcurrent > 500) {
            throw ValidationException::withMessages(['max_concurrent' => 'تعداد مجاز هر نوبت باید بین ۱ تا ۵۰۰ باشد.']);
        }

        $target = User::query()->findOrFail($userId);

        if (! $actor->isSuperuser()) {
            $allowed = $this->tree->descendantUserIds($actor)->push($actor->id)->all();
            if (! in_array($target->id, $allowed, true)) {
                abort(403, 'این کاربر در زیرمجموعه شما نیست.');
            }
        }

        $role = null;
        if ($roleId) {
            $role = Role::query()->whereKey($roleId)->where('is_organizational', true)->first();
        }
        if (! $role) {
            $role = $target->roles()
                ->where('is_organizational', true)
                ->where('slug', '!=', 'superuser')
                ->orderBy('roles.id')
                ->first();
        }
        if (! $role) {
            throw ValidationException::withMessages(['user_id' => 'کاربر نقش سازمانی فعالی ندارد.']);
        }

        if ($touchLocation) {
            $target->province = GoalBankGeo::normalize($province);
            $target->city = GoalBankGeo::normalize($city);
            $target->save();
        }

        // یک ردیف واحد برای کاربر — مشترک بین همهٔ نقش‌ها/پنل‌ها
        $existing = UserGoalBankAccess::query()->where('user_id', $target->id)->first();
        if ($existing) {
            $existing->fill([
                'role_id' => $role->id,
                'enabled' => $enabled,
                'max_concurrent' => $maxConcurrent,
                'granted_by' => $actor->id,
            ]);
            $existing->save();

            return $existing->fresh();
        }

        return UserGoalBankAccess::query()->create([
            'user_id' => $target->id,
            'role_id' => $role->id,
            'enabled' => $enabled,
            'max_concurrent' => $maxConcurrent,
            'granted_by' => $actor->id,
        ]);
    }

    public function listItems(
        User $viewer,
        ?Role $role,
        ?string $status = null,
        ?string $search = null,
        string $scope = 'all',
        int $perPage = 20,
        ?int $claimedByUserId = null,
    ): LengthAwarePaginator {
        $this->assertCanUse($viewer, $role);
        $isManager = $this->isManager($viewer);

        $query = GoalBankItem::query()
            ->with([
                'claimant:id,name,mobile',
                'claimRole:id,name,slug',
                'creator:id,name',
            ])
            ->orderByRaw("CASE status WHEN 'available' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByDesc('id');

        if ($claimedByUserId !== null) {
            $this->assertManager($viewer);
            $target = User::query()->findOrFail($claimedByUserId);
            if (! $viewer->isSuperuser()) {
                $allowed = $this->tree->descendantUserIds($viewer)->push($viewer->id)->all();
                if (! in_array($target->id, $allowed, true)) {
                    abort(403, 'این کاربر در زیرمجموعه شما نیست.');
                }
            }
            $query->where('claimed_by_user_id', $target->id);
        } elseif (! $isManager) {
            $query->where('claimed_by_user_id', $viewer->id);
        }

        if ($claimedByUserId === null) {
            if ($scope === 'mine') {
                $query->where('claimed_by_user_id', $viewer->id);
            } elseif ($scope === 'available') {
                $query->where('status', GoalBankItem::STATUS_AVAILABLE);
            } elseif ($scope === 'in_progress') {
                $query->where('status', GoalBankItem::STATUS_IN_PROGRESS);
                if (! $isManager) {
                    $query->where('claimed_by_user_id', $viewer->id);
                }
            } elseif ($scope === 'history') {
                $query->whereIn('status', [
                    GoalBankItem::STATUS_COMPLETED,
                    GoalBankItem::STATUS_FAILED,
                ]);
                if (! $isManager) {
                    $query->where('claimed_by_user_id', $viewer->id);
                }
            }
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $needle = trim($search);
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', "%{$needle}%")
                    ->orWhere('platform', 'like', "%{$needle}%")
                    ->orWhere('province', 'like', "%{$needle}%")
                    ->orWhere('city', 'like', "%{$needle}%")
                    ->orWhere('mobile', 'like', "%{$needle}%")
                    ->orWhere('phone', 'like', "%{$needle}%");
            });
        }

        return $query->paginate(max(1, min(50, $perPage)));
    }

    /**
     * خلاصه وضعیت پارت‌های یک کاربر برای مانیتورینگ.
     *
     * @return array{
     *   user_id: int,
     *   total: int,
     *   in_progress: int,
     *   completed: int,
     *   failed: int,
     *   success_rate: float|null
     * }
     */
    public function userPartStats(User $viewer, int $claimedByUserId): array
    {
        $this->assertManager($viewer);
        $target = User::query()->findOrFail($claimedByUserId);
        if (! $viewer->isSuperuser()) {
            $allowed = $this->tree->descendantUserIds($viewer)->push($viewer->id)->all();
            if (! in_array($target->id, $allowed, true)) {
                abort(403, 'این کاربر در زیرمجموعه شما نیست.');
            }
        }

        $rows = GoalBankItem::query()
            ->where('claimed_by_user_id', $target->id)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $inProgress = (int) ($rows[GoalBankItem::STATUS_IN_PROGRESS] ?? 0);
        $completed = (int) ($rows[GoalBankItem::STATUS_COMPLETED] ?? 0);
        $failed = (int) ($rows[GoalBankItem::STATUS_FAILED] ?? 0);
        $total = $inProgress + $completed + $failed;
        $decided = $completed + $failed;

        return [
            'user_id' => $target->id,
            'total' => $total,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $decided > 0 ? round(($completed / $decided) * 100, 1) : null,
        ];
    }

    public function create(User $actor, array $data): GoalBankItem
    {
        $this->assertManager($actor);

        return GoalBankItem::query()->create([
            ...$this->sanitizeFields($data),
            'status' => GoalBankItem::STATUS_AVAILABLE,
            'created_by' => $actor->id,
        ]);
    }

    public function update(User $actor, GoalBankItem $item, array $data): GoalBankItem
    {
        $this->assertManager($actor);
        if (! $item->isAvailable()) {
            throw ValidationException::withMessages(['item' => 'فقط آیتم‌های آزاد قابل ویرایش هستند.']);
        }
        $item->fill($this->sanitizeFields($data));
        $item->save();

        return $item->fresh(['claimant', 'claimRole', 'creator']);
    }

    public function delete(User $actor, GoalBankItem $item): void
    {
        $this->assertManager($actor);
        if (! $item->isAvailable()) {
            throw ValidationException::withMessages(['item' => 'فقط آیتم‌های آزاد قابل حذف هستند.']);
        }
        $item->delete();
    }

    public const IMPORT_HEADERS = [
        'نام',
        'پلتفرم',
        'آدرس پلتفرم',
        'تعداد مخاطبین',
        'توضیحات',
        'استان',
        'شهر',
        'آدرس دقیق',
        'شماره تماس',
        'شماره موبایل',
    ];

    public const IMPORT_MAX_ROWS = 15000;

    public function downloadImportTemplate(User $actor): BinaryFileResponse
    {
        $this->assertManager($actor);

        $path = storage_path('app/goal-bank-import-sample.xlsx');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        SimpleSpreadsheet::writeXlsx($path, self::IMPORT_HEADERS, [
            ['کانال نمونه ۱', 'ایتا', 'https://eitaa.com/sample1', '1200', 'توضیح نمونه برای پشتیبانی', 'تهران', 'تهران', 'خیابان ولیعصر، پلاک ۱۰', '02188776655', '09121112233'],
            ['کانال نمونه ۲', 'تلگرام', 'https://t.me/sample2', '850', 'نمونه کامل همهٔ ستون‌ها', 'البرز', 'کرج', 'بلوار دانشجو، کوچه ۳', '02634445566', '09123334455'],
        ]);

        return response()->download(
            $path,
            'goal-bank-parts-sample.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    /**
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importFromUpload(User $actor, UploadedFile $file): array
    {
        $this->assertManager($actor);

        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            throw ValidationException::withMessages([
                'file' => 'فقط فایل Excel (xlsx) یا CSV پذیرفته می‌شود.',
            ]);
        }

        try {
            $matrix = SimpleSpreadsheet::read($file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalName());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'file' => 'خواندن فایل ممکن نشد؛ از نمونه اکسل استفاده کنید.',
            ]);
        }

        if ($matrix === []) {
            throw ValidationException::withMessages(['file' => 'فایل خالی است.']);
        }

        $header = array_shift($matrix);
        $map = $this->mapImportHeaders($header);
        if (! isset($map['name'], $map['platform'])) {
            throw ValidationException::withMessages([
                'file' => 'ستون‌های اجباری «نام» و «پلتفرم» در ردیف اول یافت نشد. فایل نمونه را دانلود کنید.',
            ]);
        }

        if (count($matrix) > self::IMPORT_MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'حداکثر '.self::IMPORT_MAX_ROWS.' ردیف در هر آپلود مجاز است.',
            ]);
        }

        $now = now();
        $batch = [];
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $flush = function () use (&$batch, &$imported): void {
            if ($batch === []) {
                return;
            }
            GoalBankItem::query()->insert($batch);
            $imported += count($batch);
            $batch = [];
        };

        foreach ($matrix as $idx => $row) {
            $line = $idx + 2;
            $name = trim((string) ($row[$map['name']] ?? ''));
            $platform = trim((string) ($row[$map['platform']] ?? ''));
            if ($name === '' && $platform === '') {
                $skipped++;
                continue;
            }
            if ($name === '' || $platform === '') {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = "ردیف {$line}: نام و پلتفرم هر دو الزامی است.";
                }
                continue;
            }

            $audienceRaw = isset($map['audience_count']) ? trim((string) ($row[$map['audience_count']] ?? '')) : '';
            $audience = $audienceRaw !== '' && is_numeric($audienceRaw) ? (int) $audienceRaw : null;

            $fields = $this->sanitizeFields([
                'name' => $name,
                'platform' => $platform,
                'platform_url' => isset($map['platform_url']) ? ($row[$map['platform_url']] ?? null) : null,
                'audience_count' => $audience,
                'description' => isset($map['description']) ? ($row[$map['description']] ?? null) : null,
                'province' => isset($map['province']) ? ($row[$map['province']] ?? null) : null,
                'city' => isset($map['city']) ? ($row[$map['city']] ?? null) : null,
                'address' => isset($map['address']) ? ($row[$map['address']] ?? null) : null,
                'phone' => isset($map['phone']) ? ($row[$map['phone']] ?? null) : null,
                'mobile' => isset($map['mobile']) ? ($row[$map['mobile']] ?? null) : null,
            ]);

            $batch[] = [
                ...$fields,
                'status' => GoalBankItem::STATUS_AVAILABLE,
                'created_by' => $actor->id,
                'claimed_by_user_id' => null,
                'claimed_by_role_id' => null,
                'claimed_at' => null,
                'work_report' => null,
                'failure_reason' => null,
                'disposed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                $flush();
            }
        }

        $flush();

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapImportHeaders(array $header): array
    {
        $aliases = [
            'name' => ['نام', 'name', 'title', 'عنوان'],
            'platform' => ['پلتفرم', 'platform'],
            'platform_url' => ['آدرس پلتفرم', 'platform_url', 'url', 'لینک'],
            'audience_count' => ['تعداد مخاطبین', 'audience_count', 'audience', 'مخاطب'],
            'description' => ['توضیحات', 'description', 'desc', 'شرح'],
            'province' => ['استان', 'province', 'state'],
            'city' => ['شهر', 'city'],
            'address' => ['آدرس دقیق', 'آدرس', 'address'],
            'phone' => ['شماره تماس', 'تلفن', 'phone', 'tel'],
            'mobile' => ['شماره موبایل', 'موبایل', 'mobile'],
        ];

        $map = [];
        foreach ($header as $i => $raw) {
            $label = GoalBankGeo::normalize((string) $raw) ?? '';
            $labelLower = mb_strtolower($label);
            foreach ($aliases as $key => $names) {
                if (isset($map[$key])) {
                    continue;
                }
                foreach ($names as $name) {
                    if ($label === $name || $labelLower === mb_strtolower($name)) {
                        $map[$key] = $i;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * تخصیص خودکار با اولویت مکانی: شهر → استان → استان‌های اطراف → سایر.
     *
     * @return list<GoalBankItem>
     */
    public function assignBatch(User $actor, ?Role $role): array
    {
        $this->assertCanUse($actor, $role);
        if (! $role) {
            throw ValidationException::withMessages(['role' => 'نقش فعال مشخص نیست.']);
        }

        $state = $this->featureState($actor, $role);
        if (! $state['can_receive_batch']) {
            throw ValidationException::withMessages([
                'limit' => $state['in_progress'] > 0
                    ? 'تا وقتی همهٔ پارت‌های جاری تعیین‌تکلیف نشوند نمی‌توانید نوبت جدید بگیرید.'
                    : 'دسترسی بانک اهداف برای شما فعال نیست.',
            ]);
        }

        $batchSize = (int) $state['batch_size'];
        $location = $this->resolveUserLocation($actor);

        return DB::transaction(function () use ($actor, $role, $batchSize, $location) {
            $inProgress = $this->inProgressCount($actor->id);
            if ($inProgress > 0) {
                throw ValidationException::withMessages([
                    'limit' => 'ابتدا پارت‌های جاری را تعیین‌تکلیف کنید.',
                ]);
            }

            $access = $this->accessFor($actor);
            if (! $this->isManager($actor) && ! ($access?->enabled)) {
                abort(403, 'دسترسی بانک اهداف برای شما فعال نشده است.');
            }

            [$orderSql, $bindings] = GoalBankGeo::assignOrderSql($location['province'], $location['city']);

            $query = GoalBankItem::query()
                ->where('status', GoalBankItem::STATUS_AVAILABLE)
                ->orderByRaw($orderSql, $bindings)
                ->limit($batchSize)
                ->lockForUpdate();

            $ids = $query->pluck('id')->all();

            if ($ids === []) {
                throw ValidationException::withMessages([
                    'item' => 'در حال حاضر پارت آزادی برای تخصیص وجود ندارد.',
                ]);
            }

            $assigned = [];
            $now = now();
            foreach ($ids as $id) {
                $item = GoalBankItem::query()->whereKey($id)->lockForUpdate()->first();
                if (! $item || ! $item->isAvailable()) {
                    continue;
                }
                $item->status = GoalBankItem::STATUS_IN_PROGRESS;
                $item->claimed_by_user_id = $actor->id;
                $item->claimed_by_role_id = $role->id;
                $item->claimed_at = $now;
                $item->work_report = null;
                $item->failure_reason = null;
                $item->disposed_at = null;
                $item->save();
                $assigned[] = $item->fresh(['claimant', 'claimRole', 'creator']);
            }

            if ($assigned === []) {
                throw ValidationException::withMessages([
                    'item' => 'پارت آزادی برای تخصیص باقی نماند؛ دوباره تلاش کنید.',
                ]);
            }

            return $assigned;
        });
    }

    /** @deprecated */
    public function claim(User $actor, ?Role $role, GoalBankItem $item): GoalBankItem
    {
        throw ValidationException::withMessages([
            'item' => 'دریافت تکی غیرفعال است؛ از تخصیص خودکار نوبت استفاده کنید.',
        ]);
    }

    public function complete(User $actor, ?Role $role, GoalBankItem $item, string $workReport): GoalBankItem
    {
        $workReport = trim($workReport);
        if ($workReport === '') {
            throw ValidationException::withMessages(['work_report' => 'گزارش کار الزامی است.']);
        }

        return $this->dispose($actor, $role, $item, GoalBankItem::STATUS_COMPLETED, $workReport, null);
    }

    public function fail(User $actor, ?Role $role, GoalBankItem $item, string $failureReason): GoalBankItem
    {
        $failureReason = trim($failureReason);
        if ($failureReason === '') {
            throw ValidationException::withMessages(['failure_reason' => 'علت انجام‌نشدن الزامی است.']);
        }

        return $this->dispose($actor, $role, $item, GoalBankItem::STATUS_FAILED, null, $failureReason);
    }

    private function dispose(
        User $actor,
        ?Role $role,
        GoalBankItem $item,
        string $status,
        ?string $workReport,
        ?string $failureReason,
    ): GoalBankItem {
        $this->assertCanUse($actor, $role);

        return DB::transaction(function () use ($actor, $item, $status, $workReport, $failureReason) {
            $locked = GoalBankItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isInProgress()) {
                throw ValidationException::withMessages(['item' => 'فقط پارت‌های در حال انجام قابل تعیین‌تکلیف هستند.']);
            }
            $isOwner = (int) $locked->claimed_by_user_id === (int) $actor->id;
            if (! $isOwner && ! $this->isManager($actor)) {
                abort(403, 'فقط گیرندهٔ پارت می‌تواند آن را تعیین‌تکلیف کند.');
            }

            $locked->status = $status;
            $locked->work_report = $workReport;
            $locked->failure_reason = $failureReason;
            $locked->disposed_at = now();
            $locked->save();

            return $locked->fresh(['claimant', 'claimRole', 'creator']);
        });
    }

    /** @param array<string, mixed> $data */
    private function sanitizeFields(array $data): array
    {
        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'platform' => trim((string) ($data['platform'] ?? '')),
            'platform_url' => ($data['platform_url'] ?? null) !== null && $data['platform_url'] !== ''
                ? trim((string) $data['platform_url'])
                : null,
            'audience_count' => isset($data['audience_count']) && $data['audience_count'] !== ''
                ? (int) $data['audience_count']
                : null,
            'description' => ($data['description'] ?? null) !== null && $data['description'] !== ''
                ? trim((string) $data['description'])
                : null,
            'province' => ($data['province'] ?? null) !== null && $data['province'] !== ''
                ? trim((string) $data['province'])
                : null,
            'city' => ($data['city'] ?? null) !== null && $data['city'] !== ''
                ? trim((string) $data['city'])
                : null,
            'address' => ($data['address'] ?? null) !== null && $data['address'] !== ''
                ? trim((string) $data['address'])
                : null,
            'phone' => ($data['phone'] ?? null) !== null && $data['phone'] !== ''
                ? trim((string) $data['phone'])
                : null,
            'mobile' => ($data['mobile'] ?? null) !== null && $data['mobile'] !== ''
                ? trim((string) $data['mobile'])
                : null,
        ];
    }

    public function serialize(GoalBankItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'platform' => $item->platform,
            'platform_url' => $item->platform_url,
            'audience_count' => $item->audience_count,
            'description' => $item->description,
            'province' => $item->province,
            'city' => $item->city,
            'address' => $item->address,
            'phone' => $item->phone,
            'mobile' => $item->mobile,
            'status' => $item->status,
            'claimed_at' => $item->claimed_at?->toIso8601String(),
            'disposed_at' => $item->disposed_at?->toIso8601String(),
            'work_report' => $item->work_report,
            'failure_reason' => $item->failure_reason,
            'claimant' => $item->claimant ? [
                'id' => $item->claimant->id,
                'name' => $item->claimant->name,
                'mobile' => $item->claimant->mobile,
            ] : null,
            'claim_role' => $item->claimRole ? [
                'id' => $item->claimRole->id,
                'name' => $item->claimRole->name,
                'slug' => $item->claimRole->slug,
            ] : null,
            'created_by' => $item->created_by,
        ];
    }
}
