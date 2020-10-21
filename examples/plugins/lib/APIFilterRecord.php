<?php
namespace Psalm\Example\Plugin\lib;

use PhpParser;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassConst;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\FileManipulation;
use Psalm\FileSource;
use Psalm\Internal\Scanner\FileScanner;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Union;

class APIFilterRecord {
    /** @var Array_ parsed node with the API filters */
    public $node;

    /** @var ClassLikeStorage */
    public $storage;

    /**
     * @var array<string, array<string,string>> hopefully
     */
    public $filters;

    public function __construct(Array_ $node, ClassLikeStorage $storage) {
        $this->node = $node;
        $this->storage = $storage;
        $this->filters = $this->extractMethodFilterDefinitions();
    }

    /**
     * @return int|string|float|null
     */
    public function convertNodeToPHPScalar(Node $node) {
        if ($node instanceof Scalar) {
            if ($node instanceof String_) {
                return $node->value;
            }
            if ($node instanceof LNumber) {
                return $node->value;
            }
            if ($node instanceof DNumber) {
                return $node->value;
            }
        }
        return null;
    }

    /**
     * @return int|string|float|array|null
     * @suppress RedundantConditionGivenDocblockType
     */
    public function convertNodeToPHPLiteral(Node $node) {
        if ($node instanceof Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if (!$item) {
                    // add dummy entry of null? (only applies for list(...), so don't?)
                    return null;
                }
                // TODO: Constant lookup
                $key = $item->key;
                $resolvedKey = $key !== null ? $this->convertNodeToPHPScalar($key) : null;

                $correspondingValue = $this->convertNodeToPHPLiteral($item->value);
                // printf("result of convertNode: %s\n", json_encode([$resolvedKey, $correspondingValue]));
                if ($resolvedKey !== null) {
                    $result[$resolvedKey] = $correspondingValue;
                } else {
                    $result[] = $correspondingValue;
                }
            }
            return $result;
        }
        return $this->convertNodeToPHPScalar($node);

        // TODO: Account for constant lookup, concatenations (Not necessary in this plugin)
    }

    /**
     * @return array<string, array<string,string>> hopefully
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress MixedTypeCoercion
     */
    public function extractMethodFilterDefinitions() {
        $result = $this->convertNodeToPHPLiteral($this->node);
        if (!is_array($result)) {
            return [];
        }
        return $result;
    }
}

