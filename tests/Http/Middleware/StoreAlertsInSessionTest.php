<?php

namespace Tests\Http\Middleware;

use DarkGhostHunter\Laralerts\Http\Middleware\StoreAlertsInSession;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Tests\RegistersPackage;
use Tests\TestsView;

use function alert;
use function redirect;
use function tap;

class StoreAlertsInSessionTest extends TestCase
{
    use RegistersPackage;
    use TestsView;

    protected function registerRoutes(): void
    {
        $router = $this->app['router'];

        $router->get('foo')->uses(function () {
            alert('foo');
            return $this->view;
        })->middleware('web');

        $router->get('bar')->uses(function () {
            alert('bar');
            return $this->view;
        })->middleware('web');

        $router->get('empty')->uses(function () {
            alert()->message('');
            return $this->view;
        })->middleware('web');

        $router->get('persist')->uses(function () {
            alert()->message('foo');
            alert()->message('foo')->persistAs('foo.bar');
            return $this->view;
        })->middleware('web');

        $router->get('no-alert')->uses(function () {
            return $this->view;
        })->middleware('web');

        $router->get('redirect')->uses(function () {
            alert()->message('redirected');
            alert()->message('redirect persisted')->persistAs('foo.bar');
            return redirect()->to('no-alert');
        })->middleware('web');
    }

    protected function setUp(): void
    {
        $this->afterApplicationCreated([$this, 'addTestView']);
        $this->afterApplicationCreated([$this, 'registerRoutes']);

        parent::setUp();
    }

    public function test_doesnt_stores_persistent_without_session(): void
    {
        $this->app['router']->get('no-session')->uses(
            function () {
                alert()->message('foo')->persistAs('foo.bar');
                return $this->view;
            }
        )->middleware(StoreAlertsInSession::class);

        $this->get('no-session')->assertSessionMissing('_alerts');
    }

    public function test_doesnt_renders_empty_alerts(): void
    {
        $response = $this->get('empty')->assertSessionMissing('_alerts');

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    </div>

VIEW
            ,
            $response->getContent()
        );
    }

    public function test_renders_alert_one_time(): void
    {
        $response = $this->get('foo')->assertSessionMissing('_alerts');

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    <div class="alerts">
        <div class="alert" role="alert">
    foo
    </div>
    </div>
</div>

VIEW
            ,
            $response->getContent()
        );

        $this->refreshApplication();
        $this->setUp();

        $response = $this->get('empty')->assertSessionMissing('_alerts');

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    </div>

VIEW
            ,
            $response->getContent()
        );
    }

    public function test_alerts_persist_through_redirect(): void
    {
        $response = $this->followingRedirects()->get('redirect')->assertSessionHas('_alerts');

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    <div class="alerts">
        <div class="alert" role="alert">
    redirect persisted
    </div>
<div class="alert" role="alert">
    redirected
    </div>
    </div>
</div>

VIEW
            ,
            $response->getContent()
        );
    }

    public function test_persists_alerts_through_session(): void
    {
        $response = $this->get('persist')->assertSessionHas('_alerts');

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    <div class="alerts">
        <div class="alert" role="alert">
    foo
    </div>
<div class="alert" role="alert">
    foo
    </div>
    </div>
</div>

VIEW
            ,
            $response->getContent()
        );

        $session = tap($this->app['session'], function ($session): void {
            $session->ageFlashData();
        })->all();

        $this->refreshApplication();
        $this->setUp();

        $this->session($session);

        $response = $this->get('empty')->assertSessionHas('_alerts');

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    <div class="alerts">
        <div class="alert" role="alert">
    foo
    </div>
    </div>
</div>

VIEW
            ,
            $response->getContent()
        );
    }

    public function test_same_persisted_key_displaces_previous_alert_to_non_persisted(): void
    {
        Route::get('persist')->uses(function () {
            alert()->message('foo')->persistAs('foo.bar');
            alert()->message('bar')->persistAs('foo.bar');
            return $this->view;
        })->middleware('web');

        $this->get('persist');

        $session = tap($this->app['session'], function ($session): void {
            $session->ageFlashData();
        })->all();

        $this->refreshApplication();
        $this->setUp();

        $this->session($session);

        static::assertEquals(
            <<<'VIEW'
<div class="container">
    <div class="alerts">
        <div class="alert" role="alert">
    bar
    </div>
    </div>
</div>

VIEW
            , $this->get('empty')->getContent());
    }
}
