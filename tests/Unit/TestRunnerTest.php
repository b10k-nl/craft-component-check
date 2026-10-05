<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\services\TestRunner;
use PHPUnit\Framework\TestCase;

class TestRunnerTest extends TestCase
{
    public function testResetDeletesOnlyWhatTheSnapshotWrote(): void
    {
        // A baseline folder in the repository can hold other files too.
        $dir = sys_get_temp_dir() . '/cc-reset-' . uniqid();
        mkdir($dir . '/desktop', 0777, true);
        file_put_contents($dir . '/desktop/1.json', '{}');
        file_put_contents($dir . '/desktop/1.png', 'png');
        file_put_contents($dir . '/README.md', 'keep me');
        file_put_contents($dir . '/index.json', json_encode(['blocks' => [
            '1|desktop' => ['geometry' => 'desktop/1.json', 'screenshot' => 'desktop/1.png'],
            'bad' => ['geometry' => '../outside.json'],
        ]]));
        file_put_contents(dirname($dir) . '/outside.json', 'not ours');

        $runner = (new \ReflectionClass(TestRunner::class))->newInstanceWithoutConstructor();
        $this->assertSame(2, $runner->clearSnapshot($dir));

        $this->assertSame(['README.md'], array_values(array_diff(scandir($dir), ['.', '..'])));
        $this->assertFileExists(dirname($dir) . '/outside.json', 'never outside the snapshot folder');
        $this->assertSame(0, $runner->clearSnapshot($dir), 'no index, nothing to do');
    }
}
