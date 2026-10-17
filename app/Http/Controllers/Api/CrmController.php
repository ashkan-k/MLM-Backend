<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use App\Models\CrmVisit;
use App\Models\CrmVisitLog;
use App\Services\Crm\CrmReminderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CrmController extends Controller
{
    public function index(Request $request, CrmReminderService $reminders)
    {
        $user = $request->user();
        $roleId = $this->roleId($request);
        $reminders->dispatchDue($user->id);
        $search = trim((string) $request->query('q', ''));

        $visits = CrmVisit::query()
            ->withCount('logs')
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('place', 'like', $like)
                        ->orWhere('person_name', 'like', $like)
                        ->orWhere('national_id', 'like', $like)
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('result', 'like', $like)
                        ->orWhereHas('logs', fn ($logs) => $logs->where('body', 'like', $like));
                });
            })
            ->orderByDesc('visited_on')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CrmVisit $visit) => $this->visitPayload($visit));

        $tasks = CrmTask::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('title', 'like', $like)->orWhere('notes', 'like', $like);
                });
            })
            ->orderByRaw('completed_at is not null')
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CrmTask $task) => $this->taskPayload($task));

        return response()->json([
            'visits' => $visits,
            'tasks' => $tasks,
            'open_tasks' => CrmTask::query()->where('user_id', $user->id)->where('role_id', $roleId)->whereNull('completed_at')->count(),
        ]);
    }

    public function storeVisit(Request $request)
    {
        $data = $this->validateVisit($request);
        $visit = CrmVisit::query()->create([
            'user_id' => $request->user()->id,
            'role_id' => $this->roleId($request),
            ...$data,
        ]);

        return response()->json($this->visitPayload($visit), 201);
    }

    public function updateVisit(Request $request, CrmVisit $visit)
    {
        $this->own($request, $visit->user_id, $visit->role_id);
        $data = $this->validateVisit($request);
        if ($this->reminderMoved($visit->remind_at, $data['remind_at'] ?? null)) {
            $data = $this->rearm($data);
        }
        $visit->fill($data)->save();

        return response()->json($this->visitPayload($visit->fresh()));
    }

    public function destroyVisit(Request $request, CrmVisit $visit)
    {
        $this->own($request, $visit->user_id, $visit->role_id);
        $visit->delete();

        return response()->json(['deleted' => true]);
    }

    public function showVisit(Request $request, CrmVisit $visit)
    {
        $this->own($request, $visit->user_id, $visit->role_id);
        $visit->load(['logs' => fn ($q) => $q->orderByDesc('id')]);
        $visit->setAttribute('logs_count', $visit->logs->count());

        return response()->json($this->visitPayload($visit, true));
    }

    public function storeVisitLog(Request $request, CrmVisit $visit)
    {
        $this->own($request, $visit->user_id, $visit->role_id);
        $request->merge(['body' => trim((string) $request->input('body', ''))]);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'body.required' => 'متن گزارش الزامی است.',
        ]);
        $log = $visit->logs()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return response()->json($this->logPayload($log), 201);
    }

    public function destroyVisitLog(Request $request, CrmVisit $visit, CrmVisitLog $log)
    {
        $this->own($request, $visit->user_id, $visit->role_id);
        if ((int) $log->crm_visit_id !== (int) $visit->id) {
            abort(404);
        }
        $log->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeTask(Request $request)
    {
        $data = $this->validateTask($request);
        $task = CrmTask::query()->create([
            'user_id' => $request->user()->id,
            'role_id' => $this->roleId($request),
            ...$data,
        ]);

        return response()->json($this->taskPayload($task), 201);
    }

    public function updateTask(Request $request, CrmTask $task)
    {
        $this->own($request, $task->user_id, $task->role_id);
        $data = $this->validateTask($request, $request->boolean('completed'));
        if ($this->reminderMoved($task->due_at, $data['due_at'] ?? null)) {
            $data = $this->rearm($data);
        }
        if ($request->has('completed')) {
            $data['completed_at'] = $request->boolean('completed') ? ($task->completed_at ?? now()) : null;
        }
        $task->fill($data)->save();

        return response()->json($this->taskPayload($task->fresh()));
    }

    public function destroyTask(Request $request, CrmTask $task)
    {
        $this->own($request, $task->user_id, $task->role_id);
        $task->delete();

        return response()->json(['deleted' => true]);
    }

    private function roleId(Request $request): int
    {
        $role = $request->attributes->get('active_role');
        if (! $role) {
            abort(403, 'نقش فعال معتبر نیست.');
        }

        return (int) $role->id;
    }

    private function own(Request $request, int $ownerId, ?int $roleId): void
    {
        if ((int) $request->user()->id !== $ownerId || $this->roleId($request) !== (int) $roleId) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVisit(Request $request): array
    {
        $data = $request->validate([
            'visited_on' => ['required', 'date'],
            'activity_type' => ['nullable', 'in:visit,call,meeting'],
            'status' => ['nullable', 'in:open,done'],
            'place' => ['required', 'string', 'max:190'],
            'person_name' => ['required', 'string', 'max:190'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'result' => ['nullable', 'string', 'max:2000'],
            'remind_at' => ['nullable', 'date'],
            'notify_in_app' => ['boolean'],
            'notify_sms' => ['boolean'],
        ], [
            'place.required' => 'مکان الزامی است.',
            'person_name.required' => 'نام مخاطب الزامی است.',
            'visited_on.required' => 'تاریخ فعالیت الزامی است.',
        ]);

        $notifyInApp = $request->boolean('notify_in_app');
        $notifySms = $request->boolean('notify_sms');
        $remindAt = $this->tehran($data['remind_at'] ?? null);
        $this->assertChannel($remindAt, $notifyInApp, $notifySms);

        return [
            'visited_on' => Carbon::parse($data['visited_on'])->toDateString(),
            'activity_type' => in_array($data['activity_type'] ?? '', ['visit', 'call', 'meeting'], true) ? $data['activity_type'] : 'visit',
            'status' => ($data['status'] ?? 'open') === 'done' ? 'done' : 'open',
            'place' => trim($data['place']),
            'person_name' => trim($data['person_name']),
            'national_id' => $this->blank($data['national_id'] ?? null),
            'mobile' => $this->blank($data['mobile'] ?? null),
            'result' => $this->blank($data['result'] ?? null),
            'remind_at' => $remindAt,
            'notify_in_app' => $remindAt ? $notifyInApp : false,
            'notify_sms' => $remindAt ? $notifySms : false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTask(Request $request, bool $completed = false): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'priority' => ['nullable', 'in:normal,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
            'notify_in_app' => ['boolean'],
            'notify_sms' => ['boolean'],
            'completed' => ['boolean'],
        ], [
            'title.required' => 'عنوان کار الزامی است.',
        ]);

        $notifyInApp = $request->boolean('notify_in_app');
        $notifySms = $request->boolean('notify_sms');
        $dueAt = $this->tehran($data['due_at'] ?? null);
        if (! $completed) {
            $this->assertChannel($dueAt, $notifyInApp, $notifySms);
        }

        return [
            'title' => trim($data['title']),
            'priority' => ($data['priority'] ?? '') === 'high' ? 'high' : 'normal',
            'notes' => $this->blank($data['notes'] ?? null),
            'due_at' => $dueAt,
            'notify_in_app' => $dueAt ? $notifyInApp : false,
            'notify_sms' => $dueAt ? $notifySms : false,
        ];
    }

    private function assertChannel(?Carbon $at, bool $inApp, bool $sms): void
    {
        if ($at && ! $inApp && ! $sms) {
            throw ValidationException::withMessages([
                'notify_in_app' => 'برای یادآور حداقل اعلان یا پیامک را انتخاب کنید.',
            ]);
        }
    }

    private function tehran(mixed $value): ?Carbon
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        return Carbon::parse($text, 'Asia/Tehran')->utc();
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function rearm(array $data): array
    {
        $data['reminded_at'] = null;
        $data['notified_at'] = null;
        $data['sms_sent_at'] = null;

        return $data;
    }

    private function reminderMoved(mixed $current, mixed $next): bool
    {
        $left = $current instanceof Carbon ? $current->clone()->utc()->format('Y-m-d H:i') : '';
        $right = $next instanceof Carbon ? $next->clone()->utc()->format('Y-m-d H:i') : '';

        return $left !== $right;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function logPayload(CrmVisitLog $log): array
    {
        return [
            'id' => $log->id,
            'body' => $log->body,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visitPayload(CrmVisit $visit, bool $withLogs = false): array
    {
        $payload = [
            'id' => $visit->id,
            'visited_on' => $visit->visited_on?->toDateString(),
            'activity_type' => $visit->activity_type ?: 'visit',
            'status' => $visit->status ?: 'open',
            'place' => $visit->place,
            'person_name' => $visit->person_name,
            'national_id' => $visit->national_id,
            'mobile' => $visit->mobile,
            'result' => $visit->result,
            'remind_at' => $visit->remind_at?->toIso8601String(),
            'remind_at_input' => $visit->remind_at?->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => $visit->notify_in_app,
            'notify_sms' => $visit->notify_sms,
            'reminded_at' => $visit->reminded_at?->toIso8601String(),
            'logs_count' => (int) ($visit->logs_count ?? 0),
        ];
        if ($withLogs) {
            $payload['logs'] = $visit->logs->map(fn (CrmVisitLog $log) => $this->logPayload($log))->values();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(CrmTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority ?: 'normal',
            'notes' => $task->notes,
            'due_at' => $task->due_at?->toIso8601String(),
            'due_at_input' => $task->due_at?->timezone('Asia/Tehran')->format('Y-m-d\TH:i'),
            'notify_in_app' => $task->notify_in_app,
            'notify_sms' => $task->notify_sms,
            'reminded_at' => $task->reminded_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }
}
