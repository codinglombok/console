<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Routing\Router;

/**
 * Implements the checks listed in master prompt §6:
 *  - missing CSRF on POST/PUT/DELETE routes
 *  - APP_DEBUG=true in a production env file
 *  - missing SecurityHeaders middleware in the global stack
 *
 * (Weak hasher cost params are checked by lombokclarion/security's own
 * PasswordHasher validation at boot, not here — see that package.)
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
    ) {
    }

    public static function signature(): string
    {
        return 'audit:security';
    }

    public function run(array $arguments): int
    {
        $findings = [
            ...$this->checkCsrf(),
            ...$this->checkSecurityHeaders(),
            ...$this->checkDebugInProduction(),
        ];

        if ($findings === []) {
            echo "audit:security — no issues found.\n";
            return 0;
        }

        foreach ($findings as $finding) {
            echo "$finding\n";
        }
        echo "\n" . count($findings) . " issue(s) found.\n";

        return 1;
    }

    /** @return list<string> */
    private function checkCsrf(): array
    {
        $findings = [];
        $mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        foreach ($this->router->routes() as $route) {
            if (!in_array($route->method, $mutatingMethods, true)) {
                continue;
            }

            $hasCsrf = in_array($this->csrfMiddlewareClass, [...$this->globalMiddleware, ...$route->middleware], true);
            if (!$hasCsrf) {
                $findings[] = "route {$route->method} {$route->path}: missing {$this->csrfMiddlewareClass} middleware";
            }
        }

        return $findings;
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
