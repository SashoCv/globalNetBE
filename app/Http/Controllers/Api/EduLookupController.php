<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EduGroup;
use App\Models\EduLocation;
use App\Models\EduMunicipality;
use App\Models\EduProgram;
use App\Models\EduRegion;
use App\Models\EduSchool;
use App\Models\EduTopic;
use App\Support\Edu\EduLanguage;
use Illuminate\Http\JsonResponse;

/**
 * Обуки: everything the admin pickers need in one call.
 */
class EduLookupController extends Controller
{
    // GET /api/edu-lookups
    public function index(): JsonResponse
    {
        $municipalities = EduMunicipality::query()
            ->orderBy('name')
            ->get(['id', 'name', 'name_sq', 'region_id', 'is_training_hub', 'hub_municipality_id', 'hub_short_name', 'part_of_skopje']);

        return response()->json([
            'regions' => EduRegion::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order']),
            'municipalities' => $municipalities,
            'hubs' => $municipalities->where('is_training_hub', true)
                ->map(fn ($m) => $m->only(['id', 'name', 'hub_short_name', 'region_id']))
                ->values(),
            'topics' => EduTopic::query()->orderBy('id')->get(['id', 'title', 'short_title']),
            'languages' => EduLanguage::options(),
            'enums' => [
                'school_levels' => collect(EduSchool::LEVEL_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'location_kinds' => collect(EduLocation::KIND_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'staff_types' => collect(EduProgram::STAFF_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'plural' => EduProgram::STAFF_PLURAL[$value]])->values(),
                'program_statuses' => collect(EduProgram::STATUS_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'participant_modes' => collect(EduProgram::PARTICIPANT_MODE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'trainer_assignment_modes' => collect(EduProgram::ASSIGN_MODE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'group_statuses' => collect(EduGroup::STATUS_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            ],
        ]);
    }
}
