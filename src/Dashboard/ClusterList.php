<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Dashboard;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Reporting\ClusterReport;
use MarkusDoell\LogInsight\Support\Since;

/**
 * The dashboard's cluster list with filters and pagination.
 */
final class ClusterList extends Component
{
    use WithPagination;

    public const NAME = 'log-insight.cluster-list';

    public const PER_PAGE = 25;

    public const ALL_TIME = 'all';

    public const DEFAULT_TIME_RANGE = '7d';

    /**
     * Time range values (a Since expression or "all") and their labels.
     */
    public const TIME_RANGES = [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        self::ALL_TIME => 'All time',
    ];

    #[Url(except: self::DEFAULT_TIME_RANGE)]
    public string $since = self::DEFAULT_TIME_RANGE;

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $status = '';

    private ClusterReport $reports;

    private ViewFactory $views;

    /**
     * Runs on every request, including Livewire updates, so filtering and
     * paging are protected by the gate as well as the initial page load.
     */
    public function boot(Gate $gate, ClusterReport $reports, ViewFactory $views): void
    {
        $gate->authorize('viewLogInsight');

        $this->reports = $reports;
        $this->views = $views;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['since', 'severity', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('since', 'severity', 'status');
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'log-insight::pagination';
    }

    public function render(): View
    {
        $this->normalizeFilters();

        $page = $this->getPage();

        try {
            $clusters = $this->reports->paginate(
                perPage: self::PER_PAGE,
                page: is_numeric($page) ? (int) $page : 1,
                since: $this->since === self::ALL_TIME ? null : Since::parse($this->since),
                minSeverity: Severity::tryFrom($this->severity),
                status: $this->status === '' ? null : $this->status,
            );
            $error = null;
        } catch (LogInsightException $exception) {
            $clusters = null;
            $error = $exception->getMessage();
        }

        return $this->views->make('log-insight::livewire.cluster-list', [
            'clusters' => $clusters,
            'error' => $error,
            'filtered' => $this->since !== self::ALL_TIME || $this->severity !== '' || $this->status !== '',
            'changed' => $this->since !== self::DEFAULT_TIME_RANGE || $this->severity !== '' || $this->status !== '',
            'timeRanges' => self::TIME_RANGES,
            'severities' => Severity::values(),
            'statuses' => ClusterReport::STATUSES,
        ]);
    }

    /**
     * Unknown values, e.g. from an edited URL, fall back to "no filter".
     */
    private function normalizeFilters(): void
    {
        if (! array_key_exists($this->since, self::TIME_RANGES)) {
            $this->since = self::DEFAULT_TIME_RANGE;
        }

        if (Severity::tryFrom($this->severity) === null) {
            $this->severity = '';
        }

        if (! in_array($this->status, ClusterReport::STATUSES, true)) {
            $this->status = '';
        }
    }
}
