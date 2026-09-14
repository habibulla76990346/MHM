<?php

namespace Tests\Feature\Security;

use App\Domains\Security\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * §23: every admin action is gated, and the gate is not optional.
 *
 * THIS IS A STRUCTURAL SCAN, not a walk of the screens, and that is the point.
 * A test that visits every admin URL proves the ones that exist today are
 * protected; this proves the ones written next year will be, because a
 * resource or page added without an access check fails the build on the day it
 * is added rather than the day somebody notices.
 *
 * Filament's DEFAULT is to allow. A resource with no `canViewAny()` is visible
 * to every authenticated panel user, which on this platform means a Content
 * Manager could open the payments table. Deny-by-default is not something the
 * framework gives; it is something this test enforces.
 */
class AdminSurfaceAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function classesIn(string $directory, string $namespace, string $suffix = ''): array
    {
        $base = app_path($directory);

        if (! is_dir($base)) {
            return [];
        }

        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([app_path().'/', '/', '.php'], ['', '\\', ''], $file->getPathname());
            $class = 'App\\'.$relative;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! str_starts_with($class, $namespace)) {
                continue;
            }

            if ($suffix !== '' && ! str_ends_with($class, $suffix)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    public function test_every_admin_resource_decides_who_may_see_it(): void
    {
        $offences = [];

        foreach ($this->classesIn('Filament/Resources', 'App\\Filament\\Resources', 'Resource') as $class) {
            $reflection = new ReflectionClass($class);

            // Declared on the class ITSELF. Inheriting Filament's permissive
            // default is exactly the failure being prevented.
            if (! $reflection->hasMethod('canViewAny')
                || $reflection->getMethod('canViewAny')->getDeclaringClass()->getName() !== $class) {
                $offences[] = class_basename($class).' does not declare canViewAny()';
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['Filament allows by default. A resource that does not decide is a resource every panel user can open:'],
            $offences,
        )));
    }

    public function test_every_admin_page_decides_who_may_reach_it(): void
    {
        $offences = [];

        foreach ($this->classesIn('Filament/Pages', 'App\\Filament\\Pages') as $class) {
            $reflection = new ReflectionClass($class);

            if (! $reflection->hasMethod('canAccess')
                || $reflection->getMethod('canAccess')->getDeclaringClass()->getName() !== $class) {
                $offences[] = class_basename($class).' does not declare canAccess()';
            }

            // A page is a Livewire component: reaching it by URL is not the
            // only way in, so mount() aborts as well. Belt and braces, and
            // the belt has come off before.
            if ($reflection->hasMethod('mount')
                && $reflection->getMethod('mount')->getDeclaringClass()->getName() === $class) {
                $source = (string) file_get_contents($reflection->getFileName());

                if (! str_contains($source, 'abort_unless(static::canAccess()')) {
                    $offences[] = class_basename($class).'::mount() does not re-check canAccess()';
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", $offences));
    }

    /**
     * Every permission a screen asks for is one the registry declares.
     *
     * A TYPO IS AN OPEN DOOR, not an error. `$user->can('maintanence.run')`
     * returns false for everybody including a Super Admin — except that Spatie
     * grants Super Admin everything through `Gate::before`, so it returns TRUE
     * for them and false for the administrator who was supposed to have it.
     * The screen then looks correctly protected while being protected by
     * nothing.
     */
    public function test_no_screen_asks_for_a_permission_that_does_not_exist(): void
    {
        $declared = PermissionRegistry::allPermissions();
        $offences = [];

        // A recursive walk, not `glob('**')`: PHP's glob does not recurse, and
        // a scan that silently checked two directory levels is exactly how the
        // hard-coded-colour gate went decorative for a whole phase.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            preg_match_all("/->can\(\s*'([a-z0-9_.]+)'/i", $source, $matches);

            foreach ($matches[1] as $permission) {
                if (! in_array($permission, $declared, true)) {
                    $offences[] = str_replace(app_path().'/', '', $file->getPathname()).' asks for "'.$permission.'"';
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['These permissions are asked for and never declared, so only a Super Admin has them:'],
            $offences,
        )));
    }

    /**
     * Every state-changing public route is rate limited.
     *
     * SCOPED TO WHAT AN ANONYMOUS OR NEWLY-SIGNED-IN CALLER CAN REACH, because
     * that is where the cost falls: a login form, a password reset, a webhook,
     * a checkout, anything that spends money at a provider. An authenticated
     * Livewire action is a different shape of risk and is bounded by the
     * plan's own limits.
     */
    public function test_every_costly_or_guessable_route_is_throttled(): void
    {
        $mustBeThrottled = [
            'login.store', 'register.store', 'password.email', 'password.update',
            'verification.send',
            'mfa.verify', 'mfa.confirm', 'mfa.disable', 'mfa.recovery.regenerate',
            'webhooks.payments',
            'checkout.start', 'checkout.status',
            'renewal.pay', 'renewal.status',
            'voice.transcribe', 'voice.speak', 'voice.show',
            'install.database.test', 'install.application.save',
            'install.run.execute', 'install.administrator.create',
            'notifications.read', 'billing.profile',
        ];

        $offences = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! in_array($name, $mustBeThrottled, true)) {
                continue;
            }

            $throttled = collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

            if (! $throttled) {
                $offences[] = $name;
            }
        }

        $this->assertSame([], $offences, 'These routes are not rate limited: '.implode(', ', $offences));

        // And the list itself is real: a renamed route that silently dropped
        // out of the check would make this pass by covering nothing.
        $known = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();

        foreach ($mustBeThrottled as $name) {
            $this->assertContains($name, $known, 'The route "'.$name.'" no longer exists, so this gate is not checking it.');
        }
    }

    /**
     * No debug or development-only tooling is reachable.
     *
     * A profiler or a route lister left mounted is a map of the application
     * and, in several popular packages, an arbitrary-code endpoint.
     */
    public function test_no_developer_tooling_is_mounted(): void
    {
        $forbidden = ['_ignition', 'telescope', 'horizon', '_debugbar', 'clockwork', '__clockwork'];
        $mounted = [];

        foreach (app('router')->getRoutes() as $route) {
            foreach ($forbidden as $prefix) {
                if (str_starts_with($route->uri(), $prefix)) {
                    $mounted[] = $route->uri();
                }
            }
        }

        $this->assertSame([], array_unique($mounted),
            'Developer tooling is reachable over HTTP: '.implode(', ', array_unique($mounted)));
    }
}
