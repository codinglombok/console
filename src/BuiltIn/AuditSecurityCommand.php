<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Routing\Router;

/**
 * Implements the checks listed in design spec §6:
 *  - missing CSRF on POST/PUT/DELETE routes
 *  - APP_DEBUG=true in a production env file
 *  - missing SecurityHeaders middleware in the global stack
 *  - mutating routes with no Authenticate middleware (§Stage 9, warning level)
 *
 * (Weak hasher cost params are checked by lombokclarion/security's own
 * PasswordHasher validation at boot, not here — see that package.)
 *
 * Findings fail the build; warnings do not. The distinction is not cosmetic: an
 * unauthenticated mutating route is often correct (login, register, a public
 * webhook), so treating it as an error would mean every real application starts
 * out red and learns to ignore this command. Warnings are printed, exit stays 0,
 * and `--public=METHOD:/path` silences the ones you have decided are intentional
 * — which turns "we meant that" into something a reviewer can see in a diff.
 *
 * Flags:
 *  --public=POST:/login  declare a route as intentionally unauthenticated
 *  --strict              treat warnings as findings (exit 1) — for a codebase
 *                        that has finished triaging them
 */
final class AuditSecurityCommand implements Command
{
    /**
     * @param list<class-string> $globalMiddleware
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $globalMiddleware,
        private readonly string $csrfMiddlewareClass,
        private readonly string $securityHeadersMiddlewareClass,
        private readonly ?string $envFilePath = null,
        /**
         * Null disables the check entirely, so an application that does not use
         * lombokclarion/auth is not nagged about a package it never installed.
         *
         * @var class-string|null
         */
        private readonly ?string $authMiddlewareClass = null,
    ) {
    }

    public static function signature(): string
    {
        return 'audit:security';
    }

    public function run(array $arguments): int
    {
        $public = [];
        $strict = false;

        foreach ($arguments as $argument) {
            if ($argument === '--strict') {
                $strict = true;
            } elseif (str_starts_with($argument, '--public=')) {
                $public[] = substr($argument, strlen('--public='));
            }
        }

        $findings = [
            ...$this->checkCsrf(),
            ...$this->checkSecurityHeaders(),
            ...$this->checkDebugInProduction(),
        ];

        $warnings = $this->checkAuthenticated($public);

        if ($strict) {
            $findings = [...$findings, ...$warnings];
            $warnings = [];
        }

        foreach ($warnings as $warning) {
            echo "$warning\n";
        }

        if ($findings === []) {
            if ($warnings !== []) {
                echo "\n" . count($warnings) . " warning(s); no issues found.\n";
            } else {
                echo "audit:security — no issues found.\n";
            }

            return 0;
        }

        foreach ($findings as $finding) {
            echo "$finding\n";
        }
        echo "\n" . count($findings) . " issue(s) found.\n";

        return 1;
    }

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return list<string> */
    private function checkCsrf(): array
    {
        $findings = [];

        foreach ($this->router->routes() as $route) {
            if (!in_array($route->method, self::MUTATING_METHODS, true)) {
                continue;
            }

            if (!$this->hasMiddleware($route, $this->csrfMiddlewareClass)) {
                $findings[] = "route {$route->method} {$route->path}: missing {$this->csrfMiddlewareClass} middleware";
            }
        }

        return $findings;
    }

    /**
     * Routes carry middleware as either a class-string (resolved from the
     * container) or a ready-made instance (RateLimit::perMinute(), Authorize::role()).
     * Checking only for the class-string — as this method used to — reports a route
     * as unprotected when it is protected by an instance. A security gate that cries
     * wolf gets muted, so it has to understand both forms.
     *
     * @param class-string $class
     */
    private function hasMiddleware(\LombokClarion\Routing\Route $route, string $class): bool
    {
        foreach ([...$this->globalMiddleware, ...$route->middleware] as $middleware) {
            if ($middleware === $class) {
                return true;
            }
            if (is_object($middleware) && $middleware instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $public
     * @return list<string>
     */
    private function checkAuthenticated(array $public): array
    {
        if ($this->authMiddlewareClass === null) {
            return [];
        }

        $warnings = [];

        foreach ($this->router->routes() as $route) {
            if (!in_array($route->method, self::MUTATING_METHODS, true)) {
                continue;
            }

            if (in_array($route->method . ':' . $route->path, $public, true)) {
                continue;
            }

            if (!$this->hasMiddleware($route, $this->authMiddlewareClass)) {
                $warnings[] = "warning: route {$route->method} {$route->path} mutates state with no "
                    . "{$this->authMiddlewareClass} middleware. If that is intentional, declare it: "
                    . "--public={$route->method}:{$route->path}";
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    private function checkSecurityHeaders(): array
    {
        if (!in_array($this->securityHeadersMiddlewareClass, $this->globalMiddleware, true)) {
            return ["global middleware stack is missing {$this->securityHeadersMiddlewareClass}"];
        }

        return [];
    }

    /** @return list<string> */
    private function checkDebugInProduction(): array
    {
        if ($this->envFilePath === null || !is_file($this->envFilePath)) {
            return [];
        }

        $contents = file_get_contents($this->envFilePath) ?: '';
        $isProduction = (bool) preg_match('/^APP_ENV=production\s*$/m', $contents);
        $debugTrue = (bool) preg_match('/^APP_DEBUG=true\s*$/m', $contents);

        if ($isProduction && $debugTrue) {
            return ["{$this->envFilePath}: APP_DEBUG=true while APP_ENV=production"];
        }

        return [];
    }
}
