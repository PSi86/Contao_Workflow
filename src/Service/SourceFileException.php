<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * A refused operation on a source file (3.6.2): a public file, a rejected upload, a folder that
 * cannot take source files.
 *
 * Carries the key of its text in $GLOBALS['TL_LANG']['workflow_privacy'] plus the arguments, so
 * the back end can tell one refusal from another (the import refusal gets a "move" button). The
 * message is that text when the language file is loaded – whoever throws makes sure it is, so the
 * import log and the console get a readable sentence – and the bare key otherwise.
 */
class SourceFileException extends \RuntimeException
{
    /**
     * @param list<string|int> $args
     */
    public function __construct(
        public readonly string $key,
        public readonly array $args = [],
        ?\Throwable $previous = null,
    ) {
        $pattern = $GLOBALS['TL_LANG']['workflow_privacy'][$key] ?? null;

        parent::__construct(\is_string($pattern) ? vsprintf($pattern, $args) : $key, 0, $previous);
    }
}
