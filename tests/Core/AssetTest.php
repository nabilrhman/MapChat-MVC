<?php

namespace Tests\Core;

use Core\Asset;
use PHPUnit\Framework\TestCase;

class AssetTest extends TestCase
{
    /** A throwaway file in public/, made for each test that needs one */
    const SCRATCH_PATH = '/asset-test.css';

    private string $scratchFile;

    protected function setUp(): void
    {
        $this->scratchFile = dirname(__DIR__, 2) . '/public' . self::SCRATCH_PATH;
    }

    protected function tearDown(): void
    {
        if (is_file($this->scratchFile)) {
            unlink($this->scratchFile);
        }
    }

    public function testVersionIsTheFilesContentHash(): void
    {
        $file = dirname(__DIR__, 2) . '/public/css/core/ui.css';

        $this->assertSame('/css/core/ui.css?v=' . hash_file('crc32b', $file), Asset::url('/css/core/ui.css'));
    }

    public function testChangedFileGetsANewLink(): void
    {
        // Two paths for one file: Asset remembers each path's link for the
        // rest of the request, as a page asks for the same file only once
        file_put_contents($this->scratchFile, 'a { color: red; }');
        $before = Asset::url(self::SCRATCH_PATH);

        file_put_contents($this->scratchFile, 'a { color: blue; }');
        $after = Asset::url('/./' . ltrim(self::SCRATCH_PATH, '/'));

        $this->assertNotSame(substr($before, -8), substr($after, -8));
    }

    public function testMissingFileKeepsItsPlainLink(): void
    {
        $this->assertSame('/css/no-such-file.css', Asset::url('/css/no-such-file.css'));
    }
}
