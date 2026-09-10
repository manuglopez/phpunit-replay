<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use FilesystemIterator;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\OriginUrl;
use Manuglopez\Replay\Cache\Remote\RemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `phpunit-replay remote:init` (docs/sharing-the-cache.md "Setup: dedicated git repository",
 * DECISIONS.md D-038 and D-045): sets up the shared remote cache in one invocation instead of
 * in a document.
 *
 * The steps it takes, in order: read the project's `origin` and derive a cache repository next
 * to it (`<repo>-replay-cache`, same owner); create that repository *private* if the host's own
 * CLI can, or print the exact manual steps if it cannot; **prove a round trip through it** by
 * publishing a probe object and reading it back from an independent clone; write
 * `phpunit-replay.php` when the project has none; and print what CI still needs.
 *
 * Three boundaries are deliberate, and each of them is a reason a package like this does or does
 * not get installed:
 *
 * - **No credential ever passes through here.** No token is asked for, read, accepted or stored.
 *   Creating the repository is delegated to `gh`, which already owns that credential, and pushing
 *   is delegated to git, which already owns the other one — the same "auth: whatever git already
 *   has" rule the rest of the remote cache follows (docs/INTERNALS.md "GitRemoteCache").
 * - **Private, always.** There is no `--public`: the cache stores test names, file paths and
 *   failure messages, which is source-adjacent. Passing one is refused rather than honoured.
 * - **Nothing is written to a repository the user did not ask about.** Publishing from CI needs a
 *   write credential in the cache repository plus a secret in the project's own, which is two
 *   writes to real infrastructure; this command prints those steps and makes neither.
 *
 * The exit code answers "does it work", not "was it configured": a probe that could not make the
 * round trip is a failure even when everything got written, because those are different claims
 * and only the second is worth anything. `--dry-run` takes no action at all — it contacts nothing,
 * writes nothing, and is therefore also the shape every test that must not touch a forge uses.
 */
final class RemoteInitCommand extends Command
{
    /** Appended to origin's repository name to propose the cache repository. */
    private const NAME_SUFFIX = '-replay-cache';

    /** `remote_branch` for a dedicated cache repository — the existing `Config` default. */
    private const DEDICATED_BRANCH = 'main';

    /** `remote_branch` for `--same-repo`: an orphan branch of the project's own repository (D-045). */
    private const SAME_REPO_BRANCH = 'phpunit-replay-cache';

    /** Suggested secret name for the CI notes. Only ever printed — never read, never written. */
    private const SECRET_NAME = 'REPLAY_CACHE_DEPLOY_KEY';

    /** Continuation indent for a wrapped `label:` line ({@see self::line()}). */
    private const INDENT = '             ';

