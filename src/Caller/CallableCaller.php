<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Caller;

use IndianOpinion\MetaWriter\Exception\CallFailedException;
use IndianOpinion\MetaWriter\Exception\CreditExhaustedException;
use IndianOpinion\MetaWriter\Exception\MetaWriterException;

/**
 * Wraps a site-supplied callable: fn(array $messages, array $schema): array.
 * Any exception it throws becomes a failed call; throw
 * CreditExhaustedException to stop the run.
 */
final class CallableCaller implements ModelCaller
{
    /** @var \Closure(list<array{role: string, content: string}>, array<string, mixed>): mixed */
    private \Closure $fn;

    public function __construct(callable $fn, private readonly string $label = 'custom')
    {
        $this->fn = \Closure::fromCallable($fn);
    }

    public function call(array $messages, array $schema): CallResponse
    {
        try {
            $out = ($this->fn)($messages, $schema);
        } catch (CreditExhaustedException | CallFailedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $short = (new \ReflectionClass($e))->getShortName();
            throw new CallFailedException($e->getMessage(), $short, $e instanceof MetaWriterException ? $e : null);
        }
        if ($out instanceof CallResponse) {
            return $out;
        }
        if (!is_array($out)) {
            throw new CallFailedException('The custom caller did not return an array.', 'InvalidResponse');
        }

        /** @var array<string, mixed> $out */
        return new CallResponse($out, $this->label);
    }

    public function routeLabel(): string
    {
        return $this->label;
    }
}
