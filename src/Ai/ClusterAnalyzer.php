<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use MarkusDoell\LogInsight\Contracts\LlmClient;
use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Exceptions\InvalidAnalysisResponse;
use MarkusDoell\LogInsight\Llm\LlmRequest;
use MarkusDoell\LogInsight\Llm\LlmResponse;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Models\Cluster;

/**
 * Sends one cluster to the AI provider and stores the validated answer.
 * An invalid answer is retried once with a correction request.
 */
final readonly class ClusterAnalyzer
{
    public function __construct(
        private LlmClient $client,
        private ClusterContextBuilder $context,
        private AnalysisResponseParser $parser,
        private Dispatcher $events,
    ) {}

    public function analyze(Cluster $cluster): Analysis
    {
        $existing = $cluster->latestAnalysis()->first();

        // Never pay twice for the same cluster.
        if ($existing !== null) {
            return $existing;
        }

        $request = new LlmRequest(
            system: AnalysisPrompt::SYSTEM,
            messages: [['role' => 'user', 'content' => $this->context->build($cluster)]],
            schema: AnalysisPrompt::schema(),
        );

        $response = $this->client->complete($request);
        $responses = [$response];

        try {
            $data = $this->parser->parse($response->text);
        } catch (InvalidAnalysisResponse $invalid) {
            $retry = $this->client->complete($request->followUp(
                $response->text === '' ? '(empty answer)' : $response->text,
                sprintf(AnalysisPrompt::CORRECTION, $this->reason($invalid, $response)),
            ));
            $responses[] = $retry;

            $data = $this->parser->parse($retry->text);
            $response = $retry;
        }

        $analysis = $cluster->analyses()->create([
            'provider' => $this->client->provider(),
            'model' => $response->model,
            'root_cause' => $data->rootCause,
            'suggested_fix' => $data->suggestedFix,
            'affected_files' => $data->affectedFiles,
            'severity' => $data->severity,
            'confidence' => $data->confidence,
            'raw_response' => $response->raw,
            'input_tokens' => array_sum(array_map(static fn (LlmResponse $r): int => $r->inputTokens, $responses)),
            'output_tokens' => array_sum(array_map(static fn (LlmResponse $r): int => $r->outputTokens, $responses)),
        ]);

        $cluster->forceFill(['analysis_failed_at' => null, 'analysis_error' => null])->save();

        $this->events->dispatch(new AnalysisCompleted($analysis));

        return $analysis;
    }

    private function reason(InvalidAnalysisResponse $invalid, LlmResponse $response): string
    {
        $reason = lcfirst(str_replace('Invalid analysis response: ', '', $invalid->getMessage()));

        return $response->wasTruncated() ? $reason.' (the answer was cut off; keep it shorter)' : $reason;
    }
}
