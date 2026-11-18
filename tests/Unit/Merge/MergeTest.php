<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Unit\Merge;

use PHPUnit\Framework\TestCase;
use Yepr\Component\GitInSync\Administrator\Content\CanonicalJson;
use Yepr\Component\GitInSync\Administrator\Diff\Diff3;
use Yepr\Component\GitInSync\Administrator\Diff\LineDiff;
use Yepr\Component\GitInSync\Administrator\Git\MemoryRepository;
use Yepr\Component\GitInSync\Administrator\Git\Repository;
use Yepr\Component\GitInSync\Administrator\Merge\Conflict;
use Yepr\Component\GitInSync\Administrator\Merge\JsonMerge;
use Yepr\Component\GitInSync\Administrator\Merge\TreeMerge;

final class MergeTest extends TestCase
{
    private const UUID = '0b6e9f2a-1c3d-4e5f-8a9b-0c1d2e3f4a5b';

    public function testLineDiff(): void
    {
        $ops = LineDiff::diff(['a', 'b', 'c'], ['a', 'x', 'c', 'd']);

        self::assertSame([[' ', 'a'], ['-', 'b'], ['+', 'x'], [' ', 'c'], ['+', 'd']], $ops);
    }

    public function testDiff3TakesChangesOfBothSides(): void
    {
        $result = Diff3::merge("a\nb\nc\nd\ne\n", "A\nb\nc\nd\ne\n", "a\nb\nc\nd\nE\n");

        self::assertSame(['text' => "A\nb\nc\nd\nE\n", 'conflicts' => 0], $result);
    }

    public function testDiff3TakesTheSameChangeOnce(): void
    {
        $result = Diff3::merge("a\nb\nc\n", "a\nX\nc\n", "a\nX\nc\n");

        self::assertSame(['text' => "a\nX\nc\n", 'conflicts' => 0], $result);
    }

    public function testDiff3MarksAConflict(): void
    {
        $result = Diff3::merge("a\nb\nc\n", "a\nours\nc\n", "a\ntheirs\nc\n", 'main', 'staging');

        self::assertSame(1, $result['conflicts']);
        self::assertSame("a\n<<<<<<< main\nours\n||||||| base\nb\n=======\ntheirs\n>>>>>>> staging\nc\n", $result['text']);
        self::assertTrue(Diff3::hasMarkers($result['text']));
    }

    public function testDiff3InsertionsAtTheSamePlaceConflict(): void
    {
        $result = Diff3::merge("a\nc\n", "a\nb1\nc\n", "a\nb2\nc\n");

        self::assertSame(1, $result['conflicts']);
    }

    public function testJsonMergePerKey(): void
    {
        $merged = JsonMerge::merge(
            ['title' => 'Old', 'catid' => 'x', 'attribs' => ['a' => 1, 'b' => 2]],
            ['title' => 'New', 'catid' => 'x', 'attribs' => ['a' => 9, 'b' => 2]],
            ['title' => 'Old', 'catid' => 'y', 'attribs' => ['a' => 1, 'b' => 3], 'extra' => true]
        );

        self::assertSame([], $merged['conflicts']);
        self::assertSame(
            ['title' => 'New', 'catid' => 'y', 'attribs' => ['a' => 9, 'b' => 3], 'extra' => true],
            $merged['result']
        );
    }

    public function testJsonMergeConflictKeepsOurs(): void
    {
        $merged = JsonMerge::merge(['title' => 'Old'], ['title' => 'Ours'], ['title' => 'Theirs']);

        self::assertSame(['title' => 'Ours'], $merged['result']);
        self::assertSame([['path' => 'title', 'base' => 'Old', 'ours' => 'Ours', 'theirs' => 'Theirs']], $merged['conflicts']);
    }

    public function testJsonMergeDeletedKey(): void
    {
        $merged = JsonMerge::merge(['a' => 1, 'b' => 2], ['a' => 1], ['a' => 5, 'b' => 2]);

        self::assertSame(['a' => 5], $merged['result']);
        self::assertSame([], $merged['conflicts']);
    }

    public function testTreeMergeFollowsARenamedFolder(): void
    {
        $repo = new Repository(new MemoryRepository(), new MemoryRepository());

        $json = fn (array $data): string => $repo->writeBlob(CanonicalJson::encode($data));
        $html = fn (string $text): string => $repo->writeBlob($text);

        $base = [
            'articles/' . self::UUID . '-hello/article.json'   => $json(['alias' => 'hello', 'title' => 'Hello']),
            'articles/' . self::UUID . '-hello/introtext.html' => $html("<p>one</p>\n<p>two</p>\n"),
        ];

        // Ours renames the alias (and so the folder); theirs edits the text.
        $ours = [
            'articles/' . self::UUID . '-hi/article.json'   => $json(['alias' => 'hi', 'title' => 'Hello']),
            'articles/' . self::UUID . '-hi/introtext.html' => $html("<p>one</p>\n<p>two</p>\n"),
        ];
        $theirs = [
            'articles/' . self::UUID . '-hello/article.json'   => $json(['alias' => 'hello', 'title' => 'Hello']),
            'articles/' . self::UUID . '-hello/introtext.html' => $html("<p>one</p>\n<p>TWO</p>\n"),
        ];

        $result = (new TreeMerge($repo))->merge($base, $ours, $theirs);

        self::assertTrue($result->isClean());
        self::assertSame(
            ['articles/' . self::UUID . '-hi/article.json', 'articles/' . self::UUID . '-hi/introtext.html'],
            array_keys($result->files)
        );
        self::assertSame("<p>one</p>\n<p>TWO</p>\n", $repo->readBlob($result->files['articles/' . self::UUID . '-hi/introtext.html']));
    }

    public function testTreeMergeDeleteAgainstChangeIsAConflict(): void
    {
        $repo = new Repository(new MemoryRepository(), new MemoryRepository());
        $path = 'articles/' . self::UUID . '-hello/article.json';

        $base   = [$path => $repo->writeBlob(CanonicalJson::encode(['title' => 'Hello']))];
        $theirs = [$path => $repo->writeBlob(CanonicalJson::encode(['title' => 'Changed']))];

        $result = (new TreeMerge($repo))->merge($base, [], $theirs);

        self::assertCount(1, $result->conflicts);
        self::assertSame(Conflict::DELETED, $result->conflicts[0]->kind);
        self::assertSame([], $result->files);
    }

    public function testTreeMergeContentConflictIsReportedPerField(): void
    {
        $repo = new Repository(new MemoryRepository(), new MemoryRepository());
        $path = 'articles/' . self::UUID . '-hello/article.json';

        $result = (new TreeMerge($repo))->merge(
            [$path => $repo->writeBlob(CanonicalJson::encode(['title' => 'Hello', 'state' => 1]))],
            [$path => $repo->writeBlob(CanonicalJson::encode(['title' => 'Ours', 'state' => 1]))],
            [$path => $repo->writeBlob(CanonicalJson::encode(['title' => 'Theirs', 'state' => 0]))]
        );

        self::assertCount(1, $result->conflicts);
        $details = $result->conflicts[0]->details ?? [];
        self::assertSame('title', $details[0]['field']);
        self::assertSame(['state' => 0, 'title' => 'Ours'], CanonicalJson::decode($repo->readBlob($result->files[$path])));
    }
}
