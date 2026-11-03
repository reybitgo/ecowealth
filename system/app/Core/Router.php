<?php
declare(strict_types=1);

/* ==============================================================
 *  Router — explicit route table, guard enforcement and the
 *  central POST/CSRF check (plan §3.3, §3.4, §3.7).
 *
 *  Route row: [METHOD, '/path/{id}', [Controller::class, 'method'], guard]
 *
 *    guard: any | guest | member | admin | superadmin
 *
 *  `{name}` segments compile to ([0-9]+) and are handed to the
 *  controller as an associative array of parameters.
 * ============================================================== */

final class Router
{
    /** @var list<array{method:string, path:string, regex:string, params:list<string>, handler:array{0:string,1:string}, guard:string}> */
    private array $routes = [];

    /** @var array<string, true> Duplicate guard while compiling. */
    private array $seen = [];

    /**
     * @param list<array{0: string, 1: string, 2: array{0: string, 1: string}, 3?: string}> $definitions
     */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $i => $definition) {
            if (!is_array($definition) || count($definition) < 3) {
                throw new InvalidArgumentException(sprintf('Route #%d is malformed.', $i));
            }

            [$method, $path, $handler] = $definition;
            $guard = $definition[3] ?? 'member';

            $method = strtoupper((string) $method);
            $path   = '/' . trim((string) $path, '/');
            $path   = $path === '/' ? '/' : rtrim($path, '/');

            if (!is_array($handler) || count($handler) !== 2) {
                throw new InvalidArgumentException(sprintf('Route %s %s has an invalid handler.', $method, $path));
            }

            if (!in_array($guard, ['any', 'guest', 'member', 'admin', 'superadmin'], true)) {
                throw new InvalidArgumentException(sprintf('Route %s %s uses unknown guard "%s".', $method, $path, $guard));
            }

            $key = $method . ' ' . $path;
            if (isset($this->seen[$key])) {
                throw new InvalidArgumentException(sprintf('Duplicate route: %s.', $key));
            }
            $this->seen[$key] = true;

            [$regex, $params] = self::compile($path);

            $this->routes[] = [
                'method'  => $method,
                'path'    => $path,
                'regex'   => $regex,
                'params'  => $params,
                'handler' => [(string) $handler[0], (string) $handler[1]],
                'guard'   => $guard,
            ];
        }
    }

    /**
     * Compile a route pattern into a regex.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function compile(string $path): array
    {
        $parts  = preg_split('/(\{[A-Za-z_]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex  = '';
        $params = [];

        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Za-z_]+)\}$/', $part, $m) === 1) {
                $params[] = $m[1];
                $regex    .= '([0-9]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return ['#^' . $regex . '$#D', $params];
    }

    /** All compiled routes (route-inventory tests read this). */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Find the route for a method + path.
     *
     * @return array{route: array, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $method = $method === 'HEAD' ? 'GET' : $method;
        $path   = $path === '' ? '/' : $path;

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = (string) ($matches[$index + 1] ?? '');
            }

            return ['route' => $route, 'params' => $params];
        }

        return null;
    }

    /**
     * Enforce the guard, verify the CSRF token on POST, then call the
     * controller. Guards and CSRF live here — never in a view.
     *
     * @param array{route: array, params: array<string, string>} $match
     */
    public function dispatch(array $match): void
    {
        $route = $match['route'];

        if (!$this->guard($route['guard'])) {
            return;
        }

        if (Request::method() === 'POST') {
            $this->verifyCsrf();
        }

        [$class, $method] = $route['handler'];

        if (!class_exists($class) || !method_exists($class, $method)) {
            error_log(sprintf('[ecowealth] route %s %s points at a missing handler %s::%s()', $route['method'], $route['path'], $class, $method));
            View::error(404);
        }

        $controller = new $class();
        $result     = $controller->{$method}($match['params']);

        if (is_string($result)) {
            echo $result;
        }
    }

    /**
     * Guard enforcement (plan §3.4).
     *  - member/admin/superadmin: guests are sent to the login page
     *  - admin/superadmin: signed-in members get the 403 page
     *  - guest: signed-in visitors are sent to their dashboard
     */
    private function guard(string $guard): bool
    {
        switch ($guard) {
            case 'any':
                return true;

            case 'guest':
                if (auth_check()) {
                    redirect('dashboard');
                }
                return true;

            case 'member':
                if (!auth_check()) {
                    flash_set('warning', 'Please sign in to continue.');
                    redirect('login');
                }
                return true;

            case 'admin':
                if (!auth_check()) {
                    flash_set('warning', 'Please sign in to continue.');
                    redirect('login');
                }
                if (!is_admin()) {
                    View::error(403);
                }
                return true;

            case 'superadmin':
                if (!auth_check()) {
                    flash_set('warning', 'Please sign in to continue.');
                    redirect('login');
                }
                if (!is_superadmin()) {
                    View::error(403);
                }
                return true;
        }

        return false;
    }

    /**
     * Central CSRF check for every routed POST (plan §3.7).
     * XHR gets a JSON body; forms get a flash and a redirect back.
     *
     * Status 422, not the plan's 419: Apache's mod_php rewrites
     * unrecognised status codes (418/419) to 500, while 422 passes
     * through (verified against Laragon's Apache 2.4.62). The JSON
     * body still carries code "csrf", so clients key off that.
     */
    private function verifyCsrf(): void
    {
        $token = $_POST['_csrf'] ?? Request::header('X-CSRF-Token');

        if (csrf_verify(is_string($token) ? $token : null)) {
            return;
        }

        if (Request::wantsJson()) {
            json_response([
                'ok'      => false,
                'code'    => 'csrf',
                'message' => 'Your session expired. Please refresh the page and try again.',
            ], 422);
        }

        flash_set('danger', 'Your session expired. Please try again.');
        redirect_back();
    }
}
