<?php

namespace FixtureGood\Support\Live;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Rule live.broadcast-now control: a live prop notice that broadcasts now. */
final class BuildChanged implements ShouldBroadcastNow
{
    public function __construct(public string $key) {}

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-authoring.'.$this->key];
    }
}
