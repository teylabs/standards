<?php

declare(strict_types=1);

namespace FixtureGood\Support;

use FixtureGood\Models\Document;
use Illuminate\Support\Facades\DB;

// Builder writes on a versioned model bump its version; an instance write runs the model events.
final class Documents
{
    public function archive(int $id): void
    {
        Document::query()->whereKey($id)->update(['archived' => true, 'version' => DB::raw('version + 1')]);
        Document::upsert([['id' => $id, 'title' => 'x', 'version' => 1]], ['id'], ['title', 'version']);
        Document::where('id', $id)->increment('version');
        DB::table('documents')->where('id', $id)->update(['title' => 'y', 'version' => DB::raw('version + 1')]);
        Document::findOrFail($id)->update(['title' => 'z']);
    }
}
