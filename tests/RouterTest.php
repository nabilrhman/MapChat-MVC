<?php

namespace App\Controllers\Test {

    /**
     * A controller the router tests dispatch to; it records what ran
     */
    class Probe extends \Core\Controller
    {
        /** Each action that ran, with the route's parameters */
        public static array $calls = [];

        protected function showAction()
        {
            static::$calls[] = ['show', $this->route_params];
        }

        protected function addNewAction()
        {
            static::$calls[] = ['addNew', $this->route_params];
        }
    }
}

namespace Tests {

    use App\Controllers\Test\Probe;
    use Core\Router;
    use PHPUnit\Framework\TestCase;

    class RouterTest extends TestCase
    {
        private Router $router;

        protected function setUp(): void
        {
            Probe::$calls = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';

            $this->router = new Router();
            $this->router->add('', ['namespace' => 'Test', 'controller' => 'Probe', 'action' => 'show']);
            $this->router->add('avatar/{id:\d+}', ['namespace' => 'Test', 'controller' => 'Probe', 'action' => 'show']);
            $this->router->add('{controller}/{action}', ['namespace' => 'Test']);
        }

        public function testMatchesAFixedRoute()
        {
            $this->assertTrue($this->router->match(''));
        }

        public function testReadsVariablesFromTheUrl()
        {
            $this->router->dispatch('probe/show');

            $this->assertSame('show', Probe::$calls[0][0]);
            $this->assertSame('probe', Probe::$calls[0][1]['controller']);
        }

        public function testCustomPatternOnlyMatchesItsOwnFormat()
        {
            // On its own, without the {controller}/{action} route to fall back to
            $router = new Router();
            $router->add('avatar/{id:\d+}', ['controller' => 'Avatar', 'action' => 'show']);

            $this->assertTrue($router->match('avatar/42'));
            $this->assertFalse($router->match('avatar/abc'));
            $this->assertFalse($router->match('avatar/42/x'));
        }

        public function testCustomPatternPassesTheValue()
        {
            $this->router->dispatch('avatar/42');

            $this->assertSame('42', Probe::$calls[0][1]['id']);
        }

        public function testIgnoresCase()
        {
            $this->assertTrue($this->router->match('PROBE/SHOW'));
        }

        public function testHyphensBecomeCamelCase()
        {
            $this->router->dispatch('probe/add-new');

            $this->assertSame('addNew', Probe::$calls[0][0]);
        }

        public function testDropsTheQueryString()
        {
            // .htaccess turns "probe/show?page=1" into "probe/show&page=1"
            $this->router->dispatch('probe/show&page=1');

            $this->assertCount(1, Probe::$calls);
        }

        public function testQueryStringOnTheHomePage()
        {
            $this->router->dispatch('page=1');

            $this->assertSame('show', Probe::$calls[0][0]);
        }

        public function testUnknownUrlIs404()
        {
            $this->expectExceptionCode(404);

            $this->router->dispatch('a/b/c');
        }

        public function testUnknownControllerIs404()
        {
            $this->expectExceptionCode(404);

            $this->router->dispatch('nothing/show');
        }

        public function testUnknownActionIs404()
        {
            $this->expectExceptionCode(404);

            $this->router->dispatch('probe/missing');
        }

        public function testActionMethodCantBeCalledDirectly()
        {
            // That would skip __call, and with it the login check
            try {
                $this->router->dispatch('probe/show-action');
                $this->fail('No exception was thrown');
            } catch (\Exception $e) {
                $this->assertSame(404, $e->getCode());
            }

            $this->assertSame([], Probe::$calls);
        }
    }
}
