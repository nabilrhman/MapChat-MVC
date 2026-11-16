<?php

namespace App\Controllers\Test {

    /**
     * A controller the router tests dispatch to; it records what ran
     */
    class Probe extends \Core\Controller
    {
        /** Each step that ran, with the route's parameters for actions */
        public static array $calls = [];

        /** What before() returns; false stops the action */
        public static ?bool $beforeResult = null;

        /**
         * Note that it ran, and stop the action if told to
         */
        protected function before(): ?bool
        {
            static::$calls[] = ['before'];

            return static::$beforeResult;
        }

        /**
         * Note that it ran
         */
        protected function after(): void
        {
            static::$calls[] = ['after'];
        }

        /**
         * Note that it ran
         */
        public function showAction(): void
        {
            static::$calls[] = ['show', $this->routeParams];
        }

        /**
         * Note that it ran
         */
        public function listAction(): void
        {
            static::$calls[] = ['list', $this->routeParams];
        }

        /**
         * Note that it ran
         */
        public function saveAction(): void
        {
            static::$calls[] = ['save', $this->routeParams];
        }

        /** Public, but no route names it */
        public function secretAction(): void
        {
            static::$calls[] = ['secret', $this->routeParams];
        }
    }
}

namespace Tests {

    use App\Controllers\Test\Probe;
    use Core\MethodNotAllowed;
    use Core\Router;
    use PHPUnit\Framework\TestCase;

    class RouterTest extends TestCase
    {
        private Router $router;

        protected function setUp(): void
        {
            Probe::$calls = [];
            Probe::$beforeResult = null;
            $_SERVER['REQUEST_METHOD'] = 'GET';
            // Posts come from this site, as the origin check needs
            $_SERVER['HTTP_HOST'] = 'mapchat.test';
            $_SERVER['HTTP_ORIGIN'] = 'http://mapchat.test';

            $this->router = new Router();
            $this->router->get('/', [Probe::class, 'showAction']);
            $this->router->get('/probe', [Probe::class, 'listAction']);
            $this->router->get('/probe/list', [Probe::class, 'listAction']);
            $this->router->post('/probe/save', [Probe::class, 'saveAction']);
            $this->router->get('/item/{id:\d+}', [Probe::class, 'showAction']);
            $this->router->get('/item/{id:\d+}/edit', [Probe::class, 'showAction']);
            $this->router->post('/item/{id:\d+}/edit', [Probe::class, 'saveAction']);
        }

        /**
         * The actions that ran, in order, each with its route's parameters
         */
        private function actionCalls(): array
        {
            return array_values(array_filter(Probe::$calls, fn ($call) => count($call) === 2));
        }

        /**
         * The names of the actions that ran, in order
         */
        private function actionsRun(): array
        {
            return array_column($this->actionCalls(), 0);
        }

        /**
         * Dispatch a request that must fail with this code before any action runs
         */
        private function assertDispatchFails(int $code, string $method, string $url): \Exception
        {
            try {
                $this->router->dispatch($method, $url);
            } catch (\Exception $e) {
                $this->assertSame($code, $e->getCode());
                $this->assertSame([], $this->actionsRun());

                return $e;
            }

            $this->fail("$method $url was dispatched");
        }

        public function testRunsTheExactActionTheRouteNames()
        {
            $this->router->dispatch('GET', '/probe/list');

            $this->assertSame(['list'], $this->actionsRun());
        }

        public function testRunsTheActionThatMatchesTheMethod()
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $this->router->dispatch('POST', '/item/7/edit');
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $this->router->dispatch('GET', '/item/7/edit');

            $this->assertSame(['save', 'show'], $this->actionsRun());
        }

        public function testAliasesRunTheSameAction()
        {
            $this->router->dispatch('GET', '/probe');
            $this->router->dispatch('GET', '/probe/list');

            $this->assertSame(['list', 'list'], $this->actionsRun());
        }

        public function testHomePage()
        {
            $this->router->dispatch('GET', '/');

            $this->assertSame(['show'], $this->actionsRun());
        }

        public function testRouteVariablesReachTheController()
        {
            $this->router->dispatch('GET', '/item/42');

            $this->assertSame([['show', ['id' => '42']]], $this->actionCalls());
        }

        public function testVariableOutsideItsPatternIs404()
        {
            $this->assertDispatchFails(404, 'GET', '/item/abc');
            $this->assertDispatchFails(404, 'GET', '/item/42/x');
            $this->assertDispatchFails(404, 'GET', '/item/');
        }

        public function testIgnoresCase()
        {
            $this->router->dispatch('GET', '/PROBE/LIST');

            $this->assertSame(['list'], $this->actionsRun());
        }

        public function testQueryStringIsNotPartOfThePath()
        {
            $this->router->dispatch('GET', '/probe/list?page=1&sort=name');
            $this->router->dispatch('GET', '/?page=1');
            $this->router->dispatch('GET', '/item/42?id=99');

            $this->assertSame(['list', 'show', 'show'], $this->actionsRun());
            $this->assertSame(['id' => '42'], $this->actionCalls()[2][1]);
        }

        public function testQueryStringCantCompleteAPath()
        {
            $this->assertDispatchFails(404, 'GET', '/probe/li?st');
            $this->assertDispatchFails(404, 'GET', '/item?/42');
        }

        public function testUnknownPathIs404()
        {
            $this->assertDispatchFails(404, 'GET', '/a/b/c');
            $this->assertDispatchFails(404, 'POST', '/a/b/c');
        }

        public function testWrongMethodIs405WithTheAllowedMethods()
        {
            $e = $this->assertDispatchFails(405, 'GET', '/probe/save');
            $this->assertInstanceOf(MethodNotAllowed::class, $e);
            $this->assertSame(['POST'], $e->allowed);

            $e = $this->assertDispatchFails(405, 'POST', '/probe/list');
            $this->assertSame(['GET', 'HEAD'], $e->allowed);

            $e = $this->assertDispatchFails(405, 'PUT', '/item/7/edit');
            $this->assertSame(['GET', 'POST', 'HEAD'], $e->allowed);
        }

        public function testHeadRunsTheGetAction()
        {
            $this->router->dispatch('HEAD', '/probe/list');

            $this->assertSame(['list'], $this->actionsRun());
        }

        public function testHeadOnAPostRouteIs405()
        {
            $e = $this->assertDispatchFails(405, 'HEAD', '/probe/save');

            $this->assertSame(['POST'], $e->allowed);
        }

        public function testUnregisteredActionCantBeReachedByUrl()
        {
            // The old {controller}/{action} route turned these into secretAction
            foreach (['/probe/secret', '/probe/secret-action', '/test/probe/secret', '/probe/before', '/probe/run-action'] as $url) {
                $this->assertDispatchFails(404, 'GET', $url);
                $this->assertDispatchFails(404, 'POST', $url);
            }

            $this->assertSame([], Probe::$calls);
        }

        public function testFiltersRunAroundTheAction()
        {
            $this->router->dispatch('GET', '/probe/list');

            $this->assertSame(['before', 'list', 'after'], array_column(Probe::$calls, 0));
        }

        protected function tearDown(): void
        {
            unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_ORIGIN']);
        }

        public function testBeforeFilterCanStopTheAction()
        {
            Probe::$beforeResult = false;

            $this->router->dispatch('GET', '/probe/list');

            $this->assertSame([['before']], Probe::$calls);
        }
    }
}
