<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Unit\Remote;

use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use Yepr\Component\GitInSync\Administrator\Git\LocalRepository;
use Yepr\Component\GitInSync\Administrator\Git\RefConflict;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Git\Signature;
use Yepr\Component\GitInSync\Administrator\Job\Budget;
use Yepr\Component\GitInSync\Administrator\Remote\GitHubClient;
use Yepr\Component\GitInSync\Administrator\Remote\GitHubObjects;
use Yepr\Component\GitInSync\Administrator\Remote\GitHubRepository;
use Yepr\Component\GitInSync\Administrator\Remote\Transfer;
use Yepr\GitInSync\Tests\Support\FakeGitHub;
use Yepr\GitInSync\Tests\Support\TestDatabase;

/**
 * Pushing and fetching through GitHub's REST API, against a stand-in for GitHub that
 * stores everything with the git program.
 *
 * The point of the whole approach: a commit made on the site and created again through
 * the API gets the same id on GitHub, so both sides share one history.
 */
final class TransferTest extends TestCase
{
    private const ARTICLE = 'articles/0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b-hello/introtext.html';

    private string $dir;

    private FakeGitHub $github;

    private DatabaseInterface $db;

    protected function setUp(): void
    {
        exec('git --version 2>&1', $output, $status);

        if ($status !== 0) {
            self::markTestSkipped('git is not installed.');
        }

        if (!class_exists(\Joomla\Database\Sqlite\SqliteDriver::class) || !\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Needs Joomla in /joomla and pdo_sqlite.');
        }

        $this->dir    = sys_get_temp_dir() . '/gitinsync-transfer-' . bin2hex(random_bytes(4));
        $this->github = new FakeGitHub($this->dir . '/github.git');
        $this->db     = TestDatabase::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->remove($this->dir);
        }
    }

    public function testInitialiseGivesAnEmptyRepositoryAFirstCommit(): void
    {
        $client = $this->client();

        self::assertTrue($client->repository()['empty']);

        $client->initialise('main');

        self::assertFalse($client->repository()['empty']);
        self::assertSame($this->github->ref('main'), $client->getRef('main'));
    }

    public function testPushGivesGitHubTheSameCommitIds(): void
    {
        $client = $this->client();
        $client->initialise('main');

        [$local, $repository, $transfer] = $this->localSite();

        // The site starts from what is on GitHub.
        $fetch = $transfer->fetch('main', Budget::unlimited());
        self::assertTrue($fetch['done']);
        $repository->refs->set('refs/heads/main', (string) $fetch['head']);

        $first  = $this->commit($repository, 'main', [self::ARTICLE => "<p>Hello</p>\n"], 'Add article');
        $second = $this->commit($repository, 'main', [
            self::ARTICLE => "<p>Hello, world</p>\n",
            'categories/1a2b3c4d-1c3d-4e5f-8a9b-0c1d2e3f4a5b-news/category.json' => "{\n    \"title\": \"News\"\n}\n",
        ], 'Update article');

        $result = $transfer->push('main', Budget::unlimited());

        self::assertTrue($result['done'], $result['message']);
        self::assertSame(2, $result['commits']);
        self::assertSame($second, $this->github->ref('main'), 'GitHub\'s branch is the site\'s commit, by id.');
        self::assertSame($first, $this->github->git(['rev-parse', 'main~1']));
        self::assertSame("<p>Hello, world</p>", $this->github->git(['show', 'main:' . self::ARTICLE]));
        $this->github->git(['fsck', '--strict']);

        self::assertSame('GitHub is up to date.', $transfer->push('main', Budget::unlimited())['message']);
        self::assertSame($second, $local->get(Transfer::remoteRef('main')));
    }

    public function testFetchBringsInWhatWasCommittedOnGitHub(): void
    {
        $this->client()->initialise('main');
        $onGitHub = $this->github->commitFile('main', self::ARTICLE, "<p>From GitHub</p>\n", 'Edit on GitHub');

        [$local, $repository, $transfer] = $this->localSite();

        $result = $transfer->fetch('main', Budget::unlimited());

        self::assertTrue($result['done']);
        self::assertSame(2, $result['commits']);
        self::assertSame($onGitHub, $result['head']);
        self::assertSame($onGitHub, $local->get(Transfer::remoteRef('main')));
        self::assertSame($onGitHub, $local->read($onGitHub)->id(), 'Rebuilt with its time zone and message as git stored them.');
        self::assertSame(
            "<p>From GitHub</p>\n",
            $repository->readBlob($repository->files($onGitHub)[self::ARTICLE])
        );
    }

    public function testPushIsRefusedWhenGitHubHasCommitsTheSiteHasNot(): void
    {
        $this->client()->initialise('main');
        [, $repository, $transfer] = $this->localSite();

        $transfer->fetch('main', Budget::unlimited());
        $repository->refs->set('refs/heads/main', (string) $this->github->ref('main'));
        $this->commit($repository, 'main', ['a.txt' => "site\n"], 'On the site');
        $this->github->commitFile('main', 'b.txt', "github\n", 'On GitHub');

        $this->expectExceptionMessage('Pull first');
        $transfer->push('main', Budget::unlimited());
    }

    public function testPushResumesAfterTheBudgetRanOut(): void
    {
        $this->client()->initialise('main');
        [, $repository, $transfer] = $this->localSite();

        $transfer->fetch('main', Budget::unlimited());
        $repository->refs->set('refs/heads/main', (string) $this->github->ref('main'));

        for ($i = 1; $i <= 3; $i++) {
            $last = $this->commit($repository, 'main', ['n.txt' => "{$i}\n"], "Commit {$i}");
        }

        $runs = 0;

        do {
            $result = $transfer->push('main', new Budget(0));
            $runs++;
        } while (!$result['done'] && $runs < 20);

        self::assertTrue($result['done']);
        self::assertGreaterThan(1, $runs, 'An expired budget stops after a portion.');
        self::assertSame($last, $this->github->ref('main'));
    }

    public function testFetchResumesAfterTheBudgetRanOut(): void
    {
        $this->client()->initialise('main');

        for ($i = 1; $i <= 4; $i++) {
            $last = $this->github->commitFile('main', "dir{$i}/n.txt", "{$i}\n", "Commit {$i}");
        }

        [$local, , $transfer] = $this->localSite();
        $runs = 0;

        do {
            $result = $transfer->fetch('main', new Budget(0));
            $runs++;
        } while (!$result['done'] && $runs < 50);

        self::assertTrue($result['done']);
        self::assertGreaterThan(1, $runs, 'An expired budget stops after a portion.');
        self::assertSame($last, $local->get(Transfer::remoteRef('main')));
        self::assertSame(0, (int) $this->db->setQuery('SELECT COUNT(*) FROM jos_gitinsync_objects')->loadResult(), 'The commit cache is emptied.');
    }

    public function testGitHubOnlyRepositoryWritesStraightToGitHub(): void
    {
        $this->client()->initialise('main');
        $objects    = new GitHubObjects($this->client());
        $remote     = new GitHubRepository($objects, $this->db);
        $repository = new Repository($remote, $remote);
        $head       = $repository->head('main');

        $commit = $this->commit($repository, 'main', ['articles/x.txt' => "direct\n"], 'Straight to GitHub', $head);

        self::assertSame($commit, $this->github->ref('main'));
        self::assertSame("direct", $this->github->git(['show', 'main:articles/x.txt']));

        // Read back through a fresh instance: from the object cache, or from GitHub.
        $again = new GitHubRepository(new GitHubObjects($this->client()), TestDatabase::create());
        self::assertSame("direct\n", (new Repository($again, $again))->readBlob((new Repository($again, $again))->files($commit)['articles/x.txt']));

        // Moving the branch from a stale commit is refused.
        $this->expectException(RefConflict::class);
        $remote->set('refs/heads/main', $head ?? '', $head);
    }

    private function client(): GitHubClient
    {
        return new GitHubClient($this->github, 'owner', 'content', 'test-token');
    }

    /**
     * @return  array{0: LocalRepository, 1: Repository, 2: Transfer}
     */
    private function localSite(): array
    {
        $local = new LocalRepository($this->dir . '/site');
        $local->init('main');
        $repository = new Repository($local, $local);

        return [$local, $repository, new Transfer($local, $repository, new GitHubObjects($this->client()), $this->db)];
    }

    /**
     * @param   array<string, string>  $changes
     */
    private function commit(Repository $repository, string $branch, array $changes, string $message, ?string $expected = null): string
    {
        $head  = $expected ?? $repository->head($branch);
        $files = $repository->files($head);

        foreach ($changes as $path => $content) {
            $files[$path] = $repository->writeBlob($content);
        }

        static $time = 1_800_000_000;
        $time += 60;
        $who    = new Signature('Editor', 'editor@example.invalid', $time);
        $commit = $repository->writeCommit($repository->writeTree($files), $head === null ? [] : [$head], $who, $who, $message);
        $repository->refs->set(Repository::branchRef($branch), $commit, $head);

        return $commit;
    }

    private function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @chmod($path, 0666);
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
