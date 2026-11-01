<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MarkusDoell\LogInsight\Ai\AnalysisScheduler;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Reporting\ClusterDetailReport;
use MarkusDoell\LogInsight\Reporting\ReportRow;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The dashboard's detail page for a single cluster.
 */
final class ClusterPage extends Component
{
    public const NAME = 'log-insight.cluster-page';

    #[Locked]
    public int $clusterId;

    public ?string $flash = null;

    private ClusterDetailReport $details;

    private AnalysisScheduler $scheduler;

    private ViewFactory $views;

    /**
     * Runs on every request, including Livewire updates, so the retry
     * action is protected by the gate as well as the initial page load.
     */
    public function boot(Gate $gate, ClusterDetailReport $details, AnalysisScheduler $scheduler, ViewFactory $views): void
    {
        $gate->authorize('viewLogInsight');

        $this->details = $details;
        $this->scheduler = $scheduler;
        $this->views = $views;
    }

    public function mount(int $clusterId): void
    {
        $this->clusterId = $clusterId;
    }

    /**
     * Queues a failed analysis again. Analyzed and pending clusters are left alone.
     */
    public function retryAnalysis(): void
    {
        $cluster = $this->details->cluster($this->clusterId)
            ?? throw new NotFoundHttpException;

        $this->flash = $this->scheduler->retry($cluster)
            ? 'The analysis was queued again. It appears here once a queue worker has processed it.'
            : 'The analysis cannot be retried right now.';
    }

    public function render(): View
    {
        try {
            $detail = $this->details->find($this->clusterId);
            $error = null;
        } catch (LogInsightException $exception) {
            $detail = null;
            $error = $exception->getMessage();
        }

        if ($detail === null && $error === null) {
            throw new NotFoundHttpException;
        }

        $failed = $detail?->row->status() === ReportRow::STATUS_FAILED;

        return $this->views->make('log-insight::livewire.cluster-page', [
            'detail' => $detail,
            'error' => $error,
            'canRetry' => $failed && $this->scheduler->canRetry($detail->row->cluster),
            'retryBlockedReason' => $failed ? $this->retryBlockedReason() : null,
        ]);
    }

    /**
     * Why a failed cluster cannot be retried, so the button is not silently missing.
     */
    private function retryBlockedReason(): ?string
    {
        return match (true) {
            ! $this->scheduler->isEnabled() => 'AI analysis is disabled (LOG_INSIGHT_ANALYSIS_ENABLED).',
            ! $this->scheduler->isConfigured() => 'No API key is configured for the AI provider.',
            default => null,
        };
    }
}
