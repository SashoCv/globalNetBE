<?php

namespace Tests\Unit\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduMunicipality;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramZone;
use App\Models\EduProgramZoneMunicipality;
use App\Models\EduSchool;
use App\Services\Edu\AutoGrouper;
use App\Services\Edu\EduProgramCounts;
use App\Services\Edu\ProgramZoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\TestCase;

class AutoGrouperTest extends TestCase
{
    use EduProgramHelpers;
    use RefreshDatabase;

    private EduProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp(false);
        $this->program = $this->eduProgram(['group_size_min' => 11, 'group_size_max' => 25]);
    }

    private function run_(array $options = []): array
    {
        return app(AutoGrouper::class)->run($this->program->fresh(), $options);
    }

    /** @param array<string, array{0: int, 1?: int, 2?: int}> $counts municipality => [mk, sq, tr] */
    private function schools(array $counts, string $prefix = 'ООУ'): array
    {
        $out = [];
        foreach ($counts as $municipality => $c) {
            $school = $this->eduSchool("{$prefix} „{$municipality}“", $municipality, ['mk']);
            $this->eduCounts($this->program, $school, $c[0], $c[1] ?? 0, $c[2] ?? 0);
            $out[$municipality] = $school;
        }

        return $out;
    }

    private function vardar(): array
    {
        return $this->schools([
            'Велес' => [12], 'Чашка' => [3], 'Кавадарци' => [8], 'Демир Капија' => [4],
            'Неготино' => [6], 'Росоман' => [2], 'Градско' => [2], 'Свети Николе' => [9],
        ]);
    }

    private function municipalitiesOf(array $group): array
    {
        return array_values(array_unique(array_column($group['allocations'], 'municipality_name')));
    }

    public function test_group_sizes_stay_within_min_and_max_when_possible(): void
    {
        $this->vardar();
        $result = $this->run_();

        $this->assertTrue($result['dry_run']);
        $this->assertCount(2, $result['groups']);
        $this->assertSame(46, array_sum(array_column($result['groups'], 'total')));
        foreach ($result['groups'] as $group) {
            $this->assertGreaterThanOrEqual(11, $group['total']);
            $this->assertLessThanOrEqual(25, $group['total']);
            $this->assertFalse($group['undersized']);
            $this->assertSame('mk', $group['language']);
            $this->assertSame('Вардарски', $group['zone']['name']);
        }
        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, EduGroup::count(), 'a dry run writes nothing');
    }

    public function test_training_centres_stay_together_and_apart(): void
    {
        $this->vardar();
        $groups = $this->run_()['groups'];

        // Велес+Чашка+Свети Николе and Кавадарци+Демир Капија+Неготино+Росоман+Градско, as in ПРИМЕР.
        $byMunicipality = [];
        foreach ($groups as $i => $group) {
            foreach ($this->municipalitiesOf($group) as $m) {
                $byMunicipality[$m] = $i;
            }
        }
        $this->assertNotSame($byMunicipality['Кавадарци'], $byMunicipality['Свети Николе']);
        $this->assertSame($byMunicipality['Велес'], $byMunicipality['Чашка']);
        $this->assertSame($byMunicipality['Кавадарци'], $byMunicipality['Демир Капија']);
        $this->assertSame($byMunicipality['Неготино'], $byMunicipality['Росоман']);
        $this->assertSame($byMunicipality['Неготино'], $byMunicipality['Градско']);

        $velesGroup = $groups[$byMunicipality['Велес']];
        $this->assertSame('Велес', $velesGroup['venue_municipality_suggestion']['name']);
        $this->assertSame('ООУ „Велес“', $velesGroup['venue_school_suggestion']['display_name']);
    }

    public function test_undersized_is_flagged_when_it_cannot_be_helped(): void
    {
        $this->schools(['Кочани' => [7]]);
        $result = $this->run_();

        $this->assertCount(1, $result['groups']);
        $this->assertSame(7, $result['groups'][0]['total']);
        $this->assertTrue($result['groups'][0]['undersized']);
        $this->assertSame('undersized', $result['groups'][0]['warnings'][0]['code']);
        $warning = collect($result['warnings'])->firstWhere('code', 'undersized');
        $this->assertSame(7, $warning['count']);
        $this->assertContains('Југоисточен', array_column($warning['suggest_into'], 'name'));
    }

    public function test_turkish_participants_join_macedonian_groups_keeping_their_language(): void
    {
        $this->schools(['Велес' => [10, 0, 3]]);
        $groups = $this->run_()['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('mk', $groups[0]['language']);
        $this->assertSame(13, $groups[0]['total']);
        $this->assertSame(['mk' => 10, 'tr' => 3], array_column($groups[0]['allocations'], 'participant_count', 'language'));

        // With tr → tr in the language map they form their own group.
        $this->program->update(['language_map' => ['mk' => 'mk', 'sq' => 'sq', 'tr' => 'tr']]);
        $this->assertSame(['mk', 'tr'], array_column($this->run_()['groups'], 'language'));
    }

    public function test_zone_exception_per_language(): void
    {
        $dolneni = $this->eduSchool('ООУ „Долненско“', 'Долнени', ['mk', 'sq']);
        $kicevo = $this->eduSchool('ООУ „Кичевско“', 'Кичево', ['sq']);
        $this->eduCounts($this->program, $dolneni, 12, 12);
        $this->eduCounts($this->program, $kicevo, 0, 5);
        $southwest = EduProgramZone::where('program_id', $this->program->id)->where('name', 'Југозападен')->first();
        app(ProgramZoneService::class)->assign($this->program, $dolneni->municipality_id, $southwest, 'sq');

        $groups = collect($this->run_()['groups'])->keyBy('language');

        $this->assertSame('Пелагониски', $groups['mk']['zone']['name']);
        $this->assertSame(12, $groups['mk']['total']);
        $this->assertSame('Југозападен', $groups['sq']['zone']['name']);
        $this->assertSame(17, $groups['sq']['total']);
        $this->assertEqualsCanonicalizing(['Долнени', 'Кичево'], $this->municipalitiesOf($groups['sq']));
    }

    public function test_a_large_school_is_split_only_when_allowed(): void
    {
        $this->schools(['Велес' => [40]]);

        $split = $this->run_()['groups'];
        $this->assertSame([20, 20], array_column($split, 'total'));
        $this->assertSame(['ООУ „Велес“'], array_values(array_unique(array_merge(...array_map(fn ($g) => array_column($g['allocations'], 'display_name'), $split)))));

        $whole = $this->run_(['split_schools' => false])['groups'];
        $this->assertCount(1, $whole);
        $this->assertSame(40, $whole[0]['total']);
        $this->assertTrue($whole[0]['oversized']);

        // max_overflow lets a group grow past the maximum instead of a second group.
        $this->schools(['Чашка' => [0]]);
        $this->eduCounts($this->program, EduSchool::where('display_name', 'ООУ „Велес“')->first(), 27);
        $this->assertCount(2, $this->run_()['groups']);
        $this->assertCount(1, $this->run_(['max_overflow' => 5])['groups']);
    }

    public function test_locked_groups_are_left_alone_and_numbering_continues(): void
    {
        $schools = $this->schools(['Велес' => [30], 'Чашка' => [5]]);
        $locked = EduGroup::create(['program_id' => $this->program->id, 'number' => 3, 'language' => 'mk', 'is_locked' => true, 'comment' => 'рачно']);
        EduGroupSchool::create(['group_id' => $locked->id, 'school_id' => $schools['Велес']->id, 'language' => 'mk', 'participant_count' => 10]);
        $old = EduGroup::create(['program_id' => $this->program->id, 'number' => 1, 'language' => 'mk']);
        EduGroupSchool::create(['group_id' => $old->id, 'school_id' => $schools['Чашка']->id, 'language' => 'mk', 'participant_count' => 5]);

        $result = $this->run_(['dry_run' => false]);

        $this->assertSame([['id' => $locked->id, 'number' => 3, 'language' => 'mk', 'zone_id' => null, 'total' => 10]], $result['kept_groups']);
        $this->assertSame([4], array_column($result['groups'], 'number'));
        $this->assertSame(25, $result['groups'][0]['total']); // 30 − 10 locked + 5
        $this->assertSame('рачно', $locked->fresh()->comment);
        $this->assertSame(10, EduGroupSchool::where('group_id', $locked->id)->value('participant_count'));
        $this->assertNull(EduGroup::find($old->id));
        $this->assertSame([3, 4], EduGroup::orderBy('number')->pluck('number')->all());
        $this->assertSame($result['groups'][0]['id'], EduGroup::where('number', 4)->value('id'));
        $this->assertSame(EduMunicipality::where('name', 'Велес')->value('id'), EduGroup::where('number', 4)->value('venue_municipality_id'));

        // keep_locked=false regroups everything.
        $all = $this->run_(['keep_locked' => false]);
        $this->assertSame([], $all['kept_groups']);
        $this->assertSame(35, array_sum(array_column($all['groups'], 'total')));
    }

    public function test_numbering_is_deterministic(): void
    {
        $this->schools(['Велес' => [14], 'Кавадарци' => [12]]);
        $this->schools(['Центар' => [30, 13], 'Карпош' => [8]], 'СОУ');

        $first = $this->run_();
        $second = $this->run_();
        $this->assertSame($first, $second);

        // Скопски zone first (mk larger groups first, then sq), then Вардарски.
        $summary = array_map(fn ($g) => [$g['number'], $g['zone']['name'], $g['language']], $first['groups']);
        $this->assertSame([1, 'Скопски', 'mk'], $summary[0]);
        $this->assertSame([2, 'Скопски', 'mk'], $summary[1]);
        $this->assertSame([3, 'Скопски', 'sq'], $summary[2]);
        $this->assertSame([4, 'Вардарски', 'mk'], $summary[3]);
        $this->assertGreaterThanOrEqual($first['groups'][1]['total'], $first['groups'][0]['total']);
    }

    public function test_invited_schools_join_the_group_with_most_of_their_municipality(): void
    {
        $this->vardar();
        $invited = $this->eduSchool('ООУ „Покането“', 'Кавадарци', ['mk']);
        $this->eduCounts($this->program, $invited, 0);

        $groups = $this->run_()['groups'];
        $with = collect($groups)->first(fn ($g) => in_array($invited->id, array_column($g['allocations'], 'school_id'), true));
        $this->assertNotNull($with);
        $this->assertContains('Кавадарци', $this->municipalitiesOf($with));
        $row = collect($with['allocations'])->firstWhere('school_id', $invited->id);
        $this->assertSame(0, $row['participant_count']);
        $this->assertSame('mk', $row['language']);
    }

    public function test_merge_undersized_only_suggests(): void
    {
        $this->schools(['Кочани' => [6], 'Струмица' => [7]]);
        $before = EduProgramZoneMunicipality::where('program_id', $this->program->id)->orderBy('id')->get(['zone_id', 'municipality_id'])->toArray();

        $plain = $this->run_();
        $this->assertCount(2, $plain['groups']);
        $this->assertSame([], $plain['zone_merges']);

        $merged = $this->run_(['merge_undersized' => true]);
        $this->assertCount(1, $merged['groups']);
        $this->assertSame(13, $merged['groups'][0]['total']);
        $this->assertCount(1, $merged['zone_merges']);
        $this->assertEqualsCanonicalizing(['Источен', 'Југоисточен'], [$merged['zone_merges'][0]['from']['name'], $merged['zone_merges'][0]['into']['name']]);
        $this->assertSame('mk', $merged['zone_merges'][0]['language']);

        // Saving the groups never applies the zone merge.
        $this->run_(['merge_undersized' => true, 'dry_run' => false]);
        $this->assertSame(1, EduGroup::count());
        $this->assertSame(8, EduProgramZone::where('program_id', $this->program->id)->count());
        $this->assertSame($before, EduProgramZoneMunicipality::where('program_id', $this->program->id)->orderBy('id')->get(['zone_id', 'municipality_id'])->toArray());
    }

    public function test_persons_mode_assigns_the_people(): void
    {
        $schools = $this->schools(['Велес' => [0], 'Чашка' => [0]]);
        foreach (['Велес' => 14, 'Чашка' => 4] as $m => $n) {
            for ($i = 1; $i <= $n; $i++) {
                EduParticipant::create(['program_id' => $this->program->id, 'school_id' => $schools[$m]->id, 'language' => $i === 1 ? 'tr' : 'mk', 'full_name' => "{$m} {$i}", 'name_key' => "{$m} {$i}"]);
            }
        }
        $this->program->update(['participant_mode' => 'persons']);
        app(EduProgramCounts::class)->syncAll($this->program->fresh());

        $result = $this->run_(['dry_run' => false]);

        $this->assertCount(1, $result['groups']);
        $group = EduGroup::first();
        $this->assertSame(18, EduParticipant::where('group_id', $group->id)->count());
        foreach (EduGroupSchool::where('group_id', $group->id)->get() as $allocation) {
            $this->assertSame($allocation->participant_count, EduParticipant::where('group_id', $group->id)
                ->where('school_id', $allocation->school_id)->where('language', $allocation->language)->count());
        }
    }

    /** A demo-sized program (865 participants all over the country, 11..25). */
    public function test_regression_with_865_participants(): void
    {
        $municipalities = EduMunicipality::orderBy('id')->get();
        $remaining = 865;
        $i = 0;
        while ($remaining > 0) {
            $m = $municipalities[$i % $municipalities->count()];
            $mk = min($remaining, ($i * 7) % 13 + 1);
            $sq = $i % 4 === 0 ? min($remaining - $mk, ($i * 3) % 9) : 0;
            $tr = $i % 11 === 0 ? min($remaining - $mk - $sq, 2) : 0;
            $school = EduSchool::create(EduSchool::nameFields("ООУ „Демо {$i}“", $m->name) + ['level' => 'primary', 'municipality_id' => $m->id, 'languages' => ['mk']]);
            $this->eduCounts($this->program, $school, $mk, $sq, $tr);
            $remaining -= $mk + $sq + $tr;
            $i++;
        }

        $result = $this->run_(['dry_run' => false]);

        $this->assertSame(865, $result['summary']['participants']);
        $this->assertSame(865, array_sum(array_column($result['groups'], 'total')));
        $this->assertSame(865, (int) EduGroupSchool::sum('participant_count'));
        $undersizedBuckets = count(array_filter($result['warnings'], fn ($w) => $w['code'] === 'undersized'));
        foreach ($result['groups'] as $group) {
            $inRange = $group['total'] >= 11 && $group['total'] <= 25;
            $this->assertSame(!$inRange, $group['undersized'] || $group['oversized']);
            $this->assertFalse($group['oversized'], "Група {$group['number']} има {$group['total']}");
        }
        $this->assertLessThanOrEqual($undersizedBuckets, $result['summary']['undersized']);
        $this->assertSame(range(1, count($result['groups'])), array_column($result['groups'], 'number'));
    }
}
