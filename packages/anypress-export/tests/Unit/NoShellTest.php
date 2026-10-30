<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The export runs on hosts that turn shell access off, so it must never need it. */
final class NoShellTest extends TestCase
{
    const FORBIDDEN = ['exec', 'shell_exec', 'proc_open', 'system', 'passthru', 'popen', 'pcntl_exec'];

    public function testNothingInSrcRunsAShellCommand(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/*.php');
        $this->assertNotEmpty($files);
        $found = [];
        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if ($token === '`') $found[] = basename($file) . ': backticks';
                if (!is_array($token) || !in_array(strtolower(ltrim($token[1], '\\')), self::FORBIDDEN, true)) continue;
                // A call: the name, then "(" (skipping spaces), and not a method (->, ::) or a declaration.
                $next = $i + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
                $prev = $i - 1;
                while (isset($tokens[$prev]) && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) $prev--;
                $before = $tokens[$prev] ?? null;
                $method = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
                if (($tokens[$next] ?? null) === '(' && !$method) $found[] = basename($file) . ':' . $token[2] . ' ' . $token[1];
            }
        }
        $this->assertSame([], $found);
    }
}
