<?php

namespace App;

/**
 * Who Google says signed in, from the ID token it sent back
 */
readonly class GoogleAccount
{
    /**
     * @param string $id Google's own id for the person ("sub"), which never changes
     * @param string $email An address Google has checked they receive mail at
     * @param string $name Their name on Google, or '' when they share none
     * @param ?string $picture Their Google photo's address, or null for none
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public ?string $picture = null,
    ) {
    }
}
