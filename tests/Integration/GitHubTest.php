<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Integration;

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
use Yepr\Component\GitInSync\Administrator\Remote\JoomlaHttpTransport;
use Yepr\Component\GitInSync\Administrator\Remote\Transfer;
use Yepr\GitInSync\Tests\Support\TestDatabase;

/**
 * Against GitHub itself, through Joomla's HTTP client, as the site does it.
 *
 * Needs a private test repository and a fine-grained token for it in the git-ignored
 * tests/github.env.json: {"owner": "...", "repository": "...", "token": "..."}. Without that
 * file the tests are skipped (as on CI). Each test works on a branch of its own and
 * deletes it afterwards; only the README on the default branch stays.
 */
final class GitHubTest extends TestCase
{
    private const ARTICLE = 'articles/0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b-hello/introtext.html';

    private GitHubClient $client;

    private DatabaseInterface $db;

    private string $dir;

    private string $branch;

    protected function setUp(): void
    {
        $file = \dirname(__DIR__) . '/github.env.json';

        if (!is_file($file)) {
            self::markTestSkipped('No tests/github.env.json.');
        }

        $env = json_decode((string) file_get_contents($file), true);

        if (!\is_array($env) || !isset($env['owner'], $env['repository'], $env['token'])) {
            self::fail('tests/github.env.json needs owner, repository and token.');
        }

        $this->client = new GitHubClient(new JoomlaHttpTransport(), (string) $env['owner'], (string) $env['repository'], (string) $env['token']);
        $this->db     = TestDatabase::create();
        $this->dir    = sys_get_temp_dir() . '/gitinsync-github-' . bin2hex(random_bytes(4));
        $this->branch = 'test/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        $repository = $this->client->repository();

        if (!$repository['private']) {
            self::fail('The test repository must be private.');
        }

        if ($repository['empty']) {
            $this->client->initialise($repository['default_branch']);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->branch) && $this->client->getRef($this->branch) !== null) {
            $this->client->deleteRef($this->branch);
        }

        if (isset($this->dir)) {
            $this->remove($this->dir);
        }
    }

    public function testFetchRebuildsGitHubsOwnCommitsWithTheSameIds(): void
    {
        // The default branch starts with a commit made by GitHub (through the Contents API),
        // which GitHub signs: rebuilding it must keep the signature.
        $main = $this->client->repository()['default_branch'];
        $head = (string) $this->client->getRef($main);

        [$local, $repository, $transfer] = $this->site('a');
        $result = $transfer->fetch($main, Budget::unlimited());

        self::assertTrue($result['done']);
        self::assertSame($head, $result['head']);
        self::assertTrue($local->has($head));
        self::assertSame($head, $local->read($head)->id(), 'The commit is stored under an id that matches its content.');
        self::assertArrayHasKey('README.md', $repository->files($head));
    }

    public function testPushedCommitsKeepTheirIdsOnGitHub(): void
    {
        [, $repository, $transfer] = $this->site('a');

        $first  = $this->commit($repository, [self::ARTICLE => "<p>Hello</p>\n"], 'Add article "Hello"');
        $second = $this->commit($repository, [
            self::ARTICLE => "<p>Hello, world</p>\n",
            'categories/1a2b3c4d-1c3d-4e5f-8a9b-0c1d2e3f4a5b-news/category.json' => "{\n    \"title\": \"News\"\n}\n",
        ], 'Update article "Hello"');
        $this->assertBranchHead($repository, $second);

        $result = $transfer->push($this->branch, Budget::unlimited());

        self::assertTrue($result['done'], $result['message']);
        self::assertSame(2, $result['commits']);
        self::assertSame($second, $this->client->getRef($this->branch));
        self::assertSame([$first], array_column((array) $this->client->getCommit($second)['parents'], 'sha'));

        // A second site fetches the branch and gets the same history.
        [$other, $otherRepository, $otherTransfer] = $this->site('b');
        $fetch = $otherTransfer->fetch($this->branch, Budget::unlimited());

        self::assertSame($second, $fetch['head']);
        self::assertSame($second, $other->read($second)->id());
        self::assertSame(
            "<p>Hello, world</p>\n",
            $otherRepository->readBlob($otherRepository->files($second)[self::ARTICLE])
        );
    }

    public function testGitHubOnlyModeCommitsThroughTheApi(): void
    {
        [, $repository, $transfer] = $this->site('a');
        $start = $this->commit($repository, ['a.txt' => "a\n"], 'Start');
        $this->assertBranchHead($repository, $start);
        $transfer->push($this->branch, Budget::unlimited());

        $remote     = new GitHubRepository(new GitHubObjects($this->client), $this->db);
        $onGitHub   = new Repository($remote, $remote);
        $ref        = Repository::branchRef($this->branch);
        $files      = $onGitHub->files($start);
        $files['b.txt'] = $onGitHub->writeBlob("b\n");
        $who        = new Signature('Editor', 'editor@example.invalid', 1_800_000_000);
        $commit     = $onGitHub->writeCommit($onGitHub->writeTree($files), [$start], $who, $who, "Add b\n");

        $onGitHub->refs->set($ref, $commit, $start);

        self::assertSame($commit, $this->client->getRef($this->branch));

        // Moving the branch from a commit that is no longer its head is refused.
        $this->expectException(RefConflict::class);
        $onGitHub->refs->set($ref, $start, $start);
    }

    /**
     * @return  array{0: LocalRepository, 1: Repository, 2: Transfer}
     */
    private function site(string $name): array
    {
        $local = new LocalRepository($this->dir . '/' . $name);
        $local->init($this->branch);
        $repository = new Repository($local, $local);

        return [$local, $repository, new Transfer($local, $repository, new GitHubObjects($this->client), $this->db)];
    }

    /**
     * @param   array<string, string>  $changes
     */
    private function commit(Repository $repository, array $changes, string $message): string
    {
        $ref   = Repository::branchRef($this->branch);
        $head  = $repository->refs->get($ref);
        $files = $repository->files($head);

        foreach ($changes as $path => $content) {
            $files[$path] = $repository->writeBlob($content);
        }

        static $time = 1_800_000_000;
        $time += 60;
        $who    = new Signature('Editor', '1+editor@users.noreply.example.invalid', $time);
        $commit = $repository->writeCommit($repository->writeTree($files), $head === null ? [] : [$head], $who, $who, $message);
        $repository->refs->set($ref, $commit, $head);

        return $commit;
    }

    /**
     * The commits went onto the test branch, which is what gets pushed.
     */
    private function assertBranchHead(Repository $repository, string $head): void
    {
        self::assertSame($head, $repository->head($this->branch));
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
