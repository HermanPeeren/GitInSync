<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Support;

use Yepr\Component\GitInSync\Administrator\Remote\HttpTransport;

/**
 * GitHub's Git Data API, as far as GitInSync uses it, backed by a real bare repository.
 *
 * Every object GitInSync creates goes through the git program here, the way GitHub
 * itself stores it: an id GitInSync computed is only equal to the id this fake returns
 * if GitInSync's objects are byte for byte what git makes of the same content. Dates come
 * back in UTC with a "Z", as GitHub gives them.
 */
final class FakeGitHub implements HttpTransport
{
    private const PREFIX = 'https://api.github.com/repos/owner/content';

    /** @var array<int, string> */
    public array $log = [];

    public bool $private = true;

    public function __construct(public readonly string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->git(['init', '-q', '--bare', '-b', 'main']);
    }

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        if (!str_starts_with($url, self::PREFIX)) {
            return $this->json(404, ['message' => 'Not Found']);
        }

        $path = substr($url, \strlen(self::PREFIX));
        $data = $body === null ? [] : (array) json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $this->log[] = $method . ' ' . ($path === '' ? '/' : $path);

        if ($path === '' && $method === 'GET') {
            return $this->json(200, [
                'private'        => $this->private,
                'default_branch' => 'main',
                'html_url'       => 'https://github.com/owner/content',
                'size'           => $this->isEmpty() ? 0 : 1,
            ]);
        }

        if ($path === '/contents/README.md' && $method === 'PUT') {
            return $this->createFile((string) $data['branch'], 'README.md', base64_decode((string) $data['content']), (string) $data['message']);
        }

        // The Git Data API refuses to work on an empty repository.
        if ($this->isEmpty()) {
            return $this->json(409, ['message' => 'Git Repository is empty.']);
        }

