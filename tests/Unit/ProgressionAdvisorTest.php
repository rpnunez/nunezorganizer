<?php

namespace Tests\Unit;

use App\Domain\Reviews\ProgressionAdvisor;
use PHPUnit\Framework\TestCase;

class ProgressionAdvisorTest extends TestCase
{
    public function test_advancement_needs_week_end_consistency_and_small_backlog(): void
    {
        $advisor = new ProgressionAdvisor;
        $days = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['planned' => 1, 'completed' => 0]);
        foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
            $days[$day]['completed'] = 1;
        }

        $this->assertFalse($advisor->advise(1, '2026-11-09', '2026-11-14', $days, 0, 5, 2)['can_advance']);
        $this->assertTrue($advisor->advise(1, '2026-11-09', '2026-11-15', $days, 0, 5, 2)['can_advance']);
        $this->assertFalse($advisor->advise(1, '2026-11-09', '2026-11-15', $days, 3, 5, 2)['can_advance']);
        $days['fri']['completed'] = 0;
        $this->assertFalse($advisor->advise(1, '2026-11-09', '2026-11-15', $days, 0, 5, 2)['can_advance']);
    }
}
