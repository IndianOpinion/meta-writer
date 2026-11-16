<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Caller;

use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException as LibraryCreditExhausted;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\MetaWriter\Exception\CallFailedException;
use IndianOpinion\MetaWriter\Exception\CreditExhaustedException;
use IndianOpinion\MetaWriter\Prompt;

/**
 * Calls through indianopinion/llm-json-php. The library owns retries,
 * cooldowns, failover and JSON repair; this class never retries.
 */
final class LlmJsonCaller implements ModelCaller
{
    public const PURPOSE = 'meta-writer';

    public function __construct(
        private readonly Client $client,
        private readonly string $label = '',
        private readonly bool $keepRaw = false,
    ) {
    }

    public function call(array $messages, array $schema): CallResponse
    {
        try {
            $request = Request::for(self::PURPOSE, Schema::fromArray('title_description', $schema))
                ->withMessages($messages)
                ->withTokenCap(Prompt::MAX_OUTPUT_TOKENS)
                ->withKeepRaw($this->keepRaw);
            $result = $this->client->complete($request);
        } catch (LibraryCreditExhausted $e) {
            throw new CreditExhaustedException('Every provider is out of credit.', 'credit_exhausted', $e);
        } catch (ChainExhaustedException $e) {
            $last = $e->lastError();
            $class = $last !== null ? (new \ReflectionClass($last))->getShortName() : 'ChainExhaustedException';
            throw new CallFailedException($e->getMessage(), $class, $e);
        } catch (\Throwable $e) {
            throw new CallFailedException($e->getMessage(), (new \ReflectionClass($e))->getShortName(), $e);
        }

        /** @var array<string, mixed> $data */
        $data = $result->data();

        $usage = $result->totalUsage();

        return new CallResponse($data, $result->provider() . '/' . $result->model(), $result->raw(), $usage->cost, $usage->promptTokens, $usage->completionTokens);
    }

    public function routeLabel(): string
    {
        return $this->label;
    }
}
