<?php

namespace Tests\Feature\Deployment;

use App\Console\Commands\BackupManifestCommand;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A backup nobody has restored is a hope, not a backup.
 *
 * SO THIS ONE IS ACTUALLY PERFORMED. The test writes an encrypted provider
 * credential into a real table in a real MySQL database, dumps it with the
 * same `mysqldump` command the runbook gives, drops the table, restores it
 * with `mysql <`, and reads the credential back. The documented procedure and
 * the tested procedure are the same procedure.
 *
 * THE FAILURE IT EXISTS FOR IS `APP_KEY`. Every provider key, gateway
 * credential and webhook secret in that database is encrypted under it, and a
 * database restored beside a freshly generated key contains rows that can
 * never be decrypted by anybody, ever. There is no recovery beyond re-entering
 * every credential by hand — so the second test here deliberately restores
 * under a different key and proves the loss is total, because a warning in a
 * document is easier to skip than a test is to delete.
 *
 * @see docs/19-backup-and-restore.md
 */
class BackupRestoreTest extends TestCase
{
    /**
     * A scratch TABLE rather than a scratch DATABASE, because the deployments
     * this product targets — shared hosting — give the application's user
     * rights over one database and no right to create another. A test that
     * needs more privilege than production has is a test that will be deleted
     * the first time it fails on a real server.
     */
    private const TABLE = 'backup_restore_probe';

