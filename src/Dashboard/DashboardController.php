<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Dashboard;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;

/**
 * Serves the dashboard pages, or explains how to install Livewire.
 */
final readonly class DashboardController
{
    public function __construct(
        private LivewireSupport $livewire,
        private ViewFactory $views,
    ) {}

    /**
     * The cluster list.
     */
    public function __invoke(): Response
    {
        return $this->page('log-insight::dashboard');
    }

    /**
     * The detail page of one cluster.
     */
    public function show(int $cluster): Response
    {
        return $this->page('log-insight::cluster', ['clusterId' => $cluster]);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data = []): Response
    {
        if (! $this->livewire->installed) {
            return new Response(
                $this->views->make('log-insight::livewire-missing', ['package' => LivewireSupport::PACKAGE])->render(),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new Response($this->views->make($view, $data)->render());
    }
}
