<?php

namespace BodyGood\Http\Controllers;

use BodyGood\Actions\SavePost;
use BodyGood\Queries\FindPosts;
use BodyGood\Services\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PostController
{
    public function __construct(private SavePost $save) {}

    public function store(Request $request): mixed
    {
        $this->save->handle($request->validated());
        $this->save->handle([]);

        return $this->redirectResponse();
    }

    public function index(FindPosts $find): mixed
    {
        $find->handle();

        return Inertia::render('Posts/Index');
    }

    public function update(Document $document): void
    {
        $document->save();
        $document->validate();
        $document->expectsJson();
    }

    private function redirectResponse(): mixed
    {
        return redirect()->route('posts.index')->with('status', 'Saved');
    }

    protected function jsonResponse(): mixed
    {
        return response()->json(['ok' => true]);
    }

    protected function pageResponse(): mixed
    {
        return Inertia::render('Posts/Index', ['posts' => []]);
    }
}
