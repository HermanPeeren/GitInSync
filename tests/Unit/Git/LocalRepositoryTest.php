<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Yepr\Component\GitInSync\Administrator\Git\GitObject;
use Yepr\Component\GitInSync\Administrator\Git\LocalRepository;
use Yepr\Component\GitInSync\Administrator\Git\RefConflict;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Git\Signature;

/**
 * The repository on disk must be real git: the git program reads what GitInSync writes,
 * and GitInSync reads what the git program writes.
 *
 * Only these tests use the git program; the package itself never does. Without git
 * installed, the tests that need it are skipped.
 */
final class LocalRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gitinsync-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testBlobIdIsTheSameAsGitHashObject(): void
    {
        // `printf 'hello\n' | git hash-object --stdin`
        self::assertSame('ce013625030ba8dba906f756967f9e9ca394464a', GitObject::blob("hello\n")->id());
    }

    public function testWritesARepositoryThatGitAccepts(): void
    {
        $this->requireGit();

        [$repository, $commit] = $this->repositoryWithTwoCommits();

        $this->git('fsck --strict --no-dangling');

        self::assertSame($commit, trim($this->git('rev-parse refs/heads/main')));
        self::assertSame("Second\nFirst\n", $this->git('log --format=%s main'));
        self::assertSame("<p>Changed</p>\n", $this->git('show main:articles/0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b-hello/introtext.html'));
        self::assertSame(
            "A\tarticles/0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b-hello/fulltext.html\n"
            . "M\tarticles/0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b-hello/introtext.html\n",
            $this->git('diff --name-status main~1 main')
        );
        self::assertSame('Editor Name <editor@example.invalid>', trim($this->git('log -1 --format="%an <%ae>" main')));
    }

    public function testReadsWhatGitWrites(): void
    {
        $this->requireGit();

        mkdir($this->dir);
        $this->git('init -q -b main --bare .');
        $work = $this->dir . '-work';
        exec('git clone -q ' . escapeshellarg($this->dir) . ' ' . escapeshellarg($work) . ' 2>&1');
        mkdir($work . '/sub');
        file_put_contents($work . '/sub/a.txt', "line\n");
        file_put_contents($work . '/b.json', "{}\n");
        exec('git -C ' . escapeshellarg($work) . ' -c user.name=T -c user.email=t@example.invalid add -A');
        exec('git -C ' . escapeshellarg($work) . ' -c user.name=T -c user.email=t@example.invalid commit -q -m "From git"');
        exec('git -C ' . escapeshellarg($work) . ' push -q origin main 2>&1');
        $this->remove($work);

        // Pushed objects arrive in a pack; unpack them, as GitInSync reads loose objects only.
        foreach (glob($this->dir . '/objects/pack/*.pack') ?: [] as $pack) {
            rename($pack, $pack . '.moved');
            exec('git -C ' . escapeshellarg($this->dir) . ' unpack-objects -q < ' . escapeshellarg($pack . '.moved'));
            unlink($pack . '.moved');
            unlink(substr($pack, 0, -5) . '.idx');
        }

        $local      = new LocalRepository($this->dir);
        $repository = new Repository($local, $local);
        $head       = $repository->head('main');

        self::assertNotNull($head);
        self::assertSame('From git', $repository->readCommit($head)->subject());
        self::assertSame(['b.json', 'sub/a.txt'], array_keys($repository->files($head)));

        // And writing it back gives the same id.
        self::assertSame($head, $local->write($local->read($head)));
    }

    public function testRefusesToMoveABranchThatMoved(): void
    {
        [$repository, $commit] = $this->repositoryWithTwoCommits();

        $this->expectException(RefConflict::class);
        $repository->refs->set('refs/heads/main', $commit, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    }

    public function testHistoryAndAncestry(): void
    {
        [$repository, $second] = $this->repositoryWithTwoCommits();
        $first                 = $repository->readCommit($second)->parents[0];

        self::assertSame([$second, $first], array_keys($repository->log($second)));
        self::assertTrue($repository->isAncestor($first, $second));
        self::assertFalse($repository->isAncestor($second, $first));
        self::assertSame($first, $repository->mergeBase($first, $second));

        $changes = $repository->changes($repository->readCommit($first)->tree, $repository->readCommit($second)->tree);
        self::assertCount(2, $changes);
    }

    /**
     * @return array{0: Repository, 1: string}
     */
    private function repositoryWithTwoCommits(): array
    {
        $local = new LocalRepository($this->dir);
        $local->init();
        $repository = new Repository($local, $local);

        $folder = 'articles/0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b-hello/';
        $author = new Signature('Editor Name', 'editor@example.invalid', 1763460000);

        $files = [
            $folder . 'article.json'   => $repository->writeBlob("{\n    \"title\": \"Hello\"\n}\n"),
            $folder . 'introtext.html' => $repository->writeBlob("<p>Hello</p>\n"),
            'README.md'                => $repository->writeBlob("# Content\n"),
        ];
        $first = $repository->writeCommit($repository->writeTree($files), [], $author, $author, 'First');
        $repository->refs->set('refs/heads/main', $first, null);

        $files[$folder . 'introtext.html'] = $repository->writeBlob("<p>Changed</p>\n");
        $files[$folder . 'fulltext.html']  = $repository->writeBlob("<p>More</p>\n");
        $second                            = $repository->writeCommit(
            $repository->writeTree($files),
            [$first],
            $author,
            new Signature('GitInSync', 'gitinsync@example.invalid', 1763460060),
            "Second\n\nWith a body."
        );
        $repository->refs->set('refs/heads/main', $second, $first);

        return [$repository, $second];
    }

    private function requireGit(): void
    {
        exec('git --version 2>&1', $output, $status);

        if ($status !== 0) {
            self::markTestSkipped('The git program is not installed.');
        }
    }

    private function git(string $arguments): string
    {
        exec('git -C ' . escapeshellarg($this->dir) . ' ' . $arguments . ' 2>&1', $output, $status);
        $text = implode("\n", $output) . ($output === [] ? '' : "\n");

        self::assertSame(0, $status, "git {$arguments} failed:\n{$text}");

        return $text;
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
