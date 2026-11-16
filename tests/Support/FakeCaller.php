<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Support;

use IndianOpinion\MetaWriter\Caller\CallResponse;
use IndianOpinion\MetaWriter\Caller\ModelCaller;

/**
 * Answers from a queue; when the queue is empty it repeats the default.
 */
final class FakeCaller implements ModelCaller
{
    /** @var list<array<string, mixed>|\Throwable> */
    public array $queue = [];
    /** @var list<list<array{role: string, content: string}>> */
    public array $calls = [];
    /** @var list<array<string, mixed>> */
    public array $schemas = [];
    /** @var array<string, mixed> */
    public array $default = [
        'title' => 'Monsoon arrives early over the Kerala coast',
        'description' => 'The monsoon reached the Kerala coast three days early this year, bringing heavy rain to several districts and relief to farmers.',
    ];

    public function call(array $messages, array $schema): CallResponse
    {
        $this->calls[] = $messages;
        $this->schemas[] = $schema;
        $next = array_shift($this->queue) ?? $this->default;
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return new CallResponse($next, 'fake/model', (string) json_encode($next));
    }

    public function routeLabel(): string
    {
        return 'fake/model';
    }
}
