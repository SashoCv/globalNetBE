<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduMunicipality;
use App\Models\EduTopic;
use App\Models\EduTrainer;
use App\Services\Edu\EduScheduleQuery;
use App\Services\Edu\EduText;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: trainers, the training centres they can teach at (per topic or the
 * default matrix) and the topic lists they are on.
 */
class EduTrainerController extends Controller
{
    private const FIELDS = [
        'id', 'full_name', 'full_name_sq', 'aliases', 'languages', 'home_municipality_id', 'home_city_text',
        'education', 'phone', 'email', 'notes', 'is_active', 'created_at', 'updated_at',
    ];

    // GET /api/edu-trainers?search&language&hub_id&topic_id&active
    public function index(Request $request): JsonResponse
    {
        $query = EduTrainer::query()
            ->with(['homeMunicipality:id,name,region_id', 'hubs:id,trainer_id,hub_municipality_id,topic_id', 'topics:id'])
            ->withCount(['groups as upcoming_groups_count' => fn ($q) => self::upcoming($q)])
            ->orderBy('full_name');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q->where('full_name', 'like', $like)
                ->orWhere('full_name_sq', 'like', $like)
                ->orWhere('aliases', 'like', $like)
                ->orWhere('name_key', 'like', '%' . EduText::personKey($search) . '%')
                ->orWhere('email', 'like', $like));
        }
        $language = $request->query('language');
        if ($language === 'unknown') {
            $query->whereJsonLength('languages', 0);
        } elseif (EduLanguage::isValid($language)) {
            $query->whereJsonContains('languages', $language);
        }
        if ($hubId = $request->integer('hub_id')) {
            $query->whereHas('hubs', fn ($q) => $q->where('hub_municipality_id', $hubId));
        }
        if ($topicId = $request->integer('topic_id')) {
            $query->whereHas('topics', fn ($q) => $q->where('edu_topics.id', $topicId));
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return response()->json($query->get()->map(fn (EduTrainer $t) => $this->listItem($t))->values());
    }

    // GET /api/edu-trainers/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json($this->detail(EduTrainer::findOrFail($id)));
    }

    // GET /api/edu-trainers/{id}/groups?from&to&program_id&status (comma list)
    public function groups(Request $request, int $id, EduScheduleQuery $schedule): JsonResponse
    {
        $trainer = EduTrainer::findOrFail($id);
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'program_id' => 'nullable|integer',
            'status' => 'nullable|string|max:100',
        ]);
        $query = $schedule->inRange(EduGroup::where('trainer_id', $trainer->id), $data['from'] ?? null, $data['to'] ?? null);
        if (!empty($data['program_id'])) {
            $query->where('program_id', $data['program_id']);
        }
        if (!empty($data['status'])) {
            $statuses = array_values(array_intersect(array_map('trim', explode(',', $data['status'])), EduGroup::STATUSES));
            $query->whereIn('status', $statuses ?: ['-']);
        }
        // Dated groups by date, then the ones without dates.
        $groups = $query->orderByRaw('start_date is null')->orderBy('start_date')->orderBy('program_id')->orderBy('number')->get();

        return response()->json($schedule->items($groups));
    }

    /** Draft or planned groups that have not ended yet (or have no dates yet). */
    public static function upcoming($query)
    {
        return $query->whereIn('status', ['draft', 'planned'])
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()));
    }

    // POST /api/edu-trainers
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $trainer = DB::transaction(function () use ($data) {
            $trainer = EduTrainer::create($data['attributes']);
            $this->syncRelations($trainer, $data);

            return $trainer;
        });

        return response()->json($this->detail($trainer), 201);
    }

    // PUT /api/edu-trainers/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $trainer = EduTrainer::findOrFail($id);
        $data = $this->validatePayload($request, $trainer);
        DB::transaction(function () use ($trainer, $data) {
            $trainer->update($data['attributes']);
            $this->syncRelations($trainer, $data);
        });

        return response()->json($this->detail($trainer->fresh()));
    }

    // DELETE /api/edu-trainers/{id}
    public function destroy(int $id): JsonResponse
    {
        EduTrainer::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function validatePayload(Request $request, ?EduTrainer $trainer = null): array
    {
        $req = $trainer ? 'sometimes|required' : 'required';
        $data = $request->validate([
            'full_name' => "{$req}|string|max:150",
            'full_name_sq' => 'nullable|string|max:150',
            'aliases' => 'nullable|array',
            'aliases.*' => 'nullable|string|max:150',
            // May be empty: the language is not known yet.
            'languages' => 'nullable|array',
            'languages.*' => 'in:' . implode(',', EduLanguage::CODES),
            'home_municipality_id' => 'nullable|integer|exists:edu_municipalities,id',
            'home_city_text' => 'nullable|string|max:100',
            'education' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|boolean',
            'hubs' => 'nullable|array',
            'hubs.*.hub_municipality_id' => 'required|integer',
            'hubs.*.topic_id' => 'nullable|integer',
            'topic_ids' => 'nullable|array',
            'topic_ids.*' => 'integer',
        ]);

        // One query each for the centres and the topics.
        $hubs = null;
        if (array_key_exists('hubs', $data)) {
            $hubs = collect($data['hubs'] ?? [])
                ->map(fn ($h) => ['hub_municipality_id' => (int) $h['hub_municipality_id'], 'topic_id' => isset($h['topic_id']) ? (int) $h['topic_id'] : null])
                ->unique(fn ($h) => $h['hub_municipality_id'] . '|' . $h['topic_id'])
                ->values();
            $hubIds = $hubs->pluck('hub_municipality_id')->unique()->all();
            $valid = EduMunicipality::whereIn('id', $hubIds)->where('is_training_hub', true)->pluck('id')->all();
            if ($bad = array_diff($hubIds, $valid)) {
                throw ValidationException::withMessages(['hubs' => 'Не се центри за обука: ' . implode(', ', $bad) . '.']);
            }
        }
        $topicIds = null;
        if (array_key_exists('topic_ids', $data)) {
            $topicIds = array_values(array_unique(array_map('intval', $data['topic_ids'] ?? [])));
        }
        $allTopicIds = array_unique(array_merge($topicIds ?? [], $hubs ? $hubs->pluck('topic_id')->filter()->all() : []));
        if ($allTopicIds && ($bad = array_diff($allTopicIds, EduTopic::whereIn('id', $allTopicIds)->pluck('id')->all()))) {
            throw ValidationException::withMessages(['topic_ids' => 'Непостоечки теми: ' . implode(', ', $bad) . '.']);
        }

        $attributes = collect($data)->except(['hubs', 'topic_ids'])->all();
        if (array_key_exists('languages', $attributes)) {
            $attributes['languages'] = EduLanguage::normalize($attributes['languages'] ?? []);
        }
        if (array_key_exists('aliases', $attributes)) {
            $attributes['aliases'] = array_values(array_unique(array_filter(array_map(fn ($a) => trim((string) $a), $attributes['aliases'] ?? [])))) ?: null;
        }
        if (isset($attributes['full_name'])) {
            $attributes['full_name'] = EduText::clean($attributes['full_name']);
            $attributes['name_key'] = EduText::personKey($attributes['full_name']);
        }
        if (isset($attributes['email'])) {
            $attributes['email'] = mb_strtolower(trim($attributes['email']));
        }

        return ['attributes' => $attributes, 'hubs' => $hubs, 'topic_ids' => $topicIds];
    }

    private function syncRelations(EduTrainer $trainer, array $data): void
    {
        if ($data['hubs'] !== null) {
            $trainer->hubs()->delete();
            foreach ($data['hubs'] as $hub) {
                $trainer->hubs()->create($hub);
            }
        }
        if ($data['topic_ids'] !== null) {
            $trainer->topics()->sync($data['topic_ids']);
        }
    }

    private function listItem(EduTrainer $t): array
    {
        return array_merge($t->only(self::FIELDS), [
            'home_municipality' => $t->homeMunicipality?->only(['id', 'name', 'region_id']),
            // Centres of the default matrix (for every topic).
            'hub_ids' => $t->hubs->whereNull('topic_id')->pluck('hub_municipality_id')->unique()->sort()->values(),
            'topic_ids' => $t->topics->pluck('id')->sort()->values(),
            'upcoming_groups_count' => (int) ($t->upcoming_groups_count ?? 0),
        ]);
    }

    private function detail(EduTrainer $t): array
    {
        $t->load(['homeMunicipality:id,name,region_id', 'hubs:id,trainer_id,hub_municipality_id,topic_id', 'topics:id,title,short_title']);
        $t->loadCount(['groups as upcoming_groups_count' => fn ($q) => self::upcoming($q)]);

        return array_merge($this->listItem($t), [
            'hubs' => $t->hubs->map(fn ($h) => ['hub_municipality_id' => $h->hub_municipality_id, 'topic_id' => $h->topic_id])->values(),
            'topics' => $t->topics->map(fn ($topic) => $topic->only(['id', 'title', 'short_title']))->values(),
        ]);
    }
}