        return match (true) {
            $method === 'POST' && $path === '/git/blobs'   => $this->createBlob((string) $data['content']),
            $method === 'POST' && $path === '/git/trees'   => $this->createTree((array) $data['tree']),
            $method === 'POST' && $path === '/git/commits' => $this->createCommit($data),
            $method === 'POST' && $path === '/git/refs'    => $this->createRef((string) $data['ref'], (string) $data['sha']),
            $method === 'GET' && str_starts_with($path, '/git/blobs/')   => $this->getBlob(substr($path, 11)),
            $method === 'GET' && str_starts_with($path, '/git/trees/')   => $this->getTree(substr($path, 11)),
            $method === 'GET' && str_starts_with($path, '/git/commits/') => $this->getCommit(substr($path, 13)),
            $method === 'GET' && $path === '/git/matching-refs/heads/'   => $this->matchingRefs(),
            $method === 'GET' && str_starts_with($path, '/git/ref/heads/') => $this->getRef(rawurldecode(substr($path, 15))),
            $method === 'PATCH' && str_starts_with($path, '/git/refs/heads/') => $this->updateRef(
                rawurldecode(substr($path, 16)),
                (string) $data['sha'],
                (bool) ($data['force'] ?? false)
            ),
            $method === 'DELETE' && str_starts_with($path, '/git/refs/heads/') => $this->deleteRef(rawurldecode(substr($path, 16))),
            default => $this->json(404, ['message' => 'Not Found']),
        };
    }

    /**
     * A commit made "on GitHub", as a merged pull request would be.
     */
    public function commitFile(string $branch, string $path, string $content, string $message): string
    {
        $parent = $this->ref($branch);
        $index  = $this->dir . '/fake-index';
        $env    = ['GIT_INDEX_FILE' => $index];

        @unlink($index);

        if ($parent !== null) {
            $this->git(['read-tree', $parent], '', $env);
        }

        $blob = $this->hash('blob', $content);
        $this->git(['update-index', '--add', '--cacheinfo', '100644,' . $blob . ',' . $path], '', $env);
        $tree   = $this->git(['write-tree'], '', $env);
        $commit = $this->git(
            array_merge(['commit-tree', $tree], $parent === null ? [] : ['-p', $parent]),
            $message . "\n",
            $this->identity('Web Editor', 'editor@example.invalid', (new \DateTimeImmutable('now', new \DateTimeZone('+01:00')))->format('c'))
        );
        $this->git(['update-ref', 'refs/heads/' . $branch, $commit]);
        @unlink($index);

        return $commit;
    }

    public function ref(string $branch): ?string
    {
        $sha = $this->git(['rev-parse', '--verify', '-q', 'refs/heads/' . $branch], '', [], false);

        return $sha === '' ? null : $sha;
    }

    /**
     * @param   string[]               $args
     * @param   array<string, string>  $env
     */
    public function git(array $args, string $stdin = '', array $env = [], bool $check = true): string
    {
        $process = proc_open(
            array_values(array_merge(['git', '--git-dir=' . $this->dir], $args)),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), $env)
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot run git.');
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($check && $status !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . " failed:\n" . $err);
        }

        return rtrim($out, "\n");
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function createBlob(string $base64): array
    {
        return $this->json(201, ['sha' => $this->hash('blob', (string) base64_decode($base64))]);
    }

    private function isEmpty(): bool
    {
        return $this->git(['for-each-ref', '--count=1', 'refs/heads'], '', [], false) === '';
    }

    private function hash(string $type, string $content): string
    {
        return $this->git(['hash-object', '-w', '-t', $type, '--stdin'], $content);
    }

    /**
     * @param   array<int, mixed>  $entries
     *
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function createTree(array $entries): array
    {
        $lines = '';

        foreach ($entries as $entry) {
            $entry = (array) $entry;

            if (!\in_array($entry['mode'], ['100644', '100755', '040000', '120000', '160000'], true)) {
                return $this->json(422, ['message' => 'Invalid tree mode ' . $entry['mode']]);
            }

            $lines .= \sprintf("%s %s %s\t%s\n", $entry['mode'], $entry['type'], $entry['sha'], $entry['path']);
        }

        return $this->json(201, ['sha' => $this->git(['mktree'], $lines)]);
    }

    /**
     * @param   array<mixed>  $data
     *
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function createCommit(array $data): array
    {
        $args = ['commit-tree', (string) $data['tree']];

        foreach ((array) $data['parents'] as $parent) {
            $args[] = '-p';
            $args[] = (string) $parent;
        }

        $author    = (array) $data['author'];
        $committer = (array) $data['committer'];
        $env       = $this->identity((string) $author['name'], (string) $author['email'], (string) $author['date']);
        $env       = array_merge($env, [
            'GIT_COMMITTER_NAME'  => (string) $committer['name'],
            'GIT_COMMITTER_EMAIL' => (string) $committer['email'],
            'GIT_COMMITTER_DATE'  => (string) $committer['date'],
        ]);

        return $this->json(201, ['sha' => $this->git($args, (string) $data['message'], $env)]);
    }

    /**
     * @return  array<string, string>
     */
    private function identity(string $name, string $email, string $date): array
    {
        return [
            'GIT_AUTHOR_NAME'     => $name,
            'GIT_AUTHOR_EMAIL'    => $email,
            'GIT_AUTHOR_DATE'     => $date,
            'GIT_COMMITTER_NAME'  => $name,
            'GIT_COMMITTER_EMAIL' => $email,
            'GIT_COMMITTER_DATE'  => $date,
        ];
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function createFile(string $branch, string $path, string $content, string $message): array
    {
        return $this->json(201, ['commit' => ['sha' => $this->commitFile($branch, $path, $content, $message)]]);
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function getBlob(string $sha): array
    {
        if ($this->git(['cat-file', '-t', $sha], '', [], false) !== 'blob') {
            return $this->json(404, ['message' => 'Not Found']);
        }

        $content = $this->gitRaw(['cat-file', 'blob', $sha]);

        return $this->json(200, ['sha' => $sha, 'content' => chunk_split(base64_encode($content), 60, "\n"), 'encoding' => 'base64']);
    }

    /**
     * @param   string[]  $args
     */
    private function gitRaw(array $args): string
    {
        $process = proc_open(array_values(array_merge(['git', '--git-dir=' . $this->dir], $args)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            throw new \RuntimeException('Cannot run git.');
        }

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function getTree(string $sha): array
    {
        $entries = [];

        // -z: entries end with NUL, so a name may hold anything.
        foreach (explode("\0", $this->gitRaw(['ls-tree', '-z', $sha])) as $item) {
            if ($item === '') {
                continue;
            }

            [$meta, $name]       = explode("\t", $item, 2);
            [$mode, $type, $obj] = explode(' ', $meta);
            $entries[]           = ['path' => $name, 'mode' => $mode, 'type' => $type, 'sha' => $obj];
        }

        return $this->json(200, ['sha' => $sha, 'tree' => $entries, 'truncated' => false]);
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function getCommit(string $sha): array
    {
        if ($this->git(['cat-file', '-t', $sha], '', [], false) !== 'commit') {
            return $this->json(404, ['message' => 'Not Found']);
        }

        $raw                = $this->gitRaw(['cat-file', 'commit', $sha]);
        [$head, $message]   = explode("\n\n", $raw, 2);
        $tree               = '';
        $parents            = [];
        $people             = [];

        foreach (explode("\n", $head) as $line) {
            [$key, $value] = explode(' ', $line, 2) + [1 => ''];

            if ($key === 'tree') {
                $tree = $value;
            } elseif ($key === 'parent') {
                $parents[] = ['sha' => $value];
            } elseif ($key === 'author' || $key === 'committer') {
                if (!preg_match('/^(.*) <(.*)> (\d+) ([+-]\d{4})$/', $value, $m)) {
                    return $this->json(500, ['message' => 'Bad signature']);
                }

                $people[$key] = ['name' => $m[1], 'email' => $m[2], 'date' => gmdate('Y-m-d\TH:i:s\Z', (int) $m[3])];
            }
        }

        return $this->json(200, [
            'sha'          => $sha,
            'tree'         => ['sha' => $tree],
            'parents'      => $parents,
            'author'       => $people['author'] ?? null,
            'committer'    => $people['committer'] ?? null,
            // As GitHub gives it: without git's final newline.
            'message'      => rtrim($message, "\n"),
            'verification' => ['verified' => false, 'reason' => 'unsigned', 'signature' => null, 'payload' => null],
        ]);
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function matchingRefs(): array
    {
        $refs = [];

        foreach (array_filter(explode("\n", $this->git(['for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads']))) as $line) {
            [$ref, $sha] = explode(' ', $line);
            $refs[]      = ['ref' => $ref, 'object' => ['sha' => $sha, 'type' => 'commit']];
        }

        return $this->json(200, $refs);
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function getRef(string $branch): array
    {
        $sha = $this->ref($branch);

        return $sha === null
            ? $this->json(404, ['message' => 'Not Found'])
            : $this->json(200, ['ref' => 'refs/heads/' . $branch, 'object' => ['sha' => $sha, 'type' => 'commit']]);
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function createRef(string $ref, string $sha): array
    {
        if ($this->git(['rev-parse', '--verify', '-q', $ref], '', [], false) !== '') {
            return $this->json(422, ['message' => 'Reference already exists']);
        }

        $this->git(['update-ref', $ref, $sha]);

        return $this->json(201, ['ref' => $ref, 'object' => ['sha' => $sha]]);
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function updateRef(string $branch, string $sha, bool $force): array
    {
        $old = $this->ref($branch);

        if ($old === null) {
            return $this->json(422, ['message' => 'Reference does not exist']);
        }

        if (!$force) {
            if (!$this->isAncestor($old, $sha)) {
                return $this->json(422, ['message' => 'Update is not a fast forward']);
            }
        }

        $this->git(['update-ref', 'refs/heads/' . $branch, $sha]);

        return $this->json(200, ['ref' => 'refs/heads/' . $branch, 'object' => ['sha' => $sha]]);
    }

    private function isAncestor(string $old, string $new): bool
    {
        $command = ['git', '--git-dir=' . $this->dir, 'merge-base', '--is-ancestor', $old, $new];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!\is_resource($process)) {
            return false;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    /**
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function deleteRef(string $branch): array
    {
        if ($this->ref($branch) === null) {
            return $this->json(422, ['message' => 'Reference does not exist']);
        }

        $this->git(['update-ref', '-d', 'refs/heads/' . $branch]);

        return ['status' => 204, 'headers' => [], 'body' => ''];
    }

    /**
     * @param   array<mixed>  $data
     *
     * @return  array{status: int, headers: array<string, string>, body: string}
     */
    private function json(int $status, array $data): array
    {
        return [
            'status'  => $status,
            'headers' => ['x-ratelimit-remaining' => '4999'],
            'body'    => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
