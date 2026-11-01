<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Services\Edu\EduGroupPresenter;
use App\Services\Edu\GroupEditor;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Обуки: allocations — how many participants of a school (in their own
 * language) attend a group. Moving a smaller count than the source has
 * splits the school across groups.
 */
class EduAllocationController extends Controller
{
    public function __construct(
        private GroupEditor $editor,
        private EduGroupPresenter $presenter,
    ) {
    }

    // POST /api/edu-groups/{id}/allocations  body: {school_id, language, count, from_group_id?}
    public function store(Request $request, int $id): JsonResponse
    {
        $group = EduGroup::findOrFail($id);
        $data = $request->validate([
            'school_id' => 'required|integer',
            'language' => 'required|in:' . implode(',', EduLanguage::CODES),
            'count' => 'required|integer|min:0|max:65535',
            'from_group_id' => 'nullable|integer',
        ]);
        $from = null;
        if (!empty($data['from_group_id'])) {
            $from = EduGroup::find($data['from_group_id']);
            if (!$from) {
                throw ValidationException::withMessages(['from_group_id' => 'Изворната група не постои.']);
            }
        }
        [$target, $source] = $this->editor->allocate($group, (int) $data['school_id'], $data['language'], (int) $data['count'], $from);
        $program = $target->program;

        return response()->json([
            'group' => $this->presenter->payload($target, $program),
            'from_group' => $source ? $this->presenter->payload($source, $program) : null,
        ]);
    }

    // PUT /api/edu-group-schools/{id}  body: {participant_count}
    public function update(Request $request, int $id): JsonResponse
    {
        $allocation = EduGroupSchool::findOrFail($id);
        $data = $request->validate(['participant_count' => 'required|integer|min:0|max:65535']);
        $updated = $this->editor->setCount($allocation, (int) $data['participant_count']);
        $group = EduGroup::findOrFail($allocation->group_id);

        return response()->json([
            'allocation' => $updated,
            'group' => $this->presenter->payload($group, $group->program),
        ]);
    }

    // DELETE /api/edu-group-schools/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->editor->removeAllocation(EduGroupSchool::findOrFail($id));

        return response()->json(null, 204);
    }
}
