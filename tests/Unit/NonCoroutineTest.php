<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Unit;

use PHPUnit\Framework\TestCase;

final class NonCoroutineTest extends TestCase
{
    public function testExplicitCliScopesDoNotLeakViaNonCoroutineStaticContext(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$calls=0; for($i=0;$i<2;++$i){ \Hyperf\ModelCache\Context\RequestScope::run(function()use(&$calls){'
            . '$load=function()use(&$calls){++$calls;return ["id"=>1];};'
            . '\Hyperf\ModelCache\Context\RequestScope::current()->remember("x",$load);'
            . '\Hyperf\ModelCache\Context\RequestScope::current()->remember("x",$load);});}'
            . 'echo json_encode([$calls,\Hyperf\ModelCache\Context\RequestScope::current()]);';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame([2, null], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }
}