    private string $dumpFile;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['mysqldump', 'mysql'] as $binary) {
            if ($this->which($binary) === null) {
                $this->markTestSkipped($binary.' is not on PATH, so a real restore cannot be performed here.');
            }
        }

        $this->dumpFile = sys_get_temp_dir().'/aziv-backup-'.Str::random(8).'.sql';

        DB::statement('DROP TABLE IF EXISTS '.self::TABLE);
        DB::statement('CREATE TABLE '.self::TABLE.' (id INT PRIMARY KEY, credential TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::TABLE);

        if (is_file($this->dumpFile)) {
            unlink($this->dumpFile);
        }

        parent::tearDown();
    }

    // -- the restore, actually performed --------------------------------------

    public function test_a_dumped_database_restores_and_the_credential_is_readable_again(): void
    {
        $secret = 'sk-live-'.Str::random(32);

        DB::table(self::TABLE)->insert(['id' => 1, 'credential' => Crypt::encryptString($secret)]);

        $this->dump();

        // Everything is gone, the way it is after the incident that made you
        // reach for the backup.
        DB::statement('DROP TABLE '.self::TABLE);
        $this->assertFalse($this->tableExists(), 'The table survived the drop, so nothing was restored.');

        $this->restore();

        $this->assertTrue($this->tableExists(), 'The restore did not recreate the table.');

        $ciphertext = (string) DB::table(self::TABLE)->where('id', 1)->value('credential');

        $this->assertNotSame($secret, $ciphertext,
            'The credential was stored in plain text — the dump file itself is now a credential.');

        $this->assertSame($secret, Crypt::decryptString($ciphertext),
            'The credential did not survive the round trip.');
    }

    public function test_a_restore_beside_a_new_application_key_loses_every_credential(): void
    {
        $secret = 'sk-live-'.Str::random(32);

        DB::table(self::TABLE)->insert(['id' => 1, 'credential' => Crypt::encryptString($secret)]);

        $this->dump();
        DB::statement('DROP TABLE '.self::TABLE);
        $this->restore();

        // Exactly what `php artisan key:generate` on a restored server does.
        $freshKey = Encrypter::generateKey(config('app.cipher'));
        $stranger = new Encrypter($freshKey, (string) config('app.cipher'));

        $ciphertext = (string) DB::table(self::TABLE)->where('id', 1)->value('credential');

        $this->expectException(DecryptException::class);

        $stranger->decryptString($ciphertext);
    }

    public function test_the_original_key_still_reads_what_a_new_key_could_not(): void
    {
        // The same rows, proving the loss above is the KEY and not the dump.
        $secret = 'sk-live-'.Str::random(32);

        DB::table(self::TABLE)->insert(['id' => 1, 'credential' => Crypt::encryptString($secret)]);

        $this->dump();
        DB::statement('DROP TABLE '.self::TABLE);
        $this->restore();

        $this->assertSame($secret, Crypt::decryptString(
            (string) DB::table(self::TABLE)->where('id', 1)->value('credential')
        ));
    }

    // -- the list cannot drift ------------------------------------------------

    public function test_the_manifest_names_every_place_the_application_writes(): void
    {
        $manifest = app(BackupManifestCommand::class)->manifest();

        $named = array_column($manifest['paths'], 'disk');

        foreach (array_keys((array) config('filesystems.disks')) as $disk) {
            $this->assertContains($disk, $named,
                'The "'.$disk.'" disk is configured but is not in the backup manifest, '.
                'so whatever is on it would not be restored.');
        }

        $this->assertSame([config('database.default')], [$manifest['database']['connection']]);
        $this->assertContains('APP_KEY', $manifest['secrets']);
    }

    public function test_the_manifest_prints_no_credential(): void
    {
        // An operator forwards this to their host to ask about backups.
        $manifest = json_encode(app(BackupManifestCommand::class)->manifest());
        $connection = (string) config('database.default');

        foreach (['password', 'AWS_SECRET_ACCESS_KEY'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $manifest);
        }

        $password = (string) config("database.connections.{$connection}.password");

        if ($password !== '') {
            $this->assertStringNotContainsString($password, (string) $manifest);
        }
    }

    // -- the runbook says the things that matter ------------------------------

    public function test_the_runbook_covers_the_three_things_and_the_trap(): void
    {
        $doc = file_get_contents(base_path('docs/19-backup-and-restore.md'));

        foreach (['APP_KEY', 'mysqldump', 'storage/app/private', 'aziv:diagnose'] as $needle) {
            $this->assertStringContainsString($needle, $doc,
                'The backup runbook never mentions '.$needle.'.');
        }

        // The restore is only finished when somebody has checked it worked.
        $this->assertStringContainsString('Verifying the restore', $doc);

        // And the order that matters: the key goes in before the data is read.
        $this->assertStringContainsString('THE KEY FIRST', $doc);
    }

    // -- plumbing -------------------------------------------------------------

    /** The dump command from the runbook, with the password kept out of `ps`. */
    private function dump(): void
    {
        $this->mysqlCommand('mysqldump', '--single-transaction --quick '
            .escapeshellarg($this->database()).' '.self::TABLE.' > '.escapeshellarg($this->dumpFile));

        $this->assertGreaterThan(0, (int) filesize($this->dumpFile), 'The dump file is empty.');
    }

    private function restore(): void
    {
        $this->mysqlCommand('mysql', escapeshellarg($this->database()).' < '.escapeshellarg($this->dumpFile));

        // The restore ran on its own connection; this one may still be holding
        // a cached view of a table that no longer exists.
        DB::purge();
    }

    /**
     * Run a MySQL client command with credentials in a defaults file.
     *
     * Never `-pSECRET` on the command line: it is visible to every other user
     * on the machine in `ps`, which is exactly what the runbook tells the
     * owner not to do.
     */
    private function mysqlCommand(string $binary, string $arguments): void
    {
        $connection = (string) config('database.default');
        $defaults = tempnam(sys_get_temp_dir(), 'azivcnf');

        file_put_contents($defaults, implode("\n", [
            '[client]',
            'host='.config("database.connections.{$connection}.host"),
            'port='.config("database.connections.{$connection}.port"),
            'user='.config("database.connections.{$connection}.username"),
            'password='.config("database.connections.{$connection}.password"),
            '',
        ]));
        chmod($defaults, 0600);

        $output = [];
        $status = 0;

        exec($binary.' --defaults-extra-file='.escapeshellarg($defaults).' '.$arguments.' 2>&1', $output, $status);

        unlink($defaults);

        $this->assertSame(0, $status, $binary.' failed: '.implode("\n", $output));
    }

    private function database(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    private function tableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable(self::TABLE);
    }

    private function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
