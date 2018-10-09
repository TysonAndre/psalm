<?php declare(strict_types=1);

namespace Psalm\Diff;

use PhpParser;

/**
 * Borrows from https://github.com/nikic/PHP-Parser/blob/master/lib/PhpParser/Internal/Differ.php
 *
 * Implements the Myers diff algorithm.
 *
 * Myers, Eugene W. "An O (ND) difference algorithm and its variations."
 * Algorithmica 1.1 (1986): 251-266.
 *
 * @internal
 */
class Differ
{
    /**
     * @param  \Closure $is_equal
     * @param  array    $a
     * @param  array    $b
     * @return array{0:array, 1: int, 2: int, 3: array<int, true>, 4: array<int, array{0: int, 1: int, 2: int, 3: int}>}
     */
    protected static function calculateTrace(
        \Closure $is_equal,
        array $a,
        array $b,
        $a_code,
        $b_code
    ) : array {
        $n = \count($a);
        $m = \count($b);
        $max = $n + $m;
        $v = [1 => 0];
        $bc = [];
        $trace = [];
        $body_change = false;
        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && $v[$k-1] < $v[$k+1])) {
                    $x = $v[$k+1];
                } else {
                    $x = $v[$k-1] + 1;
                }

                $y = $x - $k;

                while ($x < $n && $y < $m && ($is_equal)($a[$x], $b[$y], $a_code, $b_code, $body_change)) {
                    $bc[$x] = $body_change;
                    $x++;
                    $y++;
                }

                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return [$trace, $x, $y, $bc];
                }
            }
        }
        throw new \Exception('Should not happen');
    }

    /**
     * @return DiffElem[]
     */
    protected static function extractDiff(array $trace, $x, $y, array $a, array $b, array $bc) : array
    {
        $result = [];
        for ($d = \count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;

            if ($k === -$d || ($k !== $d && $v[$k-1] < $v[$k+1])) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }

            $prevX = $v[$prevK];
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $result[] = new DiffElem(
                    $bc[$x-1] ? DiffElem::TYPE_KEEP_SIGNATURE : DiffElem::TYPE_KEEP,
                    $a[$x-1],
                    $b[$y-1]
                );
                $x--;
                $y--;
            }

            if ($d === 0) {
                break;
            }

            while ($x > $prevX) {
                $result[] = new DiffElem(DiffElem::TYPE_REMOVE, $a[$x-1], null);
                $x--;
            }

            while ($y > $prevY) {
                $result[] = new DiffElem(DiffElem::TYPE_ADD, null, $b[$y-1]);
                $y--;
            }
        }
        return array_reverse($result);
    }
}
