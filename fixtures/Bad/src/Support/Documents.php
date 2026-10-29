<?php

declare(strict_types=1);

namespace FixtureBad\Support;

use FixtureBad\Models\Document;
use Illuminate\Support\Facades\DB;

// Seeded: builder writes on a versioned model that skip the version bump, so a stale-write guard silently passes.
final class Documents
{
    public function archive(int $id): void
    {
        Document::query()->whereKey($id)->update(['archived' => true]);
        Document::upsert([['id' => $id, 'title' => 'x']], ['id'], ['title']);
        Document::where('id', $id)->increment('views');
        DB::table('documents')->where('id', $id)->update(['title' => 'y']);
        Document::findOrFail($id)->update(['title' => 'z']);
    }
}
