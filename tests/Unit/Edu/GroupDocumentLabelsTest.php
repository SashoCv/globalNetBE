<?php

namespace Tests\Unit\Edu;

use App\Support\Edu\GroupDocumentLabels;
use Tests\TestCase;

class GroupDocumentLabelsTest extends TestCase
{
    public function test_bilingual_is_auto_for_albanian_groups_unless_overridden(): void
    {
        $this->assertTrue(GroupDocumentLabels::bilingual('sq'));
        $this->assertFalse(GroupDocumentLabels::bilingual('mk'));
        $this->assertFalse(GroupDocumentLabels::bilingual('sq', false));
        $this->assertTrue(GroupDocumentLabels::bilingual('mk', true));
    }

    public function test_labels(): void
    {
        $mk = GroupDocumentLabels::make(false);
        $this->assertSame('Обучувач', $mk['trainer']);
        $this->assertSame('Група 3', GroupDocumentLabels::number($mk['group'], 3));

        $both = GroupDocumentLabels::make(true);
        $this->assertSame('Обучувач / Trajner', $both['trainer']);
        $this->assertSame('Група 3 / Grupi 3', GroupDocumentLabels::number($both['group'], 3));
        $this->assertSame('1. ден / Dita 1', GroupDocumentLabels::number($both['day'], 1));
        $this->assertSame('E-mail / Телефон / Telefoni', $both['contact']);
        $this->assertSame('Отпечатено / Shtypur më', $both['printed']);
        $this->assertSame('Албански / Shqip', $both['languages']['sq']);
        $this->assertSame('Наставници / Mësimdhënës', $both['staff']['teachers']);

        // Every Macedonian label has an Albanian one.
        $config = config('edu.document_labels');
        $this->assertSame(array_keys($config['mk']), array_keys($config['sq']));
        foreach (['staff', 'languages'] as $map) {
            $this->assertSame(array_keys($config['mk'][$map]), array_keys($config['sq'][$map]));
        }
    }
}
