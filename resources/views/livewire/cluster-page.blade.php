<div class="li-stack">
    <a class="li-back" href="{{ route('log-insight.dashboard') }}">← All clusters</a>

    @if ($error !== null)
        <div class="li-card li-notice li-notice-error" role="alert">{{ $error }}</div>
    @else
        @php($row = $detail->row)
        @php($cluster = $row->cluster)
        @php($analysis = $row->analysis)
        @php($chart = \MarkusDoell\LogInsight\Dashboard\BarChart::fromDailyCounts($detail->dailyCounts))

        <section class="li-card li-section">
            <div class="li-title-row">
                <h1 class="li-break">
                    @if ($cluster->exception_class !== null)
                        {{ $cluster->exception_class }}
                    @else
                        <span class="li-level">{{ strtoupper($cluster->level->value) }}</span> log message
                    @endif
                </h1>
                <span class="li-badge li-status-{{ $row->status() }}">{{ $row->status() }}</span>
                @if ($analysis !== null)
                    <span class="li-badge li-severity-{{ $analysis->severity->value }}">{{ $analysis->severity->value }}</span>
                @endif
            </div>
            <p class="li-break li-lead">{{ $cluster->message }}</p>

            <dl class="li-facts">
                <div><dt>Occurrences</dt><dd>{{ number_format($cluster->occurrence_count) }}</dd></div>
                <div><dt>Level</dt><dd>{{ strtoupper($cluster->level->value) }}</dd></div>
                <div>
                    <dt>First seen</dt>
                    <dd><time datetime="{{ $cluster->first_seen_at->toIso8601String() }}" title="{{ $cluster->first_seen_at->diffForHumans() }}">{{ $cluster->first_seen_at->toDayDateTimeString() }}</time></dd>
                </div>
                <div>
                    <dt>Last seen</dt>
                    <dd><time datetime="{{ $cluster->last_seen_at->toIso8601String() }}" title="{{ $cluster->last_seen_at->diffForHumans() }}">{{ $cluster->last_seen_at->toDayDateTimeString() }}</time></dd>
                </div>
                <div class="li-fact-wide"><dt>Location</dt><dd class="li-code li-break">{{ $cluster->app_frame ?? '–' }}</dd></div>
                @if ($cluster->file !== null)
                    <div class="li-fact-wide"><dt>Thrown in</dt><dd class="li-code li-break">{{ $cluster->line === null ? $cluster->file : $cluster->file.':'.$cluster->line }}</dd></div>
                @endif
                <div class="li-fact-wide"><dt>Latest message</dt><dd class="li-break">{{ $cluster->sample_message }}</dd></div>
                <div class="li-fact-wide"><dt>Fingerprint</dt><dd class="li-code">{{ $cluster->fingerprint }}</dd></div>
            </dl>
        </section>

        @if ($flash !== null)
            <div class="li-card li-notice" role="status">{{ $flash }}</div>
        @endif

        <section class="li-card li-section">
            <h2>Analysis</h2>

            @if ($analysis !== null)
                <dl class="li-facts">
                    <div><dt>Severity</dt><dd><span class="li-badge li-severity-{{ $analysis->severity->value }}">{{ $analysis->severity->value }}</span></dd></div>
                    <div><dt>Confidence</dt><dd>{{ (int) round($analysis->confidence * 100) }}%</dd></div>
                    <div><dt>Model</dt><dd class="li-code">{{ $analysis->provider }} / {{ $analysis->model }}</dd></div>
                    @if ($analysis->created_at !== null)
                        <div><dt>Analyzed</dt><dd><time datetime="{{ $analysis->created_at->toIso8601String() }}" title="{{ $analysis->created_at->toDayDateTimeString() }}">{{ $analysis->created_at->diffForHumans() }}</time></dd></div>
                    @endif
                </dl>

                <h3>Root cause</h3>
                <p class="li-prose">{{ $analysis->root_cause }}</p>

                <h3>Suggested fix</h3>
                <p class="li-prose">{{ $analysis->suggested_fix }}</p>

                <h3>Affected files</h3>
                @if ($analysis->affected_files === [])
                    <p class="li-muted">None named.</p>
                @else
                    <ul class="li-files">
                        @foreach ($analysis->affected_files as $file)
                            <li class="li-code li-break">{{ $file }}</li>
                        @endforeach
                    </ul>
                @endif
            @elseif ($row->status() === \MarkusDoell\LogInsight\Reporting\ReportRow::STATUS_FAILED)
                <div class="li-notice li-notice-error li-failure" role="alert">
                    <strong>The analysis failed</strong>
                    @if ($cluster->analysis_failed_at !== null)
                        <time class="li-muted" datetime="{{ $cluster->analysis_failed_at->toIso8601String() }}" title="{{ $cluster->analysis_failed_at->toDayDateTimeString() }}">{{ $cluster->analysis_failed_at->diffForHumans() }}</time>
                    @endif
                    <p class="li-prose">{{ $cluster->analysis_error ?? 'No reason was recorded.' }}</p>
                </div>

                @if ($canRetry)
                    <div class="li-actions">
                        <button type="button" class="li-button" wire:click="retryAnalysis" wire:loading.attr="disabled">Retry analysis</button>
                        <span class="li-muted li-loading" wire:loading wire:target="retryAnalysis">Queuing…</span>
                    </div>
                @elseif ($retryBlockedReason !== null)
                    <p class="li-muted">The analysis cannot be retried: {{ $retryBlockedReason }}</p>
                @endif
            @else
                <p class="li-muted">Not analyzed yet. The analysis is queued by <code>php artisan log-insight:analyze</code> and appears here once a queue worker has processed it.</p>
            @endif
        </section>

        <section class="li-card li-section">
            <h2>Occurrences per day</h2>
            <p class="li-muted">
                {{ number_format($chart->total) }} in the last {{ count($chart->bars) }} days{{ $chart->total > 0 ? ', at most '.number_format($chart->max).' a day' : '' }}.
            </p>

            <figure class="li-chart">
                <svg viewBox="0 0 {{ $chart->width }} {{ \MarkusDoell\LogInsight\Dashboard\BarChart::HEIGHT }}" preserveAspectRatio="none" role="img" aria-label="Occurrences per day, last {{ count($chart->bars) }} days">
                    <line class="li-chart-baseline" x1="0" y1="{{ \MarkusDoell\LogInsight\Dashboard\BarChart::HEIGHT }}" x2="{{ $chart->width }}" y2="{{ \MarkusDoell\LogInsight\Dashboard\BarChart::HEIGHT }}" vector-effect="non-scaling-stroke" />
                    @foreach ($chart->bars as $bar)
                        <g class="li-chart-day">
                            <title>{{ $bar['date']->format('D, M j') }}: {{ number_format($bar['count']) }} {{ \Illuminate\Support\Str::plural('occurrence', $bar['count']) }}</title>
                            <rect class="li-chart-hit" x="{{ $bar['x'] }}" y="0" width="{{ $chart->slot() }}" height="{{ \MarkusDoell\LogInsight\Dashboard\BarChart::HEIGHT }}" />
                            @if ($bar['path'] !== '')
                                <path class="li-chart-bar" d="{{ $bar['path'] }}" />
                            @endif
                        </g>
                    @endforeach
                </svg>
                <div class="li-chart-labels" aria-hidden="true" style="grid-template-columns: repeat({{ count($chart->bars) }}, 1fr)">
                    @foreach ($chart->bars as $bar)
                        <span @class(['li-chart-today' => $loop->last])>{{ $bar['date']->format('j') }}</span>
                    @endforeach
                </div>
            </figure>

            <details>
                <summary class="li-muted">Show as table</summary>
                <table class="li-table li-table-compact">
                    <thead><tr><th scope="col">Day</th><th scope="col" class="li-num">Occurrences</th></tr></thead>
                    <tbody>
                        @foreach ($chart->bars as $bar)
                            <tr><td>{{ $bar['date']->format('D, M j') }}</td><td class="li-num">{{ number_format($bar['count']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        @if ($cluster->stack_trace !== null && $cluster->stack_trace !== '')
            <section class="li-card li-section">
                <h2>Stack trace</h2>
                <p class="li-muted">From the most recent occurrence.</p>
                <pre class="li-trace"><code>{{ $cluster->stack_trace }}</code></pre>
            </section>
        @endif

        <section class="li-card li-section">
            <h2>Latest occurrences</h2>

            @if ($detail->latestOccurrences === [])
                <p class="li-muted">No occurrences stored.</p>
            @else
                <div class="li-table-wrap">
                    <table class="li-table">
                        <thead>
                            <tr>
                                <th scope="col">Time</th>
                                <th scope="col">Level</th>
                                <th scope="col">Channel</th>
                                <th scope="col">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($detail->latestOccurrences as $occurrence)
                                <tr wire:key="occurrence-{{ $occurrence->id }}">
                                    <td class="li-nowrap"><time datetime="{{ $occurrence->occurred_at->toIso8601String() }}" title="{{ $occurrence->occurred_at->diffForHumans() }}">{{ $occurrence->occurred_at->format('Y-m-d H:i:s') }}</time></td>
                                    <td class="li-level">{{ strtoupper($occurrence->level->value) }}</td>
                                    <td>{{ $occurrence->channel }}</td>
                                    <td class="li-message">{{ $occurrence->message }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($cluster->occurrence_count > count($detail->latestOccurrences))
                    <p class="li-muted">The {{ count($detail->latestOccurrences) }} most recent of {{ number_format($cluster->occurrence_count) }}.</p>
                @endif
            @endif
        </section>
    @endif
</div>
