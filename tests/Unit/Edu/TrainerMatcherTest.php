<?php

namespace Tests\Unit\Edu;

use App\Models\EduGroupSchool;
use App\Models\EduProgram;
use App\Models\EduTopic;
use App\Services\Edu\TrainerMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EduProgramHelpers;
use Tests\Support\EduTrainerHelpers;
use Tests\TestCase;

class TrainerMatcherTest extends TestCase
{
    use EduProgramHelpers;
    use EduTrainerHelpers;
    use RefreshDatabase;

    private EduProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eduSetUp(false);
        $this->program = $this->eduProgram();
    }

    private function candidates($group, bool $all = true): array
    {
        return collect(app(TrainerMatcher::class)->candidates($group->fresh(), ['include_ineligible' => $all]))->keyBy(fn ($c) => $c['trainer']['full_name'])->all();
    }

    public function test_topic_matrix_comes_before_the_default_matrix(): void
    {
        $topic = EduTopic::create(['title' => 'Кариерно водење']);
        // Default matrix: Велес; for this topic only Битола.
        $this->eduTrainer('Ана', ['mk'], ['Велес'], null, [$topic->id => ['Битола']], [$topic->id]);

        $withTopic = $this->eduProgram(['topic_id' => $topic->id]);
        $c = $this->candidates($this->eduGroup($withTopic, 'Велес'))['Ана'];
        $this->assertFalse($c['eligible']);
        $this->assertSame('topic', $c['matrix']);
        $this->assertContains('no_hub', $c['blocking']);
        $c = $this->candidates($this->eduGroup($withTopic, 'Битола'))['Ана'];
        $this->assertTrue($c['eligible']);
        $this->assertContains('hub_ok', $c['reasons']);
        $this->assertContains('hub_topic_matrix', $c['reasons']);

        // Without a topic the default matrix applies.
        $c = $this->candidates($this->eduGroup($this->program, 'Велес'))['Ана'];
        $this->assertTrue($c['eligible']);
        $this->assertSame('default', $c['matrix']);
        $c = $this->candidates($this->eduGroup($this->program, 'Битола'))['Ана'];
        $this->assertFalse($c['eligible']);

        // A venue that is not a centre counts as its centre (Чашка → Велес).
        $this->assertTrue($this->candidates($this->eduGroup($this->program, 'Чашка'))['Ана']['eligible']);
    }

    public function test_without_a_matrix_the_home_centre_and_its_region_are_used(): void
    {
        $this->eduTrainer('Бојан', ['mk'], [], 'Чашка');   // home centre Велес, region Вардарски
        $this->eduTrainer('Без дом', ['mk']);

        $c = $this->candidates($this->eduGroup($this->program, 'Велес'))['Бојан'];
        $this->assertTrue($c['eligible']);
        $this->assertSame('home', $c['matrix']);
        $this->assertContains('hub_fallback_home', $c['reasons']);
        $this->assertContains('home_hub', $c['reasons']);
        $this->assertSame(3, $c['score']);

        $c = $this->candidates($this->eduGroup($this->program, 'Кавадарци'))['Бојан'];   // a centre of the same region
        $this->assertTrue($c['eligible']);
        $this->assertContains('home_region', $c['reasons']);
        $this->assertSame(2, $c['score']);

        $c = $this->candidates($this->eduGroup($this->program, 'Битола'))['Бојан'];
        $this->assertFalse($c['eligible']);
        $this->assertSame(['no_hub'], $c['blocking']);

        $c = $this->candidates($this->eduGroup($this->program, 'Велес'))['Без дом'];
        $this->assertFalse($c['eligible']);
        $this->assertNull($c['matrix']);
    }

    public function test_wrong_language_is_a_hard_problem(): void
    {
        $this->eduTrainer('Арбен', ['sq'], ['Велес']);
        $this->eduTrainer('Двојазичен', ['mk', 'sq'], ['Велес']);
        $group = $this->eduGroup($this->program, 'Велес');

        $all = $this->candidates($group);
        $this->assertFalse($all['Арбен']['eligible']);
        $this->assertSame(['language_mismatch'], $all['Арбен']['blocking']);
        $this->assertContains('language_ok', $all['Двојазичен']['reasons']);
        $this->assertSame(['Двојазичен'], array_keys($this->candidates($group, false)));

        $sq = $this->candidates($this->eduGroup($this->program, 'Велес', [], 'sq'));
        $this->assertTrue($sq['Арбен']['eligible']);
    }

    public function test_unknown_language_is_only_a_soft_problem(): void
    {
        $this->eduTrainer('Непознат', [], ['Велес']);
        $this->eduTrainer('Познат', ['mk'], ['Велес']);
        $c = $this->candidates($this->eduGroup($this->program, 'Велес'));

        $this->assertTrue($c['Непознат']['eligible']);
        $this->assertSame(['language_unknown'], $c['Непознат']['problems']);
        $this->assertSame([], $c['Непознат']['blocking']);
        $this->assertSame(-2, $c['Непознат']['score']);
        $this->assertSame(0, $c['Познат']['score']);
    }

    public function test_topic_list_membership(): void
    {
        $topic = EduTopic::create(['title' => 'Инклузија']);
        $program = $this->eduProgram(['topic_id' => $topic->id]);
        $this->eduTrainer('На листа', ['mk'], ['Велес'], null, [], [$topic->id]);
        $this->eduTrainer('Вон листа', ['mk'], ['Велес']);

        $c = $this->candidates($this->eduGroup($program, 'Велес'));
        $this->assertTrue($c['На листа']['eligible']);
        $this->assertContains('topic_ok', $c['На листа']['reasons']);
        $this->assertFalse($c['Вон листа']['eligible']);
        $this->assertSame(['not_in_topic'], $c['Вон листа']['blocking']);
    }

    public function test_overlapping_sessions_of_other_groups_are_conflicts(): void
    {
        $busy = $this->eduTrainer('Зафатен', ['mk'], ['Велес']);
        $other = $this->eduProgram(['title' => 'Друга програма']);
        $this->eduGroup($other, 'Велес', [['2026-06-02', '09:00', '12:00']], 'mk', ['trainer_id' => $busy->id]);

        $c = $this->candidates($this->eduGroup($this->program, 'Велес', ['2026-06-01', '2026-06-02']))['Зафатен'];
        $this->assertFalse($c['eligible']);
        $this->assertSame(['conflict'], $c['blocking']);
        $this->assertSame('2026-06-02', $c['conflicts'][0]['date']);
        $this->assertSame('Друга програма', $c['conflicts'][0]['program_title']);

        // Same day, no overlap in time; another day; no dates at all.
        $this->assertTrue($this->candidates($this->eduGroup($this->program, 'Велес', [['2026-06-02', '13:00', '16:00']]))['Зафатен']['eligible']);
        $this->assertTrue($this->candidates($this->eduGroup($this->program, 'Велес', ['2026-06-03']))['Зафатен']['eligible']);
        $this->assertTrue($this->candidates($this->eduGroup($this->program, 'Велес'))['Зафатен']['eligible']);

        // With conflicts set to "warn" the trainer stays eligible with a penalty.
        config(['edu.trainer_rules.conflict' => 'warn']);
        $c = $this->candidates($this->eduGroup($this->program, 'Велес', ['2026-06-02']))['Зафатен'];
        $this->assertTrue($c['eligible']);
        $this->assertContains('conflict', $c['problems']);
        $this->assertLessThan(0, $c['score']);
    }

    public function test_cancelled_groups_are_ignored(): void
    {
        $trainer = $this->eduTrainer('Слободен', ['mk'], ['Велес']);
        $this->eduGroup($this->program, 'Велес', ['2026-06-02'], 'mk', ['trainer_id' => $trainer->id, 'status' => 'cancelled']);

        $c = $this->candidates($this->eduGroup($this->program, 'Велес', ['2026-06-02']))['Слободен'];
        $this->assertTrue($c['eligible']);
        $this->assertSame([], $c['conflicts']);
        $this->assertSame(0, $c['load']);
    }

    public function test_candidates_are_ordered_by_score_then_name(): void
    {
        $this->eduTrainer('Ѓорѓи', ['mk'], ['Велес'], 'Велес');         // home centre +3
        $this->eduTrainer('Вера', ['mk'], ['Велес'], 'Кавадарци');      // same region +2
        $this->eduTrainer('Борис', ['mk'], ['Велес']);                  // 0
        $this->eduTrainer('Ана', ['mk'], ['Велес']);                    // 0, before Борис
        $this->eduTrainer('Јана', ['mk'], ['Велес'], 'Велес');          // +3 but two groups already −2
        $this->eduTrainer('Неактивен', ['mk'], ['Велес'], 'Велес', [], [], false);
        $jana = \App\Models\EduTrainer::where('full_name', 'Јана')->first();
        $this->eduGroup($this->program, 'Битола', [], 'mk', ['trainer_id' => $jana->id]);
        $this->eduGroup($this->program, 'Битола', [], 'mk', ['trainer_id' => $jana->id]);

        $group = $this->eduGroup($this->program, 'Велес');
        $list = app(TrainerMatcher::class)->candidates($group);
        $this->assertSame(['Ѓорѓи', 'Вера', 'Јана', 'Ана', 'Борис'], array_map(fn ($c) => $c['trainer']['full_name'], $list));
        $this->assertSame([3, 2, 1, 0, 0], array_column($list, 'score'));
        $this->assertSame(2, $list[2]['load']);

        // Ineligible ones come last, on request only.
        $all = app(TrainerMatcher::class)->candidates($group, ['include_ineligible' => true]);
        $this->assertSame('Неактивен', end($all)['trainer']['full_name']);
        $this->assertSame(['inactive'], end($all)['blocking']);
    }

    public function test_hub_rule_can_be_a_suggestion_and_venue_falls_back_to_the_schools(): void
    {
        $this->eduTrainer('Битолчанка', ['mk'], ['Битола']);
        config(['edu.trainer_rules.hub' => 'suggest']);
        $c = $this->candidates($this->eduGroup($this->program, 'Велес'))['Битолчанка'];
        $this->assertTrue($c['eligible']);
        $this->assertSame(['no_hub'], $c['problems']);
        $this->assertSame(-3, $c['score']);
        config(['edu.trainer_rules.hub' => 'strict']);

        // No venue yet: the centre most participants come from.
        $group = $this->eduGroup($this->program, null);
        $school = $this->eduSchool('ООУ „Гоце Делчев“', 'Ресен');
        $this->eduCounts($this->program, $school, 10);
        EduGroupSchool::create(['group_id' => $group->id, 'school_id' => $school->id, 'language' => 'mk', 'participant_count' => 10]);
        $c = $this->candidates($group)['Битолчанка'];
        $this->assertTrue($c['eligible']);
        $this->assertContains('hub_from_schools', $c['reasons']);

        // An empty group without a venue: the centre is not checked.
        $c = $this->candidates($this->eduGroup($this->program, null))['Битолчанка'];
        $this->assertTrue($c['eligible']);
        $this->assertSame(['no_venue'], $c['problems']);
    }
}
