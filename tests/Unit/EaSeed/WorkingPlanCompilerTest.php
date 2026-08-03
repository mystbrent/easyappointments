<?php

namespace Tests\Unit\EaSeed;

use EaSeed\WorkingPlanCompiler;
use Tests\TestCase;

class WorkingPlanCompilerTest extends TestCase
{
    public function testEmptyDayCompilesToNull()
    {
        $this->assertNull(WorkingPlanCompiler::compile([]));
    }

    public function testSingleSessionHasNoBreaks()
    {
        $result = WorkingPlanCompiler::compile([
            ['start' => '09:00', 'end' => '16:00'],
        ]);

        $this->assertSame('09:00', $result['start']);
        $this->assertSame('16:00', $result['end']);
        $this->assertSame([], $result['breaks']);
    }

    public function testGapBetweenSessionsBecomesABreak()
    {
        $result = WorkingPlanCompiler::compile([
            ['start' => '09:00', 'end' => '12:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ]);

        $this->assertSame('09:00', $result['start']);
        $this->assertSame('17:00', $result['end']);
        $this->assertSame([['start' => '12:00', 'end' => '13:00']], $result['breaks']);
    }

    public function testMidnightSessionAndEveningBlockCompileToOneInvertedBreak()
    {
        // Lostlocks: Mastermind Heist runs at 00:00, rooms also run 19:30 onwards.
        $result = WorkingPlanCompiler::compile([
            ['start' => '00:00', 'end' => '01:00'],
            ['start' => '19:30', 'end' => '23:59'],
        ]);

        $this->assertSame('00:00', $result['start']);
        $this->assertSame('23:59', $result['end']);
        $this->assertSame([['start' => '01:00', 'end' => '19:30']], $result['breaks']);
    }

    public function testThreeSessionsProduceTwoBreaks()
    {
        $result = WorkingPlanCompiler::compile([
            ['start' => '07:00', 'end' => '09:00'],
            ['start' => '11:00', 'end' => '13:00'],
            ['start' => '15:00', 'end' => '18:00'],
        ]);

        $this->assertSame('07:00', $result['start']);
        $this->assertSame('18:00', $result['end']);
        $this->assertSame([
            ['start' => '09:00', 'end' => '11:00'],
            ['start' => '13:00', 'end' => '15:00'],
        ], $result['breaks']);
    }
}
