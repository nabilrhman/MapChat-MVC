<?php

namespace Core;

/**
 * A known URL requested with a method none of its routes take
 */
class MethodNotAllowed extends \Exception
{
    /**
     * Say which methods the URL does take
     *
     * @param string[] $allowed The methods the URL takes, for the Allow header
     */
    public function __construct(public readonly array $allowed)
    {
        parent::__construct('Method not allowed', 405);
    }
}
