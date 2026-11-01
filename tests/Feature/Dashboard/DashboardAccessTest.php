<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use MarkusDoell\LogInsight\Dashboard\ClusterList;
use MarkusDoell\LogInsight\Dashboard\ClusterPage;
use MarkusDoell\LogInsight\Dashboard\LivewireSupport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class DashboardAccessTest extends DashboardTestCase
{
    #[Test]
    public function it_is_forbidden_outside_the_local_environment_by_default(): void
    {
        $this->get('/log-insight')->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get('/log-insight')
            ->assertForbidden();
    }

    #[Test]
    public function it_is_allowed_in_the_local_environment_by_default(): void
    {
        $this->inLocalEnvironment();
        $this->makeCluster(['app_frame' => 'app/Http/Controllers/OrderController.php:24', 'last_seen_at' => now()]);

        $this->get('/log-insight')
            ->assertOk()
            ->assertSee('Log Insight')
            ->assertSee('ModelNotFoundException')
            ->assertSeeText('app/Http/Controllers/OrderController.php:24');
    }

    #[Test]
    public function the_application_can_define_its_own_gate(): void
    {
        Gate::define('viewLogInsight', static fn (GenericUser $user): bool => $user->getAuthIdentifier() === 1);

        $this->get('/log-insight')->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 2]))
            ->get('/log-insight')
            ->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get('/log-insight')
            ->assertOk();
    }

    #[Test]
    public function it_defines_a_default_gate(): void
    {
        $this->assertTrue(Gate::has('viewLogInsight'));
        $this->assertFalse(Gate::allows('viewLogInsight'));
    }

    #[Test]
    #[DefineEnvironment('defineGateEarly')]
    public function the_package_does_not_overwrite_an_existing_gate(): void
    {
        $this->get('/log-insight')->assertOk();
    }

    #[Test]
    public function livewire_requests_are_authorized_too(): void
    {
        Livewire::test(ClusterList::class)->assertForbidden();
    }

    #[Test]
    #[DefineEnvironment('customRoute')]
    public function path_and_middleware_are_configurable(): void
    {
        $this->inLocalEnvironment();

        $this->get('/log-insight')->assertNotFound();
        $this->get('/admin/errors')->assertOk()->assertHeader('X-Log-Insight-Test', 'yes');
    }

    #[Test]
    #[DefineEnvironment('withoutLivewire')]
    public function it_explains_how_to_install_livewire_when_it_is_missing(): void
    {
        $this->inLocalEnvironment();

        $this->get('/log-insight')
            ->assertStatus(503)
            ->assertSee('composer require livewire/livewire');
    }

    #[Test]
    #[DefineEnvironment('withoutLivewire')]
    public function the_livewire_fallback_is_still_behind_the_gate(): void
    {
        $this->get('/log-insight')->assertForbidden();
    }

    #[Test]
    public function it_explains_missing_migrations(): void
    {
        $this->inLocalEnvironment();
        $this->dropLogInsightTables();

        $this->get('/log-insight')
            ->assertOk()
            ->assertSee('vendor:publish --tag=log-insight-migrations');
    }

    #[Test]
    public function the_cluster_page_is_forbidden_outside_the_local_environment_by_default(): void
    {
        $cluster = $this->makeCluster();

        $this->get("/log-insight/clusters/{$cluster->id}")->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get("/log-insight/clusters/{$cluster->id}")
            ->assertForbidden();
    }

    #[Test]
    public function the_cluster_page_is_allowed_in_the_local_environment_by_default(): void
    {
        $this->inLocalEnvironment();
        $cluster = $this->makeCluster();

        $this->get("/log-insight/clusters/{$cluster->id}")
            ->assertOk()
            ->assertSee('Cluster #'.$cluster->id.' · Log Insight', false)
            ->assertSee('Illuminate\Database\Eloquent\ModelNotFoundException')
            ->assertSee('href="http://localhost/log-insight"', false);
    }

    #[Test]
    public function the_cluster_page_uses_the_applications_gate(): void
    {
        Gate::define('viewLogInsight', static fn (GenericUser $user): bool => $user->getAuthIdentifier() === 1);
        $cluster = $this->makeCluster();

        $this->get("/log-insight/clusters/{$cluster->id}")->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 2]))
            ->get("/log-insight/clusters/{$cluster->id}")
            ->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 1]))
            ->get("/log-insight/clusters/{$cluster->id}")
            ->assertOk();
    }

    #[Test]
    public function unknown_clusters_are_not_found(): void
    {
        $this->inLocalEnvironment();

        $this->get('/log-insight/clusters/999')->assertNotFound();
        $this->get('/log-insight/clusters/abc')->assertNotFound();
    }

    #[Test]
    public function the_cluster_page_does_not_reveal_whether_a_cluster_exists_without_access(): void
    {
        $this->get('/log-insight/clusters/999')->assertForbidden();
    }

    #[Test]
    public function list_rows_link_to_the_cluster_page(): void
    {
        $this->inLocalEnvironment();
        $cluster = $this->makeCluster(['last_seen_at' => now()]);

        $this->get('/log-insight')
            ->assertOk()
            ->assertSee('href="http://localhost/log-insight/clusters/'.$cluster->id.'"', false);
    }

    #[Test]
    public function livewire_requests_of_the_cluster_page_are_authorized_too(): void
    {
        $cluster = $this->makeCluster();

        Livewire::test(ClusterPage::class, ['clusterId' => $cluster->id])->assertForbidden();
    }

    #[Test]
    public function the_retry_action_is_authorized(): void
    {
        Queue::fake();
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
        $cluster = $this->makeCluster(['analysis_failed_at' => now(), 'analysis_error' => 'Overloaded.']);

        $allowed = true;
        Gate::define('viewLogInsight', static function (mixed $user = null) use (&$allowed): bool {
            return $allowed;
        });

        $component = Livewire::test(ClusterPage::class, ['clusterId' => $cluster->id])->assertSee('Retry analysis');

        // Access is revoked while the page is open.
        $allowed = false;
        $component->call('retryAnalysis')->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNotNull($cluster->refresh()->analysis_failed_at);
        $this->assertSame('Overloaded.', $cluster->analysis_error);
    }

    #[Test]
    #[DefineEnvironment('withoutLivewire')]
    public function the_cluster_page_explains_how_to_install_livewire_when_it_is_missing(): void
    {
        $this->inLocalEnvironment();

        $this->get('/log-insight/clusters/1')
            ->assertStatus(503)
            ->assertSee('composer require livewire/livewire');
    }

    #[Test]
    public function the_cluster_page_explains_missing_migrations(): void
    {
        $this->inLocalEnvironment();
        $this->dropLogInsightTables();

        $this->get('/log-insight/clusters/1')
            ->assertOk()
            ->assertSee('vendor:publish --tag=log-insight-migrations');
    }

    protected function defineGateEarly(Application $app): void
    {
        Gate::define('viewLogInsight', static fn (mixed $user = null): bool => true);
    }

    protected function customRoute(Application $app): void
    {
        $app['config']->set('log-insight.dashboard.path', '/admin/errors/');
        $app['config']->set('log-insight.dashboard.middleware', ['web', 'log-insight-test']);
        $app->make(Router::class)->aliasMiddleware('log-insight-test', TestHeaderMiddleware::class);
    }

    protected function withoutLivewire(Application $app): void
    {
        $app->instance(LivewireSupport::class, new LivewireSupport(installed: false));
    }

    private function dropLogInsightTables(): void
    {
        Schema::dropIfExists('log_insight_analyses');
        Schema::dropIfExists('log_insight_occurrences');
        Schema::dropIfExists('log_insight_clusters');
    }
}
