<?php

namespace FixtureBad\Support\Live;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Rule live.broadcast-now violation: a queued broadcast as a live prop notice. */
final class BuildChanged implements ShouldBroadcast
{
    public function __construct(public string $key) {}

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-authoring.'.$this->key];
    }
}
