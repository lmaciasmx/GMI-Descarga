<?php

namespace Tests\Unit;

use App\Common\Domain\WorkflowTransitions;
use App\Common\Exceptions\DomainException;
use CodeIgniter\Test\CIUnitTestCase;

final class StateMachineTest extends CIUnitTestCase
{
    public function testAllowsOperationalPlanSequence(): void
    {
        $machine = WorkflowTransitions::plan();
        $machine->assertCanTransition('draft', 'approved');
        $machine->assertCanTransition('approved', 'in_progress');
        $machine->assertCanTransition('in_progress', 'finished');
        self::assertTrue(true);
    }

    public function testConfirmedFlowCannotReturnToDraft(): void
    {
        $this->expectException(DomainException::class);
        WorkflowTransitions::weighing()->assertCanTransition('confirmed', 'draft');
    }
}
