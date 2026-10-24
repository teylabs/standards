<?php

namespace BodyBad\Http\Controllers;

use BodyBad\Actions\SavePost;
use BodyBad\Models\Post;
use BodyBad\Queries\FindPosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB as Database;
use Illuminate\Support\Facades\Validator as Check;

class PostController
{
    public function __construct(private SavePost $save) {}

    public function store(Request $input, Post $post, FindPosts $find): void
    {
        Check::make([], []);
        validator([]);
        $input->validate([]);
        $this->validate($input, []);
        $input->wantsJson();
        $input->expectsJson();
        $input->ajax();
        $input->acceptsJson();
        Post::create([]);
        $post->save();
        Post::query()->where('id', 1)->update([]);
        Database::transaction(fn () => null);
        $this->save->handle();
        $find->handle();
    }

    private function persist(Request $request): void
    {
        $request->validate([]);
        Post::create([]);
    }

    protected function formatResponse(): mixed
    {
        return response()->json(Post::create([]));
    }
}