    protected function configure(): void
    {
        $this
            ->setName('remote:init')
            ->setDescription('Sets up the shared remote cache: derives a private cache repository from origin, creates it when it can, proves a round trip through it, and writes the config.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, "Cache repository name (default: origin's repository name + " . self::NAME_SUFFIX . ').')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, "Owner or namespace for the cache repository (default: origin's own).")
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Branch of the cache repository that holds the objects (default: ' . self::DEDICATED_BRANCH . ', or ' . self::SAME_REPO_BRANCH . ' with --same-repo).')
            ->addOption('same-repo', null, InputOption::VALUE_NONE, "Keep the cache on an orphan branch of this project's own repository instead of a dedicated one (DECISIONS.md D-045): nothing to create and no access to grant, at the cost of the cache landing in every plain clone.")
            ->addOption('no-create', null, InputOption::VALUE_NONE, 'Assume the cache repository already exists; never try to create it.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print every action and take none: nothing is created, probed, pushed or written.')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Refused. The cache is source-adjacent, so its repository is always private.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('public') === true) {
            $output->writeln('refused: there is no --public. The cache stores test names, file paths and failure messages, which is source-adjacent, so its repository is always private.');

            return Command::INVALID;
        }

        $cwd = getcwd() ?: '.';
        $git = new Git($cwd);
        $root = $git->topLevel() ?? (realpath($cwd) ?: $cwd);
        $git = new Git($root);

        $config = Config::load($root);
        $stateDir = StateDirectory::resolve($config->stateDir, $root);

        $dryRun = $input->getOption('dry-run') === true;
        $sameRepo = $input->getOption('same-repo') === true;

        $origin = self::resolveOrigin($git, $output);

        if ($origin === null) {
            return Command::FAILURE;
        }

        $owner = self::option($input, 'owner') ?? $origin->owner;
        $name = self::cacheName($input, $origin);
        $branch = self::option($input, 'branch') ?? ($sameRepo ? self::SAME_REPO_BRANCH : self::DEDICATED_BRANCH);

        // --same-repo publishes cache commits to $branch of the project's OWN repository, so a
        // branch that holds code is not a misconfiguration to warn about afterwards: the probe
        // below would already have committed to it. The two branches that certainly hold code —
        // the one checked out and the project's default — are refused outright.
        if ($sameRepo) {
            $refusal = self::refuseCodeBranch($git, $config, $branch);

            if ($refusal !== null) {
                $output->writeln($refusal);

                return Command::INVALID;
            }
        }

        // --same-repo points `remote` at origin itself, canonicalised rather than copied
        // verbatim: an origin with no `.git` suffix is a URL RemoteCacheFactory would route to
        // the HTTP backend ({@see OriginUrl::sibling()}).
        $url = $sameRepo ? $origin->canonical() : $origin->sibling($owner, $name);

        $output->writeln(self::line('origin:', $origin->url));
        $output->writeln(self::INDENT . sprintf(
            'host %s · owner %s · repository %s',
            $origin->host === '' ? '(local path)' : $origin->host,
            $origin->owner === '' ? '(none)' : $origin->owner,
            $origin->name,
        ));
        $output->writeln('');
        $output->writeln(self::line('cache:', Config::maskRemote($url)));
        $output->writeln(self::line('branch:', $branch));

        if (! $sameRepo) {
            $output->writeln(self::line('private:', 'always — the cache stores test names, file paths and failure messages'));
        }

        $output->writeln('');
        self::reportCreate($input, $output, $owner, $name, $url, $root, $sameRepo, $dryRun);
        $output->writeln('');

        $probeOk = $dryRun
            ? self::describeProbe($output)
            : self::probe($config->with([
                'remote' => $url,
                'remoteBranch' => $branch,
                // A mirror left behind by a crashed earlier probe must never be read as
                // fresh; every probe starts from an actual fetch.
                'remoteRefreshSeconds' => 0,
            ]), $stateDir, $output);

        $output->writeln('');
        self::reportConfig($root, self::configKeys($url, $branch), $dryRun, $output);
        $output->writeln('');
        self::reportCi($output, self::slug($origin->owner, $origin->name), self::slug($owner, $name), $sameRepo);

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('dry run: nothing was contacted, created, pushed or written.');
        }

        return $probeOk ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * `origin` is not a convenience here: the remote cache addresses a project by the identity
     * derived from that URL ({@see \Manuglopez\Replay\Cache\ProjectKey::shared()}), which is what
     * lets two developers who cloned the same project into differently named directories find
     * the same shared baseline. Without an origin there is nothing to key the cache by and
     * nothing to derive a cache repository from.
     */
    private static function resolveOrigin(Git $git, OutputInterface $output): ?OriginUrl
    {
        $url = $git->originUrl();

        if ($url === null) {
            $output->writeln('no `origin` remote: this project has none, or its remote goes by another name.');
            $output->writeln('`origin` is required. The shared cache keys every project by the identity derived from that URL (`ProjectKey::shared()`), so two checkouts of the same project resolve to the same cache; the cache repository this command proposes is derived from it too.');
            $output->writeln('Add one (`git remote add origin <url>`) and run this again.');

            return null;
        }

        $origin = OriginUrl::parse($url);

        if ($origin === null) {
            $output->writeln('could not read owner and repository out of `origin`: ' . $url);
            $output->writeln('Supported forms are the ones the git backend accepts: `git@host:owner/name.git`, `https://host/owner/name.git`, `ssh://[user@]host[:port]/owner/name.git`, and a local `file:///path/name.git`.');
            $output->writeln('Configure the cache by hand instead — docs/sharing-the-cache.md, "Setup: dedicated git repository".');

            return null;
        }

        return $origin;
    }

    /**
     * One line explaining the refusal, or null when the branch is safe to hold the cache. Only
     * the two branches this can be sure about are refused; any other explicit choice is the
     * caller's, since nothing here can know what a given branch holds without fetching it.
     */
    private static function refuseCodeBranch(Git $git, Config $config, string $branch): ?string
    {
        $current = $git->currentBranch();
        $default = $config->defaultBranch ?? $git->defaultBranch();

        if ($branch !== $current && $branch !== $default) {
            return null;
        }

        return 'refused: --same-repo would publish the cache to `' . $branch . '` of this repository, which is '
            . ($branch === $current ? 'the branch you have checked out' : "this project's default branch")
            . '. The cache needs a branch that holds nothing but the cache: leave --branch out to get `'
            . self::SAME_REPO_BRANCH . '`, which the first push creates as an orphan branch, sharing no history with your code.';
    }

    /**
     * Deliberately origin's repository name and never `basename($root)`: a checkout directory
     * gets renamed, shortened and nested at will, and two people working on the same project
     * must land on the same cache repository — the same reason `ProjectKey::shared()` keys the
     * remote off origin identity instead of off the directory.
     */
    private static function cacheName(InputInterface $input, OriginUrl $origin): string
    {
        $explicit = self::option($input, 'name');

        if ($explicit === null) {
            return $origin->name . self::NAME_SUFFIX;
        }

        // A `.git` the user typed would otherwise survive into `<name>.git.git`.
        $stripped = preg_replace('/\.git$/i', '', $explicit) ?? '';

        return $stripped === '' ? $explicit : $stripped;
    }

    /**
     * Existence first, creation second — and never the other way round. A cache repository that
     * is already there is the normal case on every machine after the first, so it is not an
     * error and not a reason to call the host's CLI at all: `git ls-remote` answers the question
     * read-only (exit 0 even for an empty repository, which is exactly what a fresh cache repo
     * is), the same way `GitRemoteCache` itself decides whether a remote is reachable.
     */
    private static function reportCreate(
        InputInterface $input,
        OutputInterface $output,
        string $owner,
        string $name,
        string $url,
        string $root,
        bool $sameRepo,
        bool $dryRun,
    ): void {
        if ($sameRepo) {
            if (self::option($input, 'name') !== null || self::option($input, 'owner') !== null) {
                $output->writeln(self::line('note:', '--same-repo uses this project\'s own repository, so --name/--owner are ignored'));
            }

            if (self::option($input, 'branch') !== null) {
                $output->writeln(self::line('careful:', 'that branch must hold nothing but the cache. `' . self::SAME_REPO_BRANCH . '`, the'));
                $output->writeln(self::INDENT . 'default, is created as an orphan branch by the first push and shares no history');
                $output->writeln(self::INDENT . 'with your code; an existing branch with code on it would receive cache commits.');
            }

            $output->writeln(self::line('create:', 'nothing to create and no access to grant: the cache is a branch of this repository'));
            $output->writeln(self::line('cost:', 'a plain `git clone` of this project fetches every branch, so the cache lands in'));
            $output->writeln(self::INDENT . 'every checkout — including those of people who never use this package — and stays');
            $output->writeln(self::INDENT . 'bounded only while `prune --remote --squash` runs on a schedule. A dedicated');
            $output->writeln(self::INDENT . 'repository (the default, without --same-repo) carries no such cost.');

            return;
        }

        if ($dryRun) {
            $output->writeln(self::line('create:', 'would check `git ls-remote ' . Config::maskRemote($url) . '` and, if it is not there,'));
            $output->writeln(self::INDENT . 'create it with `gh repo create ' . self::slug($owner, $name) . ' --private`');

            return;
        }

        if (self::reachable($url, $root)) {
            $output->writeln(self::line('create:', 'already there — `git ls-remote` reached it'));

            return;
        }

        if ($input->getOption('no-create') === true) {
            $output->writeln(self::line('create:', '--no-create given: assuming it exists (`git ls-remote` did not reach it)'));

            return;
        }

        self::create($owner, $name, $output);
    }

    /**
     * `git ls-remote` against the cache URL: the same read-only reachability question
     * `GitRemoteCache` asks internally, with the same short budget. Exit 0 means the repository
     * is there even when it holds no refs at all.
     */
    private static function reachable(string $url, string $root): bool
    {
        return (new Git($root, 10.0))->succeeds(['ls-remote', $url]);
    }

    /**
     * Creation goes through the host's own CLI, which already holds the credential — this
     * command never asks for a token, and a test-tooling package that asks for one is a package
     * nobody installs. A failure is not fatal: no permission in the target namespace is the
     * common case, and a user who then creates the repository by hand still wants the probe, the
     * config and the CI notes, so the exact manual steps are printed and the run continues.
     */
    private static function create(string $owner, string $name, OutputInterface $output): void
    {
        $slug = self::slug($owner, $name);
        $gh = (new ExecutableFinder())->find('gh');

        if ($gh === null) {
            $output->writeln(self::line('create:', 'not there, and the `gh` CLI is not on PATH — nothing was created'));
            self::manualSteps($owner, $name, $output);

            return;
        }

        if (self::runProcess([$gh, 'auth', 'status'])['exitCode'] !== 0) {
            $output->writeln(self::line('create:', '`gh` is on PATH but not authenticated (`gh auth status` failed) — nothing was created'));
            self::manualSteps($owner, $name, $output);

            return;
        }

        $result = self::runProcess([$gh, 'repo', 'create', $slug, '--private']);

        if ($result['exitCode'] === 0) {
            $output->writeln(self::line('create:', 'created ' . $slug . ', private, with `gh`'));

            return;
        }

        $output->writeln(self::line('create:', '`gh repo create` failed: ' . self::firstLine($result['output'])));
        $output->writeln(self::INDENT . 'no write permission in that namespace is the usual reason.');
        self::manualSteps($owner, $name, $output);
    }

    private static function manualSteps(string $owner, string $name, OutputInterface $output): void
    {
        $output->writeln(self::INDENT . 'create it by hand, private, with no initial commit:');
        $output->writeln(self::INDENT . '  gh repo create ' . self::slug($owner, $name) . ' --private');
        $output->writeln(self::INDENT . '  or in the web UI: New repository → owner ' . ($owner === '' ? '(your account)' : $owner) . ', name ' . $name . ', Private, no README');
        $output->writeln(self::INDENT . 'the probe below is what tells you whether it worked; re-run this command after creating it.');
    }

    /**
     * The claim worth printing, and the reason this command exists as something other than a
     * config generator: publish a probe object through the real backend
     * ({@see RemoteCacheFactory}, never a hand-rolled git call), `end()` it — which for the git
     * backend is where the push actually happens — read it back through a **second, independent**
     * instance, compare, then delete it.
     *
     * Both instances get their own state directory under `<stateDir>/remote-init/`, removed on
     * the way out. A git mirror is bound to the URL it was cloned from, so the verifier has to
     * start from nothing to prove it reached the remote rather than reading what the writer
     * staged locally; and neither may disturb the project's real mirror at `<stateDir>/remote/git`,
     * which may still be a mirror of a previously configured remote.
     *
     * The probe is deleted through the *verifier* rather than through the writer, and that is
     * not a matter of taste: `GitRemoteCache::put()` remembers every key it wrote so that
     * `adoptFetchedHistory()` can re-apply them after a `git reset --hard`, and the writer of a
     * branch that did not exist upstream at `begin()` time takes exactly that path in its next
     * `end()` — so a delete issued on the writer is discarded by the reset and, on top of that,
     * re-written from the buffer. The verifier holds a plain clone with no write buffer, so its
     * delete commits and pushes as a delete.
     */
    private static function probe(Config $config, string $stateDir, OutputInterface $output): bool
    {
        $scratch = rtrim(Paths::normalizeSeparators($stateDir), '/') . '/remote-init';
        $key = ObjectStore::objectKey(ObjectStore::currentShard(), 'replay-probe-' . bin2hex(random_bytes(8)));
        $body = Json::encode(['probe' => 'phpunit-replay remote:init', 'at' => time()]) ?? '{"probe":"phpunit-replay remote:init"}';

        $writer = RemoteCacheFactory::fromConfig($config, $scratch . '/write');
        $writer->begin();
        $accepted = $writer->put($key, $body);
        $writer->end();
        $writeError = $writer->lastError();

        if (! $accepted || $writeError !== null) {
            $output->writeln(self::line('probe:', 'could not publish ' . $key . ' — the round trip does NOT work'));
            $output->writeln(self::INDENT . ($writeError ?? 'the backend refused the write'));
            self::removeDirectory($scratch);

            return false;
        }

        $verifier = RemoteCacheFactory::fromConfig($config, $scratch . '/verify');
        $verifier->begin();
        $readBack = $verifier->get($key);
        $readError = $verifier->lastError();

        $cleanupError = self::removeProbe($verifier, $key);
        self::removeDirectory($scratch);

        if ($readBack !== $body) {
            $output->writeln(self::line('probe:', 'published ' . $key));
            $output->writeln(self::INDENT . 'a separate clone read back ' . ($readBack === null ? 'nothing' : 'other content') . ' — the round trip does NOT work');
            $output->writeln(self::INDENT . ($readError ?? 'the object is not in the remote; check the branch and the read access'));

            return false;
        }

        $output->writeln(self::line('probe:', 'published ' . $key));
        $output->writeln(self::INDENT . 'read it back from a separate clone — the round trip works');
        $output->writeln(self::INDENT . ($cleanupError === null ? 'probe deleted' : 'the probe could not be deleted: ' . $cleanupError));

        return true;
    }

    private static function describeProbe(OutputInterface $output): bool
    {
        $output->writeln(self::line('probe:', 'would publish ' . ObjectStore::objectKey(ObjectStore::currentShard(), 'replay-probe-<nonce>')));
        $output->writeln(self::INDENT . 'read it back through a separate clone, compare it, and delete it');

        return true;
    }

    /** The backend's own error when the probe is still in the remote, null when it is gone. */
    private static function removeProbe(RemoteCache $cache, string $key): ?string
    {
        if (! $cache->delete($key)) {
            return $cache->lastError() ?? 'the backend refused the delete';
        }

        $cache->end();

        return $cache->lastError();
    }

    /**
     * An existing `phpunit-replay.php` is never edited, only read for its existence. It is a
     * committed, hand-written file that may already carry `default_branch`, `baseline_branches`,
     * `watch` patterns or a `mode` override, and rewriting it from three derived keys would
     * silently drop all of that — so the lines to add are printed instead and the user decides
     * where they go.
     *
     * @param array<string, string> $keys
     */
    private static function reportConfig(string $root, array $keys, bool $dryRun, OutputInterface $output): void
    {
        $file = rtrim(Paths::normalizeSeparators($root), '/') . '/phpunit-replay.php';

        if (is_file($file)) {
            $output->writeln(self::line('config:', $file . ' already exists and was left untouched.'));
            $output->writeln(self::INDENT . 'add these lines to the array it returns:');
            self::writeKeyLines($keys, $output);

            return;
        }

        if ($dryRun) {
            $output->writeln(self::line('config:', 'would write ' . $file . ' with:'));
            self::writeKeyLines($keys, $output);

            return;
        }

        if (! AtomicFile::write($file, self::configFileBody($keys))) {
            $output->writeln(self::line('config:', 'could not write ' . $file . '.'));
            $output->writeln(self::INDENT . 'create it by hand, returning an array with:');
            self::writeKeyLines($keys, $output);

            return;
        }

        $output->writeln(self::line('config:', 'wrote ' . $file));
        self::writeKeyLines($keys, $output);
    }

    /**
     * Publishing from CI needs a write credential in the cache repository and a matching secret
     * in the project's own repository: two writes to real infrastructure. A dev dependency does
     * not generate keys, upload them or set a secret in a production repository unasked, so this
     * prints the steps and makes none of them.
     */
    private static function reportCi(OutputInterface $output, string $projectSlug, string $cacheSlug, bool $sameRepo): void
    {
        if ($sameRepo) {
            $output->writeln(self::line('ci:', 'the cache is a branch of this repository, so the job\'s own credential can already'));
            $output->writeln(self::INDENT . 'write it: no deploy key and no secret. The publishing job needs write permission on');
            $output->writeln(self::INDENT . 'the repository contents (GitHub Actions: `permissions: contents: write`) and a');
            $output->writeln(self::INDENT . 'checkout that keeps its credentials, then:');
            self::writeWorkflowSnippet($output, self::INDENT . '  ', false);
            self::writeBaselineNote($output);

            return;
        }

        $output->writeln(self::line('ci:', 'publishing from CI needs a write credential in ' . $cacheSlug . ' and a matching secret in'));
        $output->writeln(self::INDENT . $projectSlug . ' — two writes to real infrastructure. This command makes neither: it');
        $output->writeln(self::INDENT . 'generates no keys, uploads nothing and sets no secret. A later flag may automate it.');
        $output->writeln(self::INDENT . '  1. ssh-keygen -t ed25519 -C phpunit-replay -f replay-cache-key');
        $output->writeln(self::INDENT . '     (a GitHub deploy key is SSH-only, so it has to be an SSH keypair)');
        $output->writeln(self::INDENT . '  2. add replay-cache-key.pub to ' . $cacheSlug . ' as a deploy key, with write access');
        $output->writeln(self::INDENT . '  3. add the private half as a secret named ' . self::SECRET_NAME . ' in ' . $projectSlug . ',');
        $output->writeln(self::INDENT . '     then delete both local key files');
        $output->writeln(self::INDENT . '  4. in the publishing job:');
        self::writeWorkflowSnippet($output, self::INDENT . '       ', true);
        self::writeBaselineNote($output);
    }

    private static function writeWorkflowSnippet(OutputInterface $output, string $indent, bool $withDeployKey): void
    {
        if ($withDeployKey) {
            $output->writeln($indent . '- uses: webfactory/ssh-agent@v0.9.0');
            $output->writeln($indent . '  with:');
            $output->writeln($indent . '    ssh-private-key: ${{ secrets.' . self::SECRET_NAME . ' }}');
        }

        $output->writeln($indent . '- run: vendor/bin/phpunit-replay run');
        $output->writeln($indent . '  env:');
        $output->writeln($indent . '    PHPUNIT_REPLAY_REMOTE_PUSH: objects');
    }

    private static function writeBaselineNote(OutputInterface $output): void
    {
        $output->writeln(self::INDENT . 'the baseline job after a merge runs the same thing plus `phpunit-replay push --graph`;');
        $output->writeln(self::INDENT . 'developers need nothing beyond read access. Full guide: docs/sharing-the-cache.md.');
    }

    /**
     * The three keys this command resolves, in the order they are written and printed.
     * `remote_push` is `off` on purpose: the recommended shape is CI-writes-everyone-reads, and
     * a config file that guesses where it is running fails open (docs/configuration.md, "Remote
     * cache").
     *
     * @return array<string, string>
     */
    private static function configKeys(string $url, string $branch): array
    {
        return [
            'remote' => $url,
            'remote_branch' => $branch,
            'remote_push' => 'off',
        ];
    }

    /** @param array<string, string> $keys */
    private static function writeKeyLines(array $keys, OutputInterface $output): void
    {
        foreach ($keys as $key => $value) {
            $output->writeln(self::INDENT . '  ' . self::keyLine($key, $value));
        }
    }

    /** @param array<string, string> $keys */
    private static function configFileBody(array $keys): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '/**',
            ' * phpunit-replay configuration, written by `phpunit-replay remote:init`.',
            ' *',
            ' * Every key is optional and this file is meant to be committed. The complete',
            ' * reference is docs/configuration.md in the manuglopez/phpunit-replay package.',
            ' */',
            'return [',
        ];

