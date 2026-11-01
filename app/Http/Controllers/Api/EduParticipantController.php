<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduSchool;
use App\Services\Edu\EduGroupPresenter;
use App\Services\Edu\EduProgramCounts;
use App\Services\Edu\EduText;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: participants of a program (the "persons" mode). Every change
 * recomputes the per-school counts and the allocations of the groups
 * involved.
 */
class EduParticipantController extends Controller
{
    private const RELATIONS = ['school:id,display_name,municipality_id,languages', 'municipality:id,name', 'group:id,number,language'];

    public function __construct(
        private EduProgramCounts $counts,
        private EduGroupPresenter $presenter,
    ) {
    }

    // GET /api/edu-programs/{id}/participants?school_id&group_id&language&unassigned=1&search&per_page&page
    public function index(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $query = EduParticipant::query()
            ->where('program_id', $program->id)
            ->with(self::RELATIONS)
            ->orderBy('full_name')
            ->orderBy('id');

        if ($schoolId = $request->integer('school_id')) {
            $query->where('school_id', $schoolId);
        }
        if ($groupId = $request->integer('group_id')) {
            $query->where('group_id', $groupId);
        }
        if ($request->boolean('unassigned')) {
            $query->whereNull('group_id');
        }
        if (EduLanguage::isValid($request->query('language'))) {
            $query->where('language', $request->query('language'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $query->where(fn ($q) => $q->where('full_name', 'like', $like)
                ->orWhere('name_key', 'like', '%' . EduText::personKey($search) . '%')
                ->orWhere('email', 'like', $like)
                ->orWhere('school_text', 'like', $like));
        }
        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));

        return response()->json($query->paginate($perPage));
    }

    // POST /api/edu-programs/{id}/participants
    public function store(Request $request, int $id): JsonResponse
    {
        $program = EduProgram::findOrFail($id);
        $data = $this->validatePayload($request, $program);

        if (!$program->isPersons() && EduProgramSchool::where('program_id', $program->id)->where('count_total', '>', 0)->exists()) {
            return response()->json([
                'message' => 'Програмата работи со бројки по училиште. Увезете листа на учесници или префрлете ја програмата во режим „Листа на учесници“.',
            ], 409);
        }

        $participant = DB::transaction(function () use ($program, $data) {
            if (!$program->isPersons()) {
                $program->update(['participant_mode' => EduProgram::MODE_PERSONS]);
            }
            $participant = EduParticipant::create($data + ['program_id' => $program->id]);
            $this->counts->syncSchools($program, [$participant->school_id]);
            $this->counts->syncAllocations($program, [$participant->group_id]);

            return $participant;
        });

        return response()->json($participant->load(self::RELATIONS), 201);
    }

    // PUT /api/edu-participants/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $participant = EduParticipant::findOrFail($id);
        $program = $participant->program;
        $data = $this->validatePayload($request, $program, $participant);

        DB::transaction(function () use ($participant, $program, $data) {
            $oldSchool = $participant->school_id;
            $oldGroup = $participant->group_id;
            $participant->update($data);
            $this->counts->syncSchools($program, array_filter([$oldSchool, $participant->school_id]));
            $this->counts->syncAllocations($program, [$oldGroup, $participant->group_id]);
        });

        return response()->json($participant->fresh()->load(self::RELATIONS));
    }

    // DELETE /api/edu-participants/{id}
    public function destroy(int $id): JsonResponse
    {
        $participant = EduParticipant::findOrFail($id);
        $program = $participant->program;
        DB::transaction(function () use ($participant, $program) {
            $participant->delete();
            $this->counts->syncSchools($program, array_filter([$participant->school_id]));
            $this->counts->syncAllocations($program, [$participant->group_id]);
        });

        return response()->json(null, 204);
    }

    // POST /api/edu-participants/move  body: {participant_ids: [..], group_id: id|null}
    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'participant_ids' => 'required|array|min:1|max:2000',
            'participant_ids.*' => 'integer|distinct',
            'group_id' => 'present|nullable|integer',
        ]);
        $participants = EduParticipant::whereIn('id', $data['participant_ids'])->get();
        if ($participants->count() !== count($data['participant_ids'])) {
            throw ValidationException::withMessages(['participant_ids' => 'Некои учесници не постојат.']);
        }
        if ($participants->pluck('program_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['participant_ids' => 'Учесниците мора да се од иста програма.']);
        }
        $program = EduProgram::findOrFail($participants->first()->program_id);
        if (!$program->isPersons()) {
            return response()->json(['message' => 'Програмата работи со бројки по училиште; преместете ги бројките во групите.'], 409);
        }
        $group = null;
        if ($data['group_id'] !== null) {
            $group = EduGroup::where('program_id', $program->id)->find($data['group_id']);
            if (!$group) {
                throw ValidationException::withMessages(['group_id' => 'Групата не постои во оваа програма.']);
            }
        }
        if ($participants->whereNull('school_id')->isNotEmpty() && $group) {
            throw ValidationException::withMessages(['participant_ids' => 'Учесник без училиште не може да се распореди во група.']);
        }

        $affected = array_values(array_unique(array_filter(array_merge($participants->pluck('group_id')->all(), [$group?->id]))));
        DB::transaction(function () use ($participants, $group, $program, $affected) {
            EduParticipant::whereIn('id', $participants->pluck('id'))->update(['group_id' => $group?->id]);
            $this->counts->syncAllocations($program, $affected);
        });

        $groups = EduGroup::whereIn('id', $affected)->orderBy('number')->get();
        $this->presenter->load($groups);

        return response()->json([
            'moved' => $participants->count(),
            'groups' => $groups->map(fn ($g) => $this->presenter->payload($g, $program))->values(),
        ]);
    }

    private function validatePayload(Request $request, EduProgram $program, ?EduParticipant $participant = null): array
    {
        $req = $participant ? 'sometimes|required' : 'required';
        $data = $request->validate([
            'full_name' => "{$req}|string|max:150",
            'school_id' => "{$req}|integer",
            'language' => "{$req}|in:" . implode(',', EduLanguage::CODES),
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:100',
            'municipality_id' => 'nullable|integer|exists:edu_municipalities,id',
            'group_id' => 'nullable|integer',
            'extra' => 'nullable|array',
        ]);

        if (isset($data['school_id'])) {
            $school = EduSchool::find($data['school_id']);
            if (!$school || $school->level !== $program->school_level) {
                throw ValidationException::withMessages(['school_id' => 'Училиштето не постои или не е од нивото на програмата.']);
            }
            if (!array_key_exists('municipality_id', $data) || !$data['municipality_id']) {
                $data['municipality_id'] = $school->municipality_id;
            }
        }
        if (!empty($data['group_id']) && !EduGroup::where('program_id', $program->id)->whereKey($data['group_id'])->exists()) {
            throw ValidationException::withMessages(['group_id' => 'Групата не постои во оваа програма.']);
        }
        if (isset($data['full_name'])) {
            $data['full_name'] = EduText::clean($data['full_name']);
            $data['name_key'] = EduText::personKey($data['full_name']);
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        return $data;
    }
}
