<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Testing\RecordingSleeper;
use IndianOpinion\MetaWriter\Caller\LlmJsonCaller;
use IndianOpinion\MetaWriter\Exception\CallFailedException;
use IndianOpinion\MetaWriter\Exception\CreditExhaustedException;
use IndianOpinion\MetaWriter\Flag;
use IndianOpinion\MetaWriter\Outcome;
use IndianOpinion\MetaWriter\Prompt;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;

final class CallTest extends TestCase
{
    /** T15, cases 16 and 17 */
    public function testRequestCarriesTheStrictSchemaAndLengthVariables(): void
    {
        $this->post(1);
        $this->suggestFor(1);
        $schema = $this->caller->schemas[0];
        $this->assertSame(['title', 'description'], array_keys($schema['properties']));
        $this->assertSame(['title', 'description'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $system = $this->caller->calls[0][0]['content'];
        $this->assertStringContainsString('between 30 and 60', $system);
        $this->assertStringContainsString('between 70 and 155', $system);
        $this->assertStringContainsString('JSON', $system);
        $this->assertStringNotContainsString('{{', $system);
    }

    /** T16, cases 18 and 21 */
    public function testLibraryExceptionMarksFailedCountsAndRunContinues(): void
    {
        $this->post(1);
        $this->post(2);
        $this->caller->queue = [new CallFailedException('upstream 500', 'ServerErrorException')];
        $report = $this->c->runner->run(['dry_run' => false]);
        $this->assertSame(1, $report->failed);
        $this->assertSame(1, $report->generated);
        $this->assertSame(2, $report->calls);
        $this->assertSame(2, $this->c->cap->used());
        $failed = $this->store->latestForPost(1);
        $this->assertSame(Status::FAILED, $failed?->status);
        $this->assertSame('ServerErrorException', $failed?->errorClass);
        $this->assertCount(2, $this->caller->calls, 'no retry by meta-writer after the library gave up');
    }

    /** T17, cases 19 and 58 */
    public function testPromptLibraryIsUsedAndFallsBackOnFailure(): void
    {
        $this->post(1);
        $this->post(2);
        $seen = [];
        add_filter('prompt_library_render', static function ($none, string $consumer, array $vars) use (&$seen) {
            $seen[] = $consumer;
            if ($vars['title'] === 'boom') {
                throw new \RuntimeException('render failed');
            }

            return ['messages' => [['role' => 'system', 'content' => 'LIBRARY ' . $vars['language']], ['role' => 'user', 'content' => (string) $vars['body']]], 'version' => '7'];
        }, 10, 3);
        $s = $this->suggestFor(1);
        $this->assertSame(['meta-writer/title-description'], $seen);
        $this->assertStringStartsWith('LIBRARY en-US', $this->caller->calls[0][0]['content']);
        $this->assertSame('library-7', $s->promptVersion);

        $this->post(2, ['post_title' => 'boom']);
        $s2 = $this->suggestFor(2);
        $this->assertSame(Prompt::BUILTIN_VERSION, $s2->promptVersion);
        $this->assertNotEmpty(\WpState::actionsNamed('meta_writer_log'));
        $last = \WpState::actionsNamed('meta_writer_log');
        $this->assertSame('warning', end($last)[1][0]);
    }

    /** T18, cases 20 and 50 */
    public function testKillSwitchStopsCallsAndTheConstantWins(): void
    {
        $this->post(1);
        $this->c->kill->set(true, 'test');
        $o = $this->c->generator->generate(1, ['dry_run' => false]);
        $this->assertSame(Reason::DISABLED, $o->reason);
        $this->assertCount(0, $this->caller->calls);
        $this->assertFalse(meta_writer_is_enabled());
        $this->assertSame([false, true, 'test'], \WpState::actionsNamed('meta_writer_kill_switch_changed')[0][1]);

        $this->c->kill->set(false, 'test');
        $this->assertTrue(meta_writer_is_enabled());
    }

    /** T18: the constant, once defined, cannot be overridden by the option. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testConstantOverridesTheOption(): void
    {
        define('META_WRITER_DISABLED', true);
        $this->post(1);
        $this->c->kill->set(false, 'test');
        $this->assertTrue($this->c->kill->isOn());
        $this->assertSame('constant', $this->c->kill->source());
        $this->assertSame(Reason::DISABLED, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        $this->assertCount(0, $this->caller->calls);
    }

    /** T19, case 22 */
    public function testCreditExhaustedStopsTheRun(): void
    {
        $this->post(1);
        $this->post(2);
        $this->post(3);
        $this->caller->queue = [$this->caller->default, new CreditExhaustedException('out', 'credit_exhausted')];
        $report = $this->c->runner->run(['dry_run' => false]);
        $this->assertSame(Reason::CREDIT_EXHAUSTED, $report->stopReason);
        $this->assertSame(1, $report->generated);
        $this->assertCount(2, $this->caller->calls);
        $this->assertSame('1', $report->nextCursor, 'resume at the post that hit the wall');
    }

    /** T24, cases 27 and 28 */
    public function testInvalidOutputRetriesOnceThenFails(): void
    {
        $this->post(1);
        $this->caller->queue = [
            ['title' => 'Too short', 'description' => 'Also short.'],
            $this->caller->default,
        ];
        $s = $this->suggestFor(1);
        $this->assertCount(2, $this->caller->calls);
        $this->assertTrue($s->hasFlag(Flag::RETRIED));
        $note = end($this->caller->calls[1]);
        $this->assertStringContainsString('too short', $note['content']);
        $this->assertSame(2, $this->c->cap->used(), 'the retry counts against the cap');

        $this->post(2);
        $this->settings(['keep_raw' => true]);
        $this->caller->queue = [['title' => '', 'description' => ''], ['title' => '', 'description' => '']];
        $o = $this->c->generator->generate(2, ['dry_run' => false]);
        $this->assertSame(Outcome::FAILED, $o->kind);
        $this->assertSame('invalid_output', $o->reason);
        $this->assertSame(2, $o->calls);
        $this->assertSame('invalid_output', $o->suggestion?->errorClass);
        $this->assertNotNull($o->suggestion?->raw);

        // Same as title also triggers the retry.
        $this->post(3, ['post_title' => 'The monsoon reached the Kerala coast three days early this year, say officials']);
        $this->caller->queue = [['title' => 'Monsoon arrives early over the Kerala coast', 'description' => 'The monsoon reached the Kerala coast three days early this year, say officials']];
        $s3 = $this->suggestFor(3);
        $this->assertTrue($s3->hasFlag(Flag::RETRIED));
    }

    /** T25, case 29 */
    public function testDuplicateDescriptionIsFlaggedNotRejected(): void
    {
        $this->post(1);
        $this->post(2, ['post_content' => self::body(80)]);
        $this->suggestFor(1);
        $s2 = $this->suggestFor(2);
        $this->assertTrue($s2->hasFlag(Flag::DUPLICATE));
        $this->assertSame(Status::PENDING, $s2->status);
    }

    /** T45, case 57 */
    public function testSpendMeterProtocol(): void
    {
        $this->post(1);
        $this->post(2);
        $events = [];
        add_filter('spend_meter_pre_spend', static fn ($none, array $req) => ['allowed' => true, 'reason' => 'ok', 'reservation' => 'r-' . $req['context']['post_id']], 10, 2);
        add_action('spend_meter_record', static function (string $r) use (&$events): void {
            $events[] = 'record:' . $r;
        }, 10, 2);
        add_action('spend_meter_release', static function (string $r) use (&$events): void {
            $events[] = 'release:' . $r;
        }, 10, 2);
        $this->caller->queue = [$this->caller->default, new CallFailedException('x', 'ServerErrorException')];
        $this->c->runner->run(['dry_run' => false]);
        $this->assertSame(['record:r-1', 'release:r-2'], $events);

        // A deny stops the run; an unknown reason is a deny.
        foreach ([['allowed' => false, 'reason' => 'budget'], ['allowed' => true, 'reason' => 'something_new'], ['allowed' => 1], 'yes'] as $answer) {
            remove_all_filters('spend_meter_pre_spend');
            add_filter('spend_meter_pre_spend', static fn () => $answer, 10, 2);
            $this->post(3);
            $calls = count($this->caller->calls);
            $report = $this->c->runner->run(['ids' => [3], 'dry_run' => false]);
            $this->assertSame(Reason::SPEND_DENIED, $report->stopReason, (string) json_encode($answer));
            $this->assertCount($calls, $this->caller->calls);
        }
    }

    /** T57, case 74 */
    public function testRefusalGivesFailedWithTheRefusalClass(): void
    {
        $transport = new MockTransport(MockTransport::json([
            'id' => 'x', 'object' => 'chat.completion', 'model' => 'm',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'], 'finish_reason' => 'stop']],
        ]));
        $client = Client::create(Chain::of(Route::to(Provider::openAi('sk-test-0000000000000000000000'), 'gpt-test')), $transport)
            ->withSleeper(new RecordingSleeper())
            ->withOptions(Options::defaults()->withReliability(false));
        $caller = new LlmJsonCaller($client, 'openai/gpt-test');
        try {
            $caller->call(Prompt::builtin(['title' => 't', 'body' => 'b', 'excerpt' => '', 'language' => 'en']), Prompt::schema());
            $this->fail('expected a failure');
        } catch (CallFailedException $e) {
            $this->assertSame('RefusalException', $e->errorClass());
        }

        // Through the generator: failed, counted, run continues.
        $this->post(1);
        $this->post(2);
        $this->caller->queue = [new CallFailedException('refused', 'RefusalException')];
        $report = $this->c->runner->run(['dry_run' => false]);
        $this->assertSame('RefusalException', $this->store->latestForPost(1)?->errorClass);
        $this->assertSame(1, $report->generated);
        $this->assertSame(2, $this->c->cap->used());
    }

    /** T16 at library level: a real llm-json-php client with a mock transport. */
    public function testLlmJsonCallerReturnsDataAndRoute(): void
    {
        $transport = new MockTransport(MockTransport::completion(['title' => 'A title', 'description' => 'A description']));
        $client = Client::create(Chain::of(Route::to(Provider::openAi('sk-test-0000000000000000000000'), 'gpt-test')), $transport)->withSleeper(new RecordingSleeper());
        $r = (new LlmJsonCaller($client))->call(Prompt::builtin(['title' => 't', 'body' => 'b', 'excerpt' => '', 'language' => 'en']), Prompt::schema());
        $this->assertSame('A title', $r->data['title']);
        $this->assertSame('openai/gpt-test', $r->route);
        $body = $transport->lastRequest()?->json();
        $this->assertIsArray($body);
        $this->assertSame('system', $body['messages'][0]['role']);
    }

    /** T58, case 75 */
    public function testStoreFailureIsLoggedCountedAndNeverThrowsFromCron(): void
    {
        $this->post(1);
        $this->store->failInsert = true;
        (new \IndianOpinion\MetaWriter\Cron($this->c))->runSingle(1);
        $this->assertSame(1, $this->c->cap->used());
        $logs = array_map(static fn (array $a): string => $a[1][1], \WpState::actionsNamed('meta_writer_log'));
        $this->assertContains('Could not store a suggestion.', $logs);
    }

    /** T61, case 79 */
    public function testMissingLibraryDisablesGenerationButAcceptWorks(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        remove_all_filters('meta_writer_caller');
        add_filter('meta_writer_llm_client', static fn () => null);
        $this->rebuild();
        $this->post(2);
        $o = $this->c->generator->generate(2, ['dry_run' => false]);
        $this->assertSame(Reason::DISABLED, $o->reason);
        $this->assertFalse(meta_writer_is_enabled());
        \WpState::$user = self::EDITOR;
        $r = meta_writer_accept($s->id);
        $this->assertInstanceOf(\IndianOpinion\MetaWriter\AcceptResult::class, $r);
        $this->assertSame(['title', 'description'], $r->written);
        $notices = (new \IndianOpinion\MetaWriter\Admin\Notices($this->c))->collect();
        $this->assertNotEmpty($notices);
    }

    /** T63, section 8 */
    public function testApiKeyNeverInLogsErrorsOrSettingsOutput(): void
    {
        $key = 'sk-live-SECRETSECRETSECRET123456';
        $this->settings(['api_key' => $key]);
        $this->post(1);
        $this->caller->queue = [new CallFailedException('401 for key ' . $key . ' Bearer ' . $key, 'AuthenticationException')];
        $this->c->generator->generate(1, ['dry_run' => false]);
        $s = $this->store->latestForPost(1);
        $this->assertStringNotContainsString($key, (string) $s?->error);
        $this->assertStringNotContainsString($key, (string) json_encode(get_option('meta_writer_log')));
        $this->assertStringNotContainsString($key, (string) json_encode(\WpState::$actions));
        $this->assertSame("\u{2026}3456", \IndianOpinion\MetaWriter\Settings::maskKey($key));
        $this->assertLessThanOrEqual(201, mb_strlen($this->c->log->redact(str_repeat('x', 500))));
    }
}