        foreach ($keys as $key => $value) {
            foreach (self::keyComment($key) as $comment) {
                $lines[] = '    // ' . $comment;
            }

            $lines[] = '    ' . self::keyLine($key, $value);
            $lines[] = '';
        }

        array_pop($lines);
        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }

    private static function keyLine(string $key, string $value): string
    {
        return sprintf("'%s' => %s,", $key, var_export($value, true));
    }

    /**
     * Why each key is in the file, not what it does — a reader who wants the "what" has
     * docs/configuration.md, and a reader who inherits this file wants to know why it says this.
     *
     * @return list<string>
     */
    private static function keyComment(string $key): array
    {
        return match ($key) {
            'remote' => [
                'Where the shared result cache lives. A private git repository: a shallow mirror',
                'of it is kept under the state directory, and everything it holds is derived from',
                "this project's own test runs. Unreachable or misconfigured degrades to a warning",
                'and a local-only pass; it can never break a test run.',
            ],
            'remote_branch' => [
                'Which branch of that repository holds objects/** and graph/**.',
            ],
            'remote_push' => [
                'What THIS machine may publish. `off` keeps developer machines read-only, and each',
                'CI job declares its own role with PHPUNIT_REPLAY_REMOTE_PUSH=objects (the',
                'environment always wins over this file) — an explicit variable per job fails',
                'closed, where a `getenv("CI")` guess in this file would fail open.',
            ],
            default => [],
        };
    }

    private static function slug(string $owner, string $name): string
    {
        return $owner === '' ? $name : rtrim($owner, '/') . '/' . $name;
    }

    private static function line(string $label, string $value): string
    {
        return sprintf('%-12s %s', $label, $value);
    }

    /** A trimmed, non-empty option value, or null — `null` and `''` both mean "not given". */
    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function firstLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return $lines === false || $lines === [] ? 'no output' : $lines[0];
    }

    /**
     * Runs a subprocess the way `Change\Git::result()` does, including its never-throw contract
     * (docs/INTERNALS.md conventions): a missing binary or a subprocess error is exit 127, not an
     * exception. stderr is folded into the output because `gh` reports its failures there and
     * the reason is what the user needs to read.
     *
     * @param list<string> $command
     * @return array{exitCode: int, output: string}
     */
    private static function runProcess(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(60.0);

        try {
            $process->run();
        } catch (ProcessFailedException|RuntimeException) {
            return ['exitCode' => 127, 'output' => ''];
        }

        return [
            'exitCode' => $process->getExitCode() ?? 127,
            'output' => trim($process->getOutput() . "\n" . $process->getErrorOutput()),
        ];
    }

    /** Same shape as `PruneCommand`'s: the probe's scratch mirrors are ours to remove, quietly. */
    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($dir);
    }
}
