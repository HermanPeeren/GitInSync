<?php

declare(strict_types=1);

namespace Yepr\GitInSync\Tests\Unit\Sync;

use Joomla\Registry\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Component\GitInSync\Administrator\Sync\Settings;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Registry::class)) {
            self::markTestSkipped('Needs Joomla in /joomla.');
        }
    }

    /**
     * @return  array<string, array{string, string}>
     */
    public static function paths(): array
    {
        return [
            'default: next to the site'  => ['', 'C:/wamp/www/site/gitinsync-repository'],
            'relative, outside the site' => ['../content-repository', 'C:/wamp/www/site/content-repository'],
            'relative, inside the site'  => ['./private/repo/', 'C:/wamp/www/site/joomla/private/repo'],
            'absolute Windows path'      => ['D:\data\repo', 'D:/data/repo'],
            'absolute Unix path'         => ['/var/git/../repo', '/var/repo'],
        ];
    }

    #[DataProvider('paths')]
    public function testRepositoryPathIsAbsoluteAndClean(string $option, string $expected): void
    {
        $settings = new Settings(new Registry(['repository_path' => $option]), 'C:/wamp/www/site/joomla', 'localhost');

        self::assertSame($expected, $settings->repositoryPath());
    }

    public function testContentTypesAcceptAListOrACommaString(): void
    {
        $list   = new Settings(new Registry(['content_types' => ['tag', 'article', 'nonsense']]), '/s', 'h');
        $string = new Settings(new Registry(['content_types' => 'article,category']), '/s', 'h');

        self::assertSame(['tag', 'article'], $list->contentTypes());
        self::assertSame(['category', 'article'], $string->contentTypes());
    }
}
