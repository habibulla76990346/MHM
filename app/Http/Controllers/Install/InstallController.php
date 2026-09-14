<?php

namespace App\Http\Controllers\Install;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Support\Redactor;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Identity\Models\UserProfile;
use App\Domains\Security\Services\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Install\EnvWriter;
use App\Support\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PDO;
use PDOException;
use Throwable;

/**
 * The browser installer (Owner Addendum E §4).
 *
 * IT EXISTS BECAUSE SSH IS NOT ASSUMED. The owner's constraint is explicit:
 * no shell, no Composer, no Node on every server. Three of those are solved by
 * shipping `vendor/` and compiled assets in the release package. The fourth —
 * running migrations and generating an application key — needs this.
 *
 * THE COMMAND LINE REMAINS THE PREFERRED ROUTE and is documented as such. This
 * is the fallback for constrained hosting, not the default path.
 *
 * IT IS ALSO THE MOST DANGEROUS THING IN THE CODEBASE, which is why every
 * action here goes through `Installer::isOpen()` and why that answer is
 * computed from three independent conditions — a switch, a lock file, and
 * whether the database already has accounts. The third is the one an attacker
 * cannot arrange.
 *
 * NOTHING ENTERED HERE IS EVER ECHOED OR LOGGED. The database password and the
 * first administrator's password both pass through this controller, and
 * neither appears in a response, a validation message, a log line or a
 * session flash.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    /** Step 1 — what this server can and cannot do. */
    public function requirements(CheckRegistry $registry, Request $request): View
    {
        // The SAME catalogue the System Health screen uses. A separate list of
        // installer requirements would be a second thing to keep in step, and
        // the first one to drift.
        $results = collect($registry->run(automaticOnly: true))
            ->filter(fn ($r) => in_array($r->category->value, ['php', 'filesystem'], true))
            ->values();

        return view('install.requirements', [
            'results' => $results,
            'blocked' => $results->contains(fn ($r) => $r->status === Status::Red),
            // A warning, not a block: plenty of people install over HTTP on a
            // machine only they can reach, and refusing would strand them.
            'insecure' => ! $request->isSecure(),
        ]);
    }

    /** Step 2 — the database, tested before anything is written. */
    public function database(): View
    {
        return view('install.database', ['values' => session('install.database', [])]);
    }

    public function testDatabase(Request $request, EnvWriter $env): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            // A real connection, before a single byte is written. Discovering
            // a wrong password after `.env` has been rewritten leaves the
            // owner with a broken file and no way to fix it without a shell.
            new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $data['host'], $data['port'], $data['database']),
                $data['username'],
                (string) ($data['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
        } catch (PDOException $e) {
            return back()
                // The driver's own message, which names the host and the
                // reason and never the password.
                ->withErrors(['database' => $this->databaseMessage($e)])
                // The password is deliberately NOT flashed back.
                ->withInput($request->except('password'));
        }

        $this->writer($env)->write([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => (string) ($data['password'] ?? ''),
        ]);

        // Only the non-secret fields, so re-entering the step does not require
        // retyping everything.
        session(['install.database' => collect($data)->except('password')->all()]);

        return redirect()->route('install.application');
    }

    /** Step 3 — what the site is called and where it lives. */
    public function application(): View
    {
        return view('install.application', [
            'timezones' => \DateTimeZone::listIdentifiers(),
            'suggestedUrl' => request()->getSchemeAndHttpHost(),
        ]);
    }

    public function saveApplication(Request $request, EnvWriter $env): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:190'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:8'],
        ]);

        $this->writer($env)->write([
            'APP_NAME' => $data['name'],
            'APP_URL' => rtrim($data['url'], '/'),
            'APP_TIMEZONE' => $data['timezone'],
            'APP_LOCALE' => $data['locale'],
            // The two that must be right on a live server, and the two an
            // owner is least likely to think about.
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            // Signed links are minted from APP_URL by the scheduler; if the
            // site is https the cookie must be secure too.
            'SESSION_SECURE_COOKIE' => str_starts_with($data['url'], 'https://') ? 'true' : 'false',
        ]);

        return redirect()->route('install.run');
    }

    /** Step 4 — the key, the schema, the seed. */
    public function run(): View
    {
        return view('install.run');
    }

    public function execute(EnvWriter $env): RedirectResponse
    {
        try {
            // The key FIRST. Everything encrypted afterwards depends on it,
            // and generating it later would mean re-encrypting whatever the
            // seeders wrote.
            if (blank(config('app.key'))) {
                $key = 'base64:'.base64_encode(random_bytes(32));
                $this->writer($env)->write(['APP_KEY' => $key]);
                config(['app.key' => $key]);
            }

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
        } catch (Throwable $e) {
            return back()->withErrors([
                // Scrubbed: a migration failure routinely quotes the
                // connection string it failed on.
                'install' => Redactor::scrub($e->getMessage()),
            ]);
        }

        return redirect()->route('install.administrator');
    }

    /** Step 5 — the first administrator. Never seeded. */
    public function administrator(): View
    {
        return view('install.administrator');
    }

    public function createAdministrator(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->symbols()],
        ]);

        // Only if there is genuinely nobody. This is the last moment the
        // installer is open, and creating a second administrator on an
        // installed system is the attack it exists to prevent.
        abort_if(User::query()->exists(), 404);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Verified by construction: this person typed the address into a
            // form on the server itself, which is stronger evidence than
            // clicking a link in an email — and there may be no working mail
            // configuration yet to send one with.
            $user->forceFill(['email_verified_at' => now()])->save();

            UserProfile::firstOrCreate(['user_id' => $user->getKey()]);
            $user->assignRole(PermissionRegistry::SUPER_ADMIN);

            return $user;
        });

        // The moment the account exists, this is an installed system.
        $this->installer->lock();

        // Nothing about the password reaches the session.
        session()->forget('install.database');
        session(['install.finished_for' => Str::mask($user->email, '*', 2)]);

        return redirect()->route('install.finished');
    }

    /**
     * Step 6 — done, and the installer is already shut.
     *
     * OUTSIDE the installer middleware, because by the time this renders the
     * installer is locked and that middleware would 404 the success page —
     * which reads like the install failed at the last step.
     *
     * Its own gate instead: the session flag written a moment ago. Only the
     * browser that just completed the install can see this, and on a live
     * server it is a 404 like everything else in here.
     */
    public function finished(): View
    {
        abort_unless(session()->has('install.finished_for'), 404);

        return view('install.finished', [
            'email' => session('install.finished_for'),
            'details' => $this->installer->details(),
        ]);
    }

    private function writer(EnvWriter $env): EnvWriter
    {
        return $env;
    }

    /**
     * What went wrong with the database, in words, without the password.
     *
     * PDO's own message is safe — it names the host, the database and the
     * reason — but it is written for a developer, so the common cases get a
     * sentence an owner can act on.
     */
    private function databaseMessage(PDOException $e): string
    {
        $raw = $e->getMessage();

        return match (true) {
            str_contains($raw, 'Access denied') => 'The database rejected that username and password.',
            str_contains($raw, 'Unknown database') => 'That database does not exist yet. Create it in your hosting control panel first.',
            str_contains($raw, 'refused'), str_contains($raw, 'No such file') => 'Nothing answered at that host and port. On most shared hosting the host is "localhost".',
            default => 'Could not connect: '.Redactor::scrub($raw),
        };
    }
}
