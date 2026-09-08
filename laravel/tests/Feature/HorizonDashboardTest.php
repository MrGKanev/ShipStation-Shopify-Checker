<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Tests\TestCase;

class HorizonDashboardTest extends TestCase
{
    public function test_horizon_uses_bounded_redis_supervisors(): void
    {
        $this->assertSame('admin/horizon', config('horizon.path'));
        $this->assertSame('default', config('horizon.use'));
        $this->assertSame(['critical', 'default', 'notifications'], config('horizon.defaults.supervisor-1.queue'));
        $this->assertSame(3, config('horizon.defaults.supervisor-1.tries'));
        $this->assertSame(60, config('horizon.defaults.supervisor-1.timeout'));
        $this->assertSame(10, config('horizon.environments.production.supervisor-1.maxProcesses'));
        $this->assertTrue(config('horizon.fast_termination'));
        $this->assertSame('admin/horizon/{view?}', Route::getRoutes()->getByName('horizon.index')?->uri());
    }

    public function test_dashboard_requires_an_administrator_even_locally(): void
    {
        $viewer = User::factory()->make();
        $admin = User::factory()->admin()->make();

        $this->assertFalse(Gate::forUser($viewer)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));

        $this->assertFalse(Horizon::check($this->requestFor($viewer)));
        $this->assertTrue(Horizon::check($this->requestFor($admin)));
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/admin/horizon');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }
}
