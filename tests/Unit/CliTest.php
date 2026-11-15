<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Cli\Command;
use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;

final class CliTest extends TestCase
{
    /** T33, case 39 */
    public function testAcceptAllPendingWithoutYesPrintsThePlanAndWritesNothing(): void
    {
        $this->post(1);
        $this->post(2);
        $a = $this->suggestFor(1);
        $this->suggestFor(2);
        $cmd = new Command($this->c);
        $writes = \WpState::$metaWrites;
        $cmd->accept([], ['all-pending' => '']);
        $this->assertSame($writes, \WpState::$metaWrites);
        $this->assertSame(Status::PENDING, $this->store->find($a->id)?->status);
        $out = implode("\n", \WP_CLI::$lines);
        $this->assertStringContainsString('would write #' . $a->id . ' post 1 title', $out);
        $this->assertStringContainsString('Nothing written', $out);

        $cmd->accept([], ['all-pending' => '', 'yes' => '']);
        $this->assertSame(Status::ACCEPTED, $this->store->find($a->id)?->status);
    }

    public function testAcceptNeedsAUser(): void
    {
        \WpState::$user = 0;
        $this->expectException(\WpCliHalt::class);
        (new Command($this->c))->accept(['1'], ['yes' => '']);
    }

    /** T37 from the CLI: dry by default; exit code 2 when capped. */
    public function testGenerateIsDryByDefaultAndExitsTwoWhenCapped(): void
    {
        $this->post(1);
        $this->post(2);
        $cmd = new Command($this->c);
        $cmd->generate([], []);
        $this->assertCount(0, $this->caller->calls);
        $this->assertStringContainsString('Add --run', implode("\n", \WP_CLI::$lines));

        $this->settings(['daily_cap' => 1]);
        $cmd = new Command($this->c);
        try {
            $cmd->generate([], ['run' => '']);
            $this->fail('expected exit code 2');
        } catch (\WpCliHalt $e) {
            $this->assertSame(2, $e->getCode());
        }
        $this->assertCount(1, $this->caller->calls);
    }

    public function testKillAndStatus(): void
    {
        $cmd = new Command($this->c);
        $cmd->kill(['on'], []);
        $this->assertTrue($this->c->kill->isOn());
        $cmd->status([], []);
        $this->assertStringContainsString('kill_switch  on (option)', implode("\n", \WP_CLI::$lines));
        $cmd->kill(['off'], []);
        $this->assertFalse($this->c->kill->isOn());
    }
}
