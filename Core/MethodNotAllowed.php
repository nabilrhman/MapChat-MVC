<?php

namespace Core;

/**
 * A known URL requested with a method none of its routes take
 */
class MethodNotAllowed extends \Exception
{
    /**
     * The methods the URL takes, for the Allow header
     * @var array
     */
    public $allowed;

    /**
     * @param array $allowed The methods the URL takes
     */
    public function __construct($allowed)
    {
        parent::__construct('Method not allowed', 405);

        $this->allowed = $allowed;
    }
}
