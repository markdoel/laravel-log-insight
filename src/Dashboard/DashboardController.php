<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Dashboard;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;

/**
 * Serves the dashboard page, or explains how to install Livewire.
 */
final readonly class DashboardController
{
    public function __construct(
        private LivewireSupport $livewire,
        private ViewFactory $views,
    ) {}

    public function __invoke(): Response
    {
        if (! $this->livewire->installed) {
            return new Response(
                $this->views->make('log-insight::livewire-missing', ['package' => LivewireSupport::PACKAGE])->render(),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new Response($this->views->make('log-insight::dashboard')->render());
    }
}
