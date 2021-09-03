<?php

namespace Nsq\Lookup;

use Nsq\Exception\LookupException;

final class Response
{
    /**
     * @param string[] $channels
     * @param Producer[] $producers
     */
    public function __construct(
        public array $channels,
        public array $producers,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $array = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (array_key_exists('message', $array)) {
            throw new LookupException($array['message']);
        }

        return new self(
            $array['channels'],
            array_map([Producer::class, 'fromArray'], $array['producers']),
        );
    }
}
